<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
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
    ) {
    }

    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): string
    {
        // Le bucket doit exister AVANT que l'URL ne soit signee : c'est cette
        // URL que le navigateur ouvrira, sans repasser par le backend. La
        // stack de test ne lance aucun conteneur `mc` pour le creer d'avance
        // (compose.test.yaml) : c'est ce code qui en tient lieu.
        $this->ensureBucketExists();

        $command = $this->signerClient->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'ContentType' => $mimeType->value,
        ]);

        $url = (string) $this->signerClient
            ->createPresignedRequest($command, $now->modify(self::UPLOAD_TTL))
            ->getUri();

        return '' === $url ? throw new \RuntimeException('La signature a rendu une URL vide.') : $url;
    }

    public function presignDownload(StorageKey $key, \DateTimeImmutable $now): string
    {
        $command = $this->signerClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
        ]);

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

            if ('NoSuchKey' === $exception->getAwsErrorCode()) {
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
        $this->ensureBucketExists();

        $this->internalClient->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'SourceFile' => $localPath,
            'ContentType' => $mimeType->value,
        ]);
    }

    public function delete(StorageKey $key): void
    {
        try {
            $this->internalClient->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
            ]);
        } catch (AwsException $exception) {
            // Effacer est idempotent : un objet deja absent n'est pas un echec.
            // On le signale sans interrompre l'appelant — jamais la cle en clair.
            $this->logger->warning('Suppression du media {aws_error_code} sans effet', [
                'aws_error_code' => $exception->getAwsErrorCode() ?? 'unknown',
            ]);
        }
    }

    /**
     * Cree le bucket au premier acces s'il est absent. En production, le
     * `minio-create-bucket` de `compose.yaml` l'a deja fait : `headBucket`
     * repond alors immediatement sans jamais atteindre `createBucket`. La
     * stack de test n'a pas cet aide-la (deliberement, cf. plan T4) : c'est
     * ici, et nulle part ailleurs, que le bucket de test nait.
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
            // CREATE. Ce n'est pas un echec, jamais la cle en clair au-dela du
            // code d'erreur AWS.
            if ('BucketAlreadyOwnedByYou' !== $exception->getAwsErrorCode()) {
                throw $exception;
            }
        }
    }
}
