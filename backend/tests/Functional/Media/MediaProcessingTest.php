<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\Command\ProcessMediaCommandHandler;
use App\Media\Application\MediaStorageInterface;
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

final class MediaProcessingTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/media/';

    public function testAValidImageBecomesReadyWithAThumbnail(): void
    {
        $mediaId = $this->uploaded('valide.jpg', MediaMimeType::Jpeg);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(MediaMimeType::Jpeg, $media->mimeType());
        self::assertSame(1600, $media->width());
        self::assertNotNull($media->thumbnailKey());
        // La miniature existe REELLEMENT dans le bucket, elle n'est pas
        // seulement enregistree en base.
        self::assertNotNull($this->storage()->downloadToTemporaryFile($media->thumbnailKey()));
    }

    public function testAPhpFileRenamedJpgIsRejectedAndItsBytesAreDestroyed(): void
    {
        $mediaId = $this->uploaded('piege.jpg', MediaMimeType::Jpeg);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());
        // On ne conserve pas les octets d'un fichier qu'on ne servira jamais.
        self::assertNull($this->storage()->downloadToTemporaryFile($media->storageKey()));
    }

    public function testAMissingObjectIsRejectedRatherThanRetriedForever(): void
    {
        // Litteral plutot que derive de crc32() : ce test n'a pas de fixture
        // associee, seulement un identifiant qui doit rester un ULID valide.
        $mediaId = MediaId::fromString('01JQZ000000000000000099999');
        $this->repository()->add(MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        ));

        $this->process($mediaId);

        self::assertSame(MediaRejectionReason::MissingObject, $this->repository()->ofId($mediaId)->rejectionReason());
    }

    /** Depose reellement le fichier dans MinIO, puis rend l'identifiant. */
    private function uploaded(string $fixture, MediaMimeType $declared): MediaId
    {
        self::bootKernel();
        $mediaId = MediaId::fromString(sprintf('01JQZ0000000000000000%05d', crc32($fixture) % 100_000));
        $key = StorageKey::forOriginal($mediaId, $declared);

        $media = MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            $key,
            $declared,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));
        $this->repository()->add($media);
        $this->repository()->save($media);

        $this->storage()->put($key, self::FIXTURES . $fixture, $declared);

        return $mediaId;
    }

    /**
     * Invoque le vrai handler directement, sans passer par le bus : le
     * transport `media` est `in-memory://` en test (aucun RabbitMQ requis
     * en CI), donc `dispatch()` ne ferait qu'y deposer le message sans le
     * consommer — `HandleMessageMiddleware` ne s'execute jamais quand un
     * message est route vers un transport. C'est le worker (conteneur
     * separe) qui consomme la vraie file ; ici on declenche le handler « a
     * la main », comme le prevoit ce test.
     */
    private function process(MediaId $mediaId): void
    {
        self::bootKernel();

        /** @var ProcessMediaCommandHandler $handler */
        $handler = self::getContainer()->get(ProcessMediaCommandHandler::class);
        $handler(new ProcessMediaCommand($mediaId));
    }

    private function repository(): MediaRepositoryInterface
    {
        self::bootKernel();

        /** @var MediaRepositoryInterface */
        return self::getContainer()->get(MediaRepositoryInterface::class);
    }

    private function storage(): MediaStorageInterface
    {
        self::bootKernel();

        /** @var MediaStorageInterface */
        return self::getContainer()->get(MediaStorageInterface::class);
    }

    private function anyUserId(): UserId
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var string $id */
        $id = $connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return UserId::fromString($id);
    }
}
