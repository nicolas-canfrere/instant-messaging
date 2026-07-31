<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\MediaCommandSpy;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

/**
 * `DatabaseTestCase::login()` re-authentifie le MEME client : les scenarios a
 * deux utilisateurs (Alice presigne, Bob tente de confirmer) s'ecrivent donc
 * par deux appels sequentiels a `login()`, pas par deux `KernelBrowser`
 * distincts — c'est le motif deja suivi par `LeaveGroupTest`.
 */
final class UploadFlowTest extends DatabaseTestCase
{
    use InteractsWithMessenger;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/Media/';

    public function testPresigningReturnsAUsableUrlAndLeavesTheMediaPending(): void
    {
        $this->login('alice');

        /** @var array{media_id: string, upload_url: string, expires_at: string} $ticket */
        $ticket = $this->presignRaw('photo.jpg', 'image/jpeg', 2_048);
        self::assertResponseStatusCodeSame(201);

        // La signature couvre la methode, la cle ET le Content-Type. L'URL
        // porte donc les parametres AWS SigV4, et vise l'origine unique — pas
        // `minio:9000`, que le navigateur ne sait pas joindre (spec §5.1).
        //
        // Ceci ne verifie que la FORME de l'URL : qu'elle soit reellement
        // utilisable (un vrai PUT accepte, une signature expiree refusee) est
        // couvert par PresignedUploadSignatureTest, contre le vrai minio-test.
        self::assertStringContainsString('X-Amz-Signature=', $ticket['upload_url']);
        self::assertStringStartsWith('http://localhost:8080/messaging-media/media/', $ticket['upload_url']);

        /** @var MediaRepositoryInterface $repository */
        $repository = self::getContainer()->get(MediaRepositoryInterface::class);
        self::assertSame(MediaStatus::Pending, $repository->ofId(MediaId::fromString($ticket['media_id']))->status());
    }

    public function testATypeOutsideTheAllowlistIsRefusedWithAViolation(): void
    {
        $this->login('alice');

        // `image/svg+xml` reste hors de l'allowlist (spec §9) : un SVG peut
        // porter du script, l'accepter reviendrait a servir du contenu
        // actif en same-origin.
        $this->presignRaw('icone.svg', 'image/svg+xml', 2_048);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        /** @var array{type: string, violations: list<array{field: string, message: string}>} $body */
        $body = $this->json();
        self::assertSame('/problems/validation-failed', $body['type']);
        // Le client doit savoir QUEL champ corriger.
        self::assertSame('content_type', $body['violations'][0]['field']);
    }

    /**
     * Regression : un nom compose UNIQUEMENT d'espaces est truthy pour PHP,
     * donc invisible a `NotBlank` sans `normalizer: 'trim'` — et `Regex`
     * l'autorise aussi puisque l'espace est un caractere permis par
     * `OriginalFilename::PATTERN`. Sans le normalizer, la requete atteignait
     * le controleur, ou `OriginalFilename::fromString()` levait une exception
     * que le listener ne traduit pas en 422 : un 500 sur une simple entree
     * malformee, contraire a la regle absolue de l'API.
     */
    public function testAWhitespaceOnlyFilenameIsRefusedWithAViolation(): void
    {
        $this->login('alice');

        $this->presignRaw('   ', 'image/jpeg', 2_048);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        /** @var array{type: string, violations: list<array{field: string, message: string}>} $body */
        $body = $this->json();
        self::assertSame('/problems/validation-failed', $body['type']);
        self::assertSame('filename', $body['violations'][0]['field']);
    }

    public function testASizeAboveTheCapIsRefusedBeforeAnyTransfer(): void
    {
        $this->login('alice');

        $this->presignRaw('enorme.jpg', 'image/jpeg', 11 * 1024 * 1024);

        self::assertResponseStatusCodeSame(422);
    }

    public function testConfirmingTheUploadIsIdempotent(): void
    {
        $this->login('alice');
        $mediaId = $this->presign();

        $this->client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));
        self::assertResponseStatusCodeSame(204);

        // Rejouer ne doit produire NI erreur, NI second traitement.
        $this->client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));
        self::assertResponseStatusCodeSame(204);

        /** @var MediaRepositoryInterface $repository */
        $repository = self::getContainer()->get(MediaRepositoryInterface::class);
        self::assertSame(MediaStatus::Processing, $repository->ofId(MediaId::fromString($mediaId))->status());

        // Le statut final reste identique meme si le garde `$wasPending` est
        // supprime du handler : SEUL le compte de messages effectivement
        // routes vers le bus prouve que le second appel n'a pas redemande de
        // traitement.
        /** @var MediaCommandSpy $spy */
        $spy = self::getContainer()->get(MediaCommandSpy::class);
        $processCommands = array_filter(
            $spy->sent(),
            static fn(object $message): bool => $message instanceof ProcessMediaCommand,
        );
        self::assertCount(1, $processCommands, 'Rejouer la confirmation ne doit pas redemander de traitement.');
    }

    /**
     * Bob n'a AUCUN lien avec le media d'Alice : la reponse doit etre
     * indistinguable d'un media qui n'existe pas, sinon un 403 lui apprendrait
     * que ce mediaId existe (CLAUDE.md, oracle d'enumeration).
     */
    public function testConfirmingSomeoneElsesMediaIsNotFound(): void
    {
        $this->login('alice');
        $mediaId = $this->presign();

        $this->login('bob');
        $this->client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));

        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string} $body */
        $body = $this->json();
        self::assertSame('/problems/resource-not-found', $body['type']);

        // Fail-closed : la tentative de Bob n'a rien fait avancer.
        /** @var MediaRepositoryInterface $repository */
        $repository = self::getContainer()->get(MediaRepositoryInterface::class);
        self::assertSame(MediaStatus::Pending, $repository->ofId(MediaId::fromString($mediaId))->status());
    }

    public function testAnUnknownMediaIsNotFound(): void
    {
        $this->login('alice');

        $this->client->request('POST', '/api/media/01JQZ0000000000000000000ZZ/uploaded');

        self::assertResponseStatusCodeSame(404);
    }

    public function testZipIsRefusedAtPresign(): void
    {
        $this->login('alice');

        // 422 avec la violation nommant le champ, pas un 500 ni un message
        // ad hoc : c'est la discipline RFC 7807 du projet. `application/zip`
        // n'identifie pas un fichier zip, c'est le conteneur de .docx, .jar
        // et .apk (spec §9) : ecarte explicitement de l'allowlist.
        $this->presignRaw('archive.zip', 'application/zip', 1_024);

        self::assertResponseStatusCodeSame(422);
        /** @var array{violations: list<array{field: string, message: string}>} $body */
        $body = $this->json();
        self::assertSame('content_type', $body['violations'][0]['field']);
    }

    /**
     * Bout en bout : presignature, depot des octets, confirmation,
     * traitement par le handler, puis lecture via un message qui le porte.
     * `notes.md` est reconnu `text/plain` par le detecteur (les octets ne
     * distinguent pas les trois sous-types texte) : c'est le cas COUVERT par
     * `MediaMimeType::covers()`, pas une egalite stricte.
     */
    public function testAMarkdownDocumentFlowsThroughAsReadyForDownload(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        /** @var array{media_id: string} $ticket */
        $ticket = $this->presignRaw('notes.md', 'text/markdown', 24);
        self::assertResponseStatusCodeSame(201);

        $mediaId = MediaId::fromString($ticket['media_id']);

        // La stack de test ne leve aucun Caddy : aucune origine unique n'est
        // joignable depuis l'interieur du conteneur `backend-test` pour un
        // vrai PUT signe (cf. PresignedUploadSignatureTest). On depose donc
        // les octets directement, comme MediaProcessingTest.
        /** @var MediaStorageInterface $storage */
        $storage = self::getContainer()->get(MediaStorageInterface::class);
        $storage->put(
            StorageKey::forOriginal($mediaId, MediaMimeType::Markdown),
            self::FIXTURES . 'notes.md',
            MediaMimeType::Markdown,
        );

        $this->client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId->toString()));
        self::assertResponseStatusCodeSame(204);

        $this->transport('media')->process();

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => '01J9ZQ7X8K3M4N5P6Q7R8S9TC2', 'media_ids' => [$mediaId->toString()]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));
        self::assertResponseStatusCodeSame(200);

        /** @var array{items: list<array{media: list<array<string, mixed>>}>} $page */
        $page = $this->json();
        /** @var array{status: string, mime_type: string, width: int|null, height: int|null, url: string, thumbnail_url: string|null, filename: string} $media */
        $media = $page['items'][0]['media'][0];

        self::assertSame('ready', $media['status']);
        // La bibliotheque n'a jamais su distinguer les trois sous-types
        // texte : la mesure est TOUJOURS text/plain, meme pour un .md.
        self::assertSame('text/plain', $media['mime_type']);
        self::assertNull($media['width']);
        self::assertNull($media['height']);
        self::assertNull($media['thumbnail_url']);
        self::assertStringContainsString('attachment', urldecode($media['url']));
        self::assertSame('notes.md', $media['filename']);
    }

    private function presign(): string
    {
        /** @var array{media_id: string} $body */
        $body = $this->presignRaw('photo.jpg', 'image/jpeg', 2_048);

        return $body['media_id'];
    }

    /** @return array<string, mixed> */
    private function presignRaw(string $filename, string $contentType, int $size): array
    {
        $this->client->request(
            'POST',
            '/api/media',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['filename' => $filename, 'content_type' => $contentType, 'size' => $size],
                \JSON_THROW_ON_ERROR,
            ),
        );

        return $this->json();
    }
}
