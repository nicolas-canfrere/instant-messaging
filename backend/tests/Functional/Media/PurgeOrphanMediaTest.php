<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\PurgeOrphanMediaCommand;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaNotFoundException;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Tests\Functional\DatabaseTestCase;

/**
 * Le ramassage des medias que plus rien ne reference.
 *
 * Deux sources d'orphelins, et la seconde est la plus importante : un
 * televersement abandonne (l'utilisateur choisit une image puis ferme
 * l'onglet), et un message supprime pour tous — `save()` detache ses medias,
 * qui n'ont alors plus aucun porteur. Sans cette purge, supprimer un message ne
 * supprimerait jamais ses octets.
 */
final class PurgeOrphanMediaTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/media/';

    private const string ABANDONNE_ID = '01JQZ0000000000000000080AA';
    private const string PRET_ORPHELIN_ID = '01JQZ0000000000000000081AA';
    private const string PRET_ATTACHE_ID = '01JQZ0000000000000000082AA';
    private const string RECENT_ID = '01JQZ0000000000000000083AA';
    private const string DETACHE_ID = '01JQZ0000000000000000084AA';

    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TD1';

    public function testAnAbandonedUploadIsRemovedRowAndBytesAlike(): void
    {
        $this->login('alice');

        $mediaId = $this->seedMedia(self::ABANDONNE_ID, MediaStatus::Processing, hoursAgo: 30);

        $this->purge();

        $this->assertPurged($mediaId);
    }

    public function testAProcessedButNeverAttachedMediaIsRemovedToo(): void
    {
        $this->login('alice');

        $mediaId = $this->seedMedia(self::PRET_ORPHELIN_ID, MediaStatus::Ready, hoursAgo: 30);

        $this->purge();

        $this->assertPurged($mediaId);
    }

    public function testAMediaCarriedByAMessageIsKeptHoweverOldItIs(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $mediaId = $this->seedMedia(self::PRET_ATTACHE_ID, MediaStatus::Ready, hoursAgo: 30);
        $this->sendWithMedia($conversationId, self::CLIENT_ID, $mediaId);

        $this->purge();

        // L'age ne fait rien a l'affaire : un media affiche dans un fil n'est
        // pas un orphelin, meme vieux de six mois.
        $this->assertStillThere($mediaId);
    }

    public function testARecentUploadIsKeptBecauseItMayStillBeInFlight(): void
    {
        $this->login('alice');

        $mediaId = $this->seedMedia(self::RECENT_ID, MediaStatus::Pending, hoursAgo: 2);

        $this->purge();

        // Deux heures, ce n'est pas un abandon : le televersement peut etre en
        // cours. Purger sur le seul critere « non attache » effacerait les
        // octets d'un utilisateur en train de les envoyer.
        $this->assertStillThere($mediaId);
    }

    /**
     * Le cas qui ferme la boucle ouverte par la tache 6 : supprimer un message
     * pour tous detache ses medias, mais ne touche pas a leurs octets. Sans
     * cette purge, « supprime pour tous » laisserait l'image accessible a qui
     * detient une URL signee, et sur le disque pour toujours.
     */
    public function testAMediaLeftBehindByADeletedMessageIsRemoved(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $mediaId = $this->seedMedia(self::DETACHE_ID, MediaStatus::Ready, hoursAgo: 30);
        $this->sendWithMedia($conversationId, self::CLIENT_ID, $mediaId);

        /** @var array{id: string} $created */
        $created = $this->json();

        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/messages/%s', $conversationId, $created['id']),
        );
        self::assertResponseStatusCodeSame(204);

        $this->purge();

        $this->assertPurged($mediaId);
    }

    private function purge(): void
    {
        /** @var CommandDispatcherInterface $commands */
        $commands = static::getContainer()->get(CommandDispatcherInterface::class);
        $commands->dispatch(new PurgeOrphanMediaCommand());
    }

    private function assertPurged(MediaId $mediaId): void
    {
        $storage = $this->storage();

        // Les OCTETS d'abord : c'est ce qui compte. Une ligne effacee dont les
        // octets restent serait pire que rien — ils deviendraient invisibles,
        // donc impurgeables.
        self::assertNull(
            $storage->downloadToTemporaryFile(StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg)),
            'Les octets de l\'original doivent avoir disparu du bucket.',
        );
        self::assertNull(
            $storage->downloadToTemporaryFile(StorageKey::forThumbnail($mediaId)),
            'Les octets de la miniature doivent avoir disparu du bucket.',
        );

        $this->expectException(MediaNotFoundException::class);
        $this->repository()->ofId($mediaId);
    }

    private function assertStillThere(MediaId $mediaId): void
    {
        self::assertSame($mediaId->toString(), $this->repository()->ofId($mediaId)->id()->toString());
    }

    /**
     * Sème un media a l'age voulu, octets compris. L'age se pose directement en
     * base : `MockClock` est gelee sur l'instant du boot, faire vieillir une
     * ligne de trente heures par l'horloge n'est donc pas possible.
     */
    private function seedMedia(string $mediaIdString, MediaStatus $status, int $hoursAgo): MediaId
    {
        $mediaId = MediaId::fromString($mediaIdString);
        $key = StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg);
        $createdAt = new \DateTimeImmutable(sprintf('-%d hours', $hoursAgo));

        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($this->userId('alice')),
            $key,
            MediaMimeType::Jpeg,
            2_000,
            $createdAt,
        );

        if (MediaStatus::Pending !== $status) {
            $media->markUploaded($createdAt);
        }

        $this->repository()->add($media);

        if (MediaStatus::Ready === $status) {
            $thumbnailKey = StorageKey::forThumbnail($mediaId);
            $media->markReady(MediaMimeType::Jpeg, 1_600, 900, 2_000, $thumbnailKey, $createdAt);
            $this->repository()->save($media);
            $this->storage()->put($thumbnailKey, self::FIXTURES . 'valide.jpg', MediaMimeType::Jpeg);
        } elseif (MediaStatus::Processing === $status) {
            $this->repository()->save($media);
        }

        $this->storage()->put($key, self::FIXTURES . 'valide.jpg', MediaMimeType::Jpeg);

        return $mediaId;
    }

    private function sendWithMedia(string $conversationId, string $clientMessageId, MediaId $mediaId): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'media_ids' => [$mediaId->toString()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);
    }

    private function repository(): MediaRepositoryInterface
    {
        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        return $repository;
    }

    private function storage(): MediaStorageInterface
    {
        /** @var MediaStorageInterface $storage */
        $storage = static::getContainer()->get(MediaStorageInterface::class);

        return $storage;
    }
}
