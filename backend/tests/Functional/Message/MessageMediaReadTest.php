<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\QueryRecorder\RecordedQueries;

/**
 * La lecture d'un message porte ses medias, et une URL n'est signee que pour un
 * media `ready` : signer un `processing` donnerait acces a des octets dont
 * personne n'a encore verifie qu'ils sont servables (spec §4.3).
 */
final class MessageMediaReadTest extends DatabaseTestCase
{
    private const string READY_MEDIA_ID = '01JQZ0000000000000000010AA';
    private const string PROCESSING_MEDIA_ID = '01JQZ0000000000000000011AA';
    private const string REJECTED_MEDIA_ID = '01JQZ0000000000000000012AA';
    private const string READY_DOCUMENT_MEDIA_ID = '01JQZ0000000000000000013AA';

    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TB1';

    public function testAReadyMediaIsServedWithSignedUrlsAndItsMeasures(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::READY_MEDIA_ID, $this->userId('alice'), MediaStatus::Ready);

        $this->sendWithMedia($conversationId, self::CLIENT_ID, self::READY_MEDIA_ID);

        $media = $this->firstMediaOfLatestMessage($conversationId);

        self::assertSame(self::READY_MEDIA_ID, $media['id']);
        self::assertSame('ready', $media['status']);
        self::assertSame('image/jpeg', $media['mime_type']);
        self::assertSame(800, $media['width']);
        self::assertSame(600, $media['height']);

        // La signature, et pas seulement la presence d'une URL : une URL nue
        // vers le bucket serait refusee par MinIO, le test passerait quand meme.
        self::assertIsString($media['url']);
        self::assertStringContainsString('X-Amz-Signature=', $media['url']);
        self::assertIsString($media['thumbnail_url']);
        self::assertStringContainsString('X-Amz-Signature=', $media['thumbnail_url']);
        self::assertStringContainsString('-thumb.jpg', $media['thumbnail_url']);
    }

    /**
     * La miniature ne peut plus servir de temoin de « servable » : un
     * document pret n'en a jamais. C'est le statut qui doit trancher.
     */
    public function testAReadyDocumentIsServedWithoutAThumbnail(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createDocumentMedia(self::READY_DOCUMENT_MEDIA_ID, $this->userId('alice'));

        $this->sendWithMedia($conversationId, self::CLIENT_ID, self::READY_DOCUMENT_MEDIA_ID);

        $media = $this->firstMediaOfLatestMessage($conversationId);

        self::assertSame('ready', $media['status']);
        self::assertIsString($media['url']);
        self::assertStringContainsString('X-Amz-Signature=', $media['url']);
        self::assertNull($media['thumbnail_url']);
        self::assertNull($media['width']);
        self::assertNull($media['height']);
    }

    public function testAMediaStillProcessingCarriesNoUrlAtAll(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::PROCESSING_MEDIA_ID, $this->userId('alice'), MediaStatus::Processing);

        $this->sendWithMedia($conversationId, self::CLIENT_ID, self::PROCESSING_MEDIA_ID);

        $media = $this->firstMediaOfLatestMessage($conversationId);

        self::assertSame('processing', $media['status']);
        self::assertNull($media['url']);
        self::assertNull($media['thumbnail_url']);
        // Rien n'a encore ete mesure : le front n'a pas de quoi reserver la place.
        self::assertNull($media['width']);
        self::assertNull($media['height']);
    }

    public function testARejectedMediaIsAnnouncedWithoutAnyUrl(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::REJECTED_MEDIA_ID, $this->userId('alice'), MediaStatus::Rejected);

        $this->sendWithMedia($conversationId, self::CLIENT_ID, self::REJECTED_MEDIA_ID);

        $media = $this->firstMediaOfLatestMessage($conversationId);

        // Le rejet est un etat rendu au client, pas un media escamote : sans
        // cela, le front laisserait un emplacement « en cours… » pour toujours.
        self::assertSame('rejected', $media['status']);
        self::assertNull($media['url']);
        self::assertNull($media['thumbnail_url']);
    }

    /**
     * Le seul test qui protege du N+1 : la solution evidente — une requete de
     * medias par message — passerait tous les autres.
     */
    public function testAWholePageOfMessagesCostsASingleMediaLookup(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $aliceId = $this->userId('alice');

        for ($index = 0; $index < 30; ++$index) {
            $clientMessageId = sprintf('01J9ZQ7X8K3M4N5P6Q7R8S9%03d', $index);

            // Un message sur six porte une image : la page melange donc des
            // messages avec et sans medias, comme un vrai fil.
            if (0 === $index % 6) {
                $mediaId = sprintf('01JQZ00000000000000000%04d', $index);
                $this->createMedia($mediaId, $aliceId, MediaStatus::Ready);
                $this->sendWithMedia($conversationId, $clientMessageId, $mediaId);

                continue;
            }

            $this->send($conversationId, $clientMessageId, sprintf('message %d', $index));
        }

        /** @var RecordedQueries $recorded */
        $recorded = static::getContainer()->get(RecordedQueries::class);

        // Efface juste avant le geste observe : tout le semis ci-dessus a lui
        // aussi touche `message_media` et `media_objects`.
        $recorded->clear();

        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));

        self::assertResponseIsSuccessful();

        /** @var array{items: list<array{media: list<array{id: string}>}>} $page */
        $page = $this->json();

        self::assertCount(30, $page['items']);
        self::assertSame(5, $this->countOfItemsCarryingMedia($page['items']));

        self::assertSame(1, $recorded->countMatching('FROM message_media'), 'Une seule requete de liaisons pour toute la page.');
        self::assertSame(1, $recorded->countMatching('FROM media_objects'), 'Une seule requete de medias pour toute la page.');
    }

    /**
     * @param list<array{media: list<array{id: string}>}> $items
     */
    private function countOfItemsCarryingMedia(array $items): int
    {
        return count(array_filter($items, static fn(array $item): bool => [] !== $item['media']));
    }

    /**
     * Sème un media dans l'etat demande, sans passer par le flux d'upload : ce
     * test porte sur la lecture, pas sur le chemin qui a mene a cet etat.
     */
    private function createMedia(string $mediaIdString, string $ownerId, MediaStatus $status): void
    {
        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        $mediaId = MediaId::fromString($mediaIdString);
        $now = new \DateTimeImmutable('2026-07-26T09:00:00+00:00');

        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($ownerId),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            OriginalFilename::fromString('photo.jpg'),
            MediaMimeType::Jpeg,
            2_000,
            $now,
        );
        $repository->add($media);

        if (MediaStatus::Pending === $status) {
            return;
        }

        $media->markUploaded($now);

        match ($status) {
            MediaStatus::Ready => $media->markImageReady(
                MediaMimeType::Jpeg,
                800,
                600,
                2_000,
                StorageKey::forThumbnail($mediaId),
                $now,
            ),
            MediaStatus::Rejected => $media->markRejected(MediaRejectionReason::TooLarge, $now),
            default => null,
        };

        $repository->save($media);
    }

    /**
     * Un document pret, seme directement en base : ni mesure ni miniature,
     * conformement au CHECK `media_ready_is_measured`.
     */
    private function createDocumentMedia(string $mediaIdString, string $ownerId): void
    {
        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        $mediaId = MediaId::fromString($mediaIdString);
        $now = new \DateTimeImmutable('2026-07-26T09:00:00+00:00');

        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($ownerId),
            StorageKey::forOriginal($mediaId, MediaMimeType::Text),
            OriginalFilename::fromString('notes.txt'),
            MediaMimeType::Text,
            2_000,
            $now,
        );
        $repository->add($media);

        $media->markUploaded($now);
        $media->markDocumentReady(MediaMimeType::Text, 2_000, $now);
        $repository->save($media);
    }

    private function sendWithMedia(string $conversationId, string $clientMessageId, string $mediaId): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'media_ids' => [$mediaId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{id: string, status: string, mime_type: string|null, width: int|null, height: int|null, url: string|null, thumbnail_url: string|null}
     */
    private function firstMediaOfLatestMessage(string $conversationId): array
    {
        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));

        self::assertResponseIsSuccessful();

        /** @var array{items: list<array{media: list<array{id: string, status: string, mime_type: string|null, width: int|null, height: int|null, url: string|null, thumbnail_url: string|null}>}>} $page */
        $page = $this->json();

        // La page va du plus recent au plus ancien : le message qu'on vient
        // d'envoyer est le premier.
        return $page['items'][0]['media'][0];
    }
}
