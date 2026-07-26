<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Tests\Functional\DatabaseTestCase;

final class SendMessageWithMediaTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string OTHER_CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';

    private const string ALICE_MEDIA_ID = '01JQZ0000000000000000001AA';
    private const string BOB_MEDIA_ID = '01JQZ0000000000000000002AA';

    public function testAMessageWithOnlyAnImageIsCreatedWithoutContent(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID]);

        self::assertResponseStatusCodeSame(201);

        /** @var string|null $content */
        $content = $this->connection->fetchOne('SELECT content FROM messages WHERE id = :id', [
            'id' => $this->json()['id'],
        ]);

        self::assertNull($content);
    }

    public function testAMessageWithNeitherTextNorMediaIsRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    /** Un media qui n'est pas a l'expediteur rend 404 : un 403 confirmerait son existence. */
    public function testAttachingSomeoneElsesMediaIsRefusedAsNotFound(): void
    {
        // Le login le temps de lire l'annuaire, puis on repart sur la session d'Alice.
        $this->login('bob');
        $bobId = $this->userId('bob');
        $this->createMedia(self::BOB_MEDIA_ID, $bobId);

        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::BOB_MEDIA_ID]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/resource-not-found', $this->json()['type']);
    }

    public function testAttachingTheSameMediaTwiceIsRejectedAsAConflict(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID]);
        self::assertResponseStatusCodeSame(201);

        $this->postWithMedia($conversationId, self::OTHER_CLIENT_ID, null, [self::ALICE_MEDIA_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/media-already-attached', $this->json()['type']);
    }

    /** Le convertisseur snake_case s'applique aussi aux chemins indexes. */
    public function testAMalformedMediaIdReportsItsIndexedFieldPath(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, ['pas-un-ulid']);

        self::assertResponseStatusCodeSame(422);

        /** @var array{violations: list<array{field: string, message: string}>} $problem */
        $problem = $this->json();

        self::assertSame('media_ids[0]', $problem['violations'][0]['field']);
    }

    /**
     * Cree un media appartenant a l'utilisateur donne. Le statut n'importe
     * pas au contrat d'attachement (`DbalMediaOwnership`) : seule
     * l'appartenance et l'absence de rattachement prealable comptent.
     */
    private function createMedia(string $mediaIdString, string $ownerId): void
    {
        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        $mediaId = MediaId::fromString($mediaIdString);
        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($ownerId),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);
    }

    /** @param list<string> $mediaIds */
    private function postWithMedia(string $conversationId, string $clientMessageId, ?string $content, array $mediaIds): void
    {
        $body = ['client_message_id' => $clientMessageId, 'media_ids' => $mediaIds];

        if (null !== $content) {
            $body['content'] = $content;
        }

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
