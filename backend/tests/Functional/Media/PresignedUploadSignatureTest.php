<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Domain\MediaDisposition;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Media\Infrastructure\Storage\S3MediaStorage;
use App\Shared\Domain\Identifier\MediaId;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `UploadFlowTest` ne verifie que la FORME de l'URL signee (presence de
 * `X-Amz-Signature=`, prefixe attendu). Rien n'y prouve qu'un vrai navigateur
 * pourrait s'en servir, ni qu'une signature expiree est vraiment refusee — les
 * deux comportements dont depend tout le reste de la tranche, et qui n'etaient
 * jusqu'ici verifies qu'a la main (cf. rapport tache 4, etapes 11 et 12).
 *
 * Ce test construit son propre `S3MediaStorage`, signeur COMPRIS, sur
 * l'endpoint INTERNE (`minio-test:9000`) : la stack de test ne leve aucun
 * Caddy, donc aucune origine unique a viser depuis l'interieur du conteneur
 * `backend-test`. C'est le seul endroit du projet ou signeur et client
 * interne coincident — en production ils different toujours (spec §5.1),
 * et c'est deja verifie par la forme de l'URL dans `UploadFlowTest`.
 */
final class PresignedUploadSignatureTest extends TestCase
{
    private S3Client $client;
    private S3MediaStorage $storage;

    protected function setUp(): void
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => (string) getenv('MEDIA_S3_INTERNAL_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => (string) getenv('MEDIA_S3_KEY'),
                'secret' => (string) getenv('MEDIA_S3_SECRET'),
            ],
        ]);

        $this->storage = new S3MediaStorage($this->client, $this->client, (string) getenv('MEDIA_BUCKET'), new NullLogger(), true);
    }

    public function testAFreshlySignedUrlAcceptsARealPutAndTheBytesComeBackOnGet(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString('01JQZ0000000000000000000AA'), MediaMimeType::Jpeg);
        $presigned = $this->storage->presignUpload($key, MediaMimeType::Jpeg, new \DateTimeImmutable());

        $http = new HttpClient(['http_errors' => false]);
        $putResponse = $http->put($presigned->url, [
            'headers' => ['Content-Type' => 'image/jpeg'],
            'body' => 'contenu-de-test',
        ]);

        self::assertSame(200, $putResponse->getStatusCode());

        $downloadUrl = $this->storage->presignDownload($key, MediaDisposition::Inline, null, new \DateTimeImmutable());
        $getResponse = $http->get($downloadUrl);

        self::assertSame(200, $getResponse->getStatusCode());
        self::assertSame('contenu-de-test', (string) $getResponse->getBody());
    }

    /**
     * Le nom d'origine doit atterrir dans l'en-tete Content-Disposition de
     * l'URL signee — c'est la seule destination de OriginalFilename (Tache 1).
     * Verifie a la fois la forme du parametre de signature ET le comportement
     * reel du serveur : MinIO doit vraiment renvoyer l'en-tete demande.
     */
    public function testTheDownloadUrlCarriesTheOriginalFilename(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString('01JQZ0000000000000000000AC'), MediaMimeType::Jpeg);
        $presigned = $this->storage->presignUpload($key, MediaMimeType::Jpeg, new \DateTimeImmutable());

        $http = new HttpClient(['http_errors' => false]);
        $http->put($presigned->url, [
            'headers' => ['Content-Type' => 'image/jpeg'],
            'body' => 'contenu-de-test',
        ]);

        $downloadUrl = $this->storage->presignDownload(
            $key,
            MediaDisposition::Inline,
            OriginalFilename::fromString('rapport été.pdf'),
            new \DateTimeImmutable(),
        );

        self::assertStringContainsString('response-content-disposition=', $downloadUrl);
        self::assertStringContainsString('inline', urldecode($downloadUrl));
        self::assertStringContainsString('filename%2A%3DUTF-8', $downloadUrl);

        $getResponse = $http->get($downloadUrl);

        self::assertSame(200, $getResponse->getStatusCode());
        self::assertStringContainsString(
            'inline; filename="rapport __t__.pdf"',
            $getResponse->getHeaderLine('Content-Disposition'),
        );
    }

    /**
     * Le VO OriginalFilename laisse passer `"` et `\` : ce sont des
     * caracteres imprimables, pas des caracteres de controle. Seul
     * `S3MediaStorage::contentDisposition()` les neutralise (`str_replace`)
     * avant de les mettre dans la valeur citee de l'en-tete — sans quoi ils
     * fermeraient la chaine et casseraient le Content-Disposition. Ce test
     * couvre precisement ce mecanisme, au-dela de ce que le VO bloque deja.
     */
    public function testTheAsciiFilenameNeutralizesQuotesAndBackslashes(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString('01JQZ0000000000000000000AD'), MediaMimeType::Jpeg);
        $presigned = $this->storage->presignUpload($key, MediaMimeType::Jpeg, new \DateTimeImmutable());

        $http = new HttpClient(['http_errors' => false]);
        $http->put($presigned->url, [
            'headers' => ['Content-Type' => 'image/jpeg'],
            'body' => 'contenu-de-test',
        ]);

        $downloadUrl = $this->storage->presignDownload(
            $key,
            MediaDisposition::Inline,
            OriginalFilename::fromString('rapport "final"\\copy.pdf'),
            new \DateTimeImmutable(),
        );

        $getResponse = $http->get($downloadUrl);

        self::assertSame(200, $getResponse->getStatusCode());
        self::assertStringContainsString(
            'inline; filename="rapport _final__copy.pdf"',
            $getResponse->getHeaderLine('Content-Disposition'),
        );
        // Ni guillemet ni antislash ne doivent survivre dans la valeur citee :
        // l'un ou l'autre fermerait prematurement la chaine.
        self::assertStringNotContainsString('final"copy', $getResponse->getHeaderLine('Content-Disposition'));
    }

    /**
     * Reproduit precisement le trou trouve en revue : `presignDownload()`
     * n'emettait `ResponseContentDisposition` QUE si un nom etait fourni,
     * si bien qu'un `Attachment` sans nom (le cas d'une miniature, ou tout
     * futur appelant qui n'a pas de nom sous la main) ne portait AUCUN
     * Content-Disposition — ni `attachment`, ni rien. La disposition est
     * une decision de securite ; elle ne doit jamais dependre d'un
     * parametre sans rapport comme le nom du fichier.
     */
    public function testAnAttachmentPresignWithNoFilenameStillCarriesTheAttachmentDisposition(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString('01JQZ0000000000000000000AE'), MediaMimeType::Jpeg);
        $presigned = $this->storage->presignUpload($key, MediaMimeType::Jpeg, new \DateTimeImmutable());

        $http = new HttpClient(['http_errors' => false]);
        $http->put($presigned->url, [
            'headers' => ['Content-Type' => 'image/jpeg'],
            'body' => 'contenu-de-test',
        ]);

        $downloadUrl = $this->storage->presignDownload($key, MediaDisposition::Attachment, null, new \DateTimeImmutable());

        $getResponse = $http->get($downloadUrl);

        self::assertSame(200, $getResponse->getStatusCode());
        self::assertSame('attachment', $getResponse->getHeaderLine('Content-Disposition'));
    }

    /**
     * `X-Amz-Expires` est une DUREE relative a l'instant reel de signature —
     * pas un instant absolu qu'on pourrait antidater via le `$now` passe a
     * `presignUpload()`. Le SDK refuse meme une duree negative avant d'avoir
     * rien signe (400 `AuthorizationQueryParametersError`), donc simuler « il
     * y a 10 minutes » ne produit PAS le 403 « Request has expired » qu'on
     * cherche a observer.
     *
     * La seule facon de provoquer une VRAIE expiration est de laisser le
     * temps reel s'ecouler au-dela d'une signature courte — exactement ce que
     * l'etape 12 du rapport de la tache 4 a fait a la main (TTL a 30 s,
     * attente, PUT refuse), ici automatise avec une signature de 2 s. On
     * signe directement via le `S3Client`, plutot que via
     * `S3MediaStorage::presignUpload()` dont le TTL de 5 minutes rendrait ce
     * test trop lent — le mecanisme de signature exerce est rigoureusement le
     * meme (`createPresignedRequest` sur le meme client).
     */
    public function testASignatureThatHasReallyExpiredIsRefused(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString('01JQZ0000000000000000000AB'), MediaMimeType::Jpeg);

        $command = $this->client->getCommand('PutObject', [
            'Bucket' => (string) getenv('MEDIA_BUCKET'),
            'Key' => $key->toString(),
            'ContentType' => MediaMimeType::Jpeg->value,
        ]);
        $url = (string) $this->client
            ->createPresignedRequest($command, (new \DateTimeImmutable())->modify('+2 seconds'))
            ->getUri();

        sleep(3);

        $http = new HttpClient(['http_errors' => false]);
        $response = $http->put($url, [
            'headers' => ['Content-Type' => 'image/jpeg'],
            'body' => 'trop-tard',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Request has expired', (string) $response->getBody());
    }
}
