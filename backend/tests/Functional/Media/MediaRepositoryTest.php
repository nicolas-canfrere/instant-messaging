<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaRepositoryTest extends KernelTestCase
{
    public function testAMediaSurvivesARoundTrip(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var MediaRepositoryInterface $repository */
        $repository = $container->get(MediaRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $ownerId = $this->anyUserId($connection);
        $mediaId = MediaId::fromString('01JQZ0000000000000000000AB');

        $media = MediaObject::request(
            $mediaId,
            $ownerId,
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);

        $reloaded = $repository->ofId($mediaId);

        self::assertSame(MediaStatus::Pending, $reloaded->status());
        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $reloaded->storageKey()->toString());
        self::assertTrue($reloaded->ownerId()->equals($ownerId));
        self::assertNull($reloaded->mimeType());
    }

    public function testTheDatabaseRefusesAReadyMediaWithoutMeasurements(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $this->expectExceptionMessageMatches('/media_ready_is_measured/');

        // L'invariant « un media pret est un media mesure » vit dans le schema,
        // pas seulement dans l'agregat : aucun chemin de code ne peut
        // l'enfreindre en silence.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO media_objects (id, owner_id, storage_key, status, declared_mime_type, declared_size, created_at)
                VALUES (:id, :owner_id, :storage_key, 'ready', 'image/jpeg', 2000, NOW())
                SQL,
            [
                'id' => '01JQZ0000000000000000000CD',
                'owner_id' => $this->anyUserId($connection)->toString(),
                'storage_key' => 'media/01JQZ0000000000000000000CD.jpg',
            ],
        );
    }

    public function testARejectedMediaKeepsItsReasonThroughPersistence(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var MediaRepositoryInterface $repository */
        $repository = $container->get(MediaRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $mediaId = MediaId::fromString('01JQZ0000000000000000000EF');
        $media = MediaObject::request(
            $mediaId,
            $this->anyUserId($connection),
            StorageKey::forOriginal($mediaId, MediaMimeType::Png),
            MediaMimeType::Png,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);

        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));
        $media->markRejected(MediaRejectionReason::UnsupportedType, new \DateTimeImmutable('2026-07-26T09:00:20+00:00'));
        $repository->save($media);

        $reloaded = $repository->ofId($mediaId);

        self::assertSame(MediaStatus::Rejected, $reloaded->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $reloaded->rejectionReason());
    }

    private function anyUserId(Connection $connection): UserId
    {
        /** @var string $id */
        $id = $connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return UserId::fromString($id);
    }
}
