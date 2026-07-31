<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\MediaStorageInterface;
use App\Media\Application\PresignedUpload;
use App\Media\Domain\MediaDisposition;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

/**
 * Deux clients S3, et c'est delibere (spec §5.1).
 *
 * Une URL pre-signee signe le `Host`. Le client INTERNE signe ses propres
 * requetes avec l'hote qu'il appelle vraiment (`minio:9000`) : aucun probleme.
 * Le client SIGNEUR, lui, doit signer avec l'hote que le NAVIGATEUR appellera
 * (`localhost:8080`, l'origine unique), sinon `SignatureDoesNotMatch`.
 *
 * Caddy proxifie `/messaging-media/*` vers MinIO en preservant le Host, et le
 * nom du bucket sert de prefixe de chemin — ce que `use_path_style_endpoint`
 * donne deja. Aucune reecriture d'URL, donc aucune signature cassee.
 */
final readonly class S3MediaStorage implements MediaStorageInterface
{
    private const string UPLOAD_TTL = '+5 minutes';
    private const string DOWNLOAD_TTL = '+15 minutes';

    public function __construct(
        private S3Client $internalClient,
        private S3Client $signerClient,
        private string $bucket,
        private LoggerInterface $logger,
        // `false` en production : `minio-create-bucket` (compose.yaml) a deja
        // fait le travail, et un HEAD systematique sur le chemin de signature
        // ajouterait un aller-retour bloquant a CHAQUE presign — pire, un vrai
        // S3 a politique restrictive repond souvent 403 (pas 404) a un HEAD
        // sur un bucket qu'on ne peut pas lister, ce qui ferait 500 la ou rien
        // n'est casse. `true` seulement en test (services_test.yaml) : la
        // stack de test ne lance aucun conteneur `mc` pour pre-creer le bucket.
        private bool $createBucketIfMissing = false,
    ) {
    }

    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): PresignedUpload
    {
        if ($this->createBucketIfMissing) {
            $this->ensureBucketExists();
        }

        $command = $this->signerClient->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'ContentType' => $mimeType->value,
        ]);

        // L'expiration REELLE de la signature et celle annoncee au client
        // doivent venir de la MEME valeur : `$expiresAt` sert aux deux, pour
        // qu'aucune copie ne puisse diverger de l'autre.
        $expiresAt = $now->modify(self::UPLOAD_TTL);

        $url = (string) $this->signerClient
            ->createPresignedRequest($command, $expiresAt)
            ->getUri();

        if ('' === $url) {
            throw new \RuntimeException('La signature a rendu une URL vide.');
        }

        return new PresignedUpload($url, $expiresAt);
    }

    public function presignDownload(
        StorageKey $key,
        MediaDisposition $disposition,
        ?OriginalFilename $filename,
        \DateTimeImmutable $now,
    ): string {
        $parameters = ['Bucket' => $this->bucket, 'Key' => $key->toString()];

        if (null !== $filename) {
            $parameters['ResponseContentDisposition'] = $this->contentDisposition($disposition, $filename);
        }

        if (MediaDisposition::Attachment === $disposition) {
            // Le navigateur ne doit rien deduire du type : il telecharge, point.
            $parameters['ResponseContentType'] = 'application/octet-stream';
        }

        $command = $this->signerClient->getCommand('GetObject', $parameters);

        $url = (string) $this->signerClient
            ->createPresignedRequest($command, $now->modify(self::DOWNLOAD_TTL))
            ->getUri();

        return '' === $url ? throw new \RuntimeException('La signature a rendu une URL vide.') : $url;
    }

    public function downloadToTemporaryFile(StorageKey $key): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'media-');

        if (false === $path) {
            throw new \RuntimeException('Impossible de creer un fichier temporaire.');
        }

        try {
            // `SaveAs` fait ecrire le SDK directement dans le fichier : les
            // octets ne transitent jamais par une variable PHP.
            $this->internalClient->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
                'SaveAs' => $path,
            ]);
        } catch (AwsException $exception) {
            @unlink($path);

            if ($this->isMissingObject($exception)) {
                return null;
            }

            throw $exception;
        }

        // `$path` vient de `tempnam()`, deja verifie non-vide plus haut : la
        // seule issue possible ici est un succes.
        return $path;
    }

    public function put(StorageKey $key, string $localPath, MediaMimeType $mimeType): void
    {
        if ($this->createBucketIfMissing) {
            $this->ensureBucketExists();
        }

        $this->internalClient->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'SourceFile' => $localPath,
            'ContentType' => $mimeType->value,
        ]);
    }

    public function delete(StorageKey $key, MediaId $mediaId): void
    {
        try {
            $this->internalClient->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
            ]);
        } catch (AwsException $exception) {
            // Absence genuine : effacer est idempotent, rien a signaler. Tout
            // AUTRE echec (identifiants, reseau, bucket manquant, 5xx) doit
            // remonter — le taire aurait fait passer l'echec d'une purge pour
            // un succes (Task 11), en laissant l'objet en place.
            if ($this->isMissingObject($exception)) {
                return;
            }

            $this->logger->error('Suppression du media {media_id} en echec : {aws_error_code}', [
                'media_id' => $mediaId->toString(),
                'aws_error_code' => $exception->getAwsErrorCode() ?? 'unknown',
            ]);

            throw $exception;
        }
    }

    /**
     * Cree le bucket au premier acces s'il est absent. En production, le
     * `minio-create-bucket` de `compose.yaml` l'a deja fait, et
     * `$createBucketIfMissing` vaut `false` : cette methode n'est alors jamais
     * appelee. La stack de test n'a pas cette aide-la (deliberement, cf. plan
     * T4) : c'est ici, et nulle part ailleurs, que le bucket de test nait.
     */
    private function ensureBucketExists(): void
    {
        try {
            $this->internalClient->headBucket(['Bucket' => $this->bucket]);

            return;
        } catch (AwsException $exception) {
            if (404 !== $exception->getStatusCode()) {
                throw $exception;
            }
        }

        try {
            $this->internalClient->createBucket(['Bucket' => $this->bucket]);

            $this->logger->notice('Bucket media cree car absent au demarrage');
        } catch (AwsException $exception) {
            // Concurrence : un autre process a pu le creer entre le HEAD et le
            // CREATE. MinIO et S3 ne repondent pas forcement le meme code dans
            // ce cas : ni l'un ni l'autre n'est un echec, jamais la cle en
            // clair au-dela du code d'erreur AWS.
            if (!\in_array($exception->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
                throw $exception;
            }
        }
    }

    /**
     * RFC 6266 : `filename` en ASCII pour les clients anciens, `filename*` en
     * UTF-8 pour la verite. Les deux, parce qu'un client qui ne comprend pas
     * `filename*` doit quand meme obtenir quelque chose de lisible.
     *
     * Le VO OriginalFilename a deja interdit les caracteres de controle ; il
     * reste a neutraliser le guillemet et l'antislash, qui fermeraient la
     * chaine citee.
     */
    private function contentDisposition(MediaDisposition $disposition, OriginalFilename $filename): string
    {
        $name = $filename->toString();
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'fichier';
        $ascii = str_replace(['\\', '"'], '_', $ascii);

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition->value,
            $ascii,
            rawurlencode($name),
        );
    }

    /** Absence genuine de l'objet ou du bucket, quelle que soit la forme exacte de la reponse AWS/MinIO. */
    private function isMissingObject(AwsException $exception): bool
    {
        return \in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound', 'NoSuchBucket'], true)
            || 404 === $exception->getStatusCode();
    }
}
