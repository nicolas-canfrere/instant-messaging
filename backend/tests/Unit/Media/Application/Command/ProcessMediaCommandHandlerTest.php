<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application\Command;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\Command\ProcessMediaCommandHandler;
use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\InspectedImage;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Le handler telecharge un objet reel et decode reellement des octets :
 * `MediaProcessingTest` (fonctionnel) le verifie contre le vrai MinIO. Ici,
 * les adaptateurs sont doubles : ce fichier verifie la LOGIQUE du handler —
 * le plafond de taille, et le garde-fou de redelivrage — sans avoir a
 * committer une fixture de plus de 10 Mio ni a rejouer un vrai transport.
 */
#[CoversClass(ProcessMediaCommandHandler::class)]
final class ProcessMediaCommandHandlerTest extends TestCase
{
    private const string OWNER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public function testAnImageLargerThanTheCeilingIsRejectedAndItsOriginalBytesAreDeleted(): void
    {
        $mediaId = MediaId::fromString('01JQZ000000000000000090001');
        $media = $this->uploadedMedia($mediaId);
        $localPath = $this->temporaryFile();

        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())->method('downloadToTemporaryFile')->with($media->storageKey())->willReturn($localPath);
        // Le plafond ne peut se verifier qu'apres inspection : `put()` (la
        // miniature) ne doit JAMAIS etre atteint pour un fichier trop lourd.
        $storage->expects(self::never())->method('put');
        $storage->expects(self::once())->method('delete')->with($media->storageKey(), $mediaId);

        $inspector = $this->createStub(ImageInspectorInterface::class);
        $inspector->method('inspect')->willReturn(
            new InspectedImage(MediaMimeType::Jpeg, 4000, 3000, MediaObject::MAX_BYTES + 1),
        );

        $handler = $this->handler($media, $storage, $inspector);

        $handler(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::TooLarge, $media->rejectionReason());
    }

    public function testARedeliveredMessageForAnAlreadyTerminalMediaDoesNothing(): void
    {
        $mediaId = MediaId::fromString('01JQZ000000000000000090002');
        $media = $this->uploadedMedia($mediaId);
        $media->markReady(
            MediaMimeType::Jpeg,
            1600,
            900,
            2_000,
            StorageKey::forThumbnail($mediaId),
            new \DateTimeImmutable('2026-07-26T09:00:20+00:00'),
        );

        // Rien de tout cela ne doit etre appele : le garde-fou doit sortir
        // AVANT le premier acces reseau, pas apres avoir deja depense un
        // telechargement et un putObject de miniature.
        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::never())->method('downloadToTemporaryFile');
        $storage->expects(self::never())->method('put');
        $storage->expects(self::never())->method('delete');

        $inspector = $this->createMock(ImageInspectorInterface::class);
        $inspector->expects(self::never())->method('inspect');

        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('notice');
        $logger->expects(self::never())->method('warning');

        $handler = new ProcessMediaCommandHandler(
            $this->repositoryReturning($media),
            $storage,
            $inspector,
            $clock,
            $logger,
        );

        $handler(new ProcessMediaCommand($mediaId));

        // L'agregat ressort inchange : toujours Ready, meme miniature.
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(1600, $media->width());
    }

    private function uploadedMedia(MediaId $mediaId): MediaObject
    {
        $media = MediaObject::request(
            $mediaId,
            UserId::fromString(self::OWNER_ID),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            OriginalFilename::fromString('photo.jpg'),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));

        return $media;
    }

    private function handler(
        MediaObject $media,
        MediaStorageInterface $storage,
        ImageInspectorInterface $inspector,
    ): ProcessMediaCommandHandler {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-26T09:00:30+00:00'));

        return new ProcessMediaCommandHandler(
            $this->repositoryReturning($media),
            $storage,
            $inspector,
            $clock,
            new NullLogger(),
        );
    }

    /** Repository double : rend toujours le meme agregat mutable, `save()` ne fait rien de plus. */
    private function repositoryReturning(MediaObject $media): MediaRepositoryInterface
    {
        $repository = $this->createStub(MediaRepositoryInterface::class);
        $repository->method('ofId')->willReturn($media);

        return $repository;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'process-media-test-');

        if (false === $path) {
            self::fail('Impossible de creer un fichier temporaire pour le test.');
        }

        return $path;
    }
}
