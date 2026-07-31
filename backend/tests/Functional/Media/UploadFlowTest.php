<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Shared\Domain\Identifier\MediaId;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\MediaCommandSpy;

/**
 * `DatabaseTestCase::login()` re-authentifie le MEME client : les scenarios a
 * deux utilisateurs (Alice presigne, Bob tente de confirmer) s'ecrivent donc
 * par deux appels sequentiels a `login()`, pas par deux `KernelBrowser`
 * distincts — c'est le motif deja suivi par `LeaveGroupTest`.
 */
final class UploadFlowTest extends DatabaseTestCase
{
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

        $this->presignRaw('contrat.pdf', 'application/pdf', 2_048);

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
