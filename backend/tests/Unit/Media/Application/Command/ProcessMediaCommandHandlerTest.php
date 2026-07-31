<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application\Command;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\Command\ProcessMediaCommandHandler;
use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\MediaStorageInterface;
use App\Media\Application\MimeTypeDetectorInterface;
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
        $localPath = $this->temporaryFile(MediaObject::MAX_BYTES + 1);

        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())->method('downloadToTemporaryFile')->with($media->storageKey())->willReturn($localPath);
        // Le plafond ne peut se verifier qu'apres inspection : `put()` (la
        // miniature) ne doit JAMAIS etre atteint pour un fichier trop lourd.
        $storage->expects(self::never())->method('put');
        $storage->expects(self::once())->method('delete')->with($media->storageKey(), $mediaId);

        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $inspector = $this->createStub(ImageInspectorInterface::class);

        $handler = $this->handler($media, $storage, $detector, $inspector);

        $handler(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::TooLarge, $media->rejectionReason());
    }

    public function testAnOversizedFileIsRejectedWithoutEvenDetectingItsType(): void
    {
        // L'ordre compte : detecter d'abord ferait lire un fichier
        // arbitrairement gros. Le detecteur ne doit PAS etre appele.
        $mediaId = MediaId::fromString('01JQZ000000000000000090003');
        $media = $this->uploadedMedia($mediaId);
        $localPath = $this->temporaryFile(MediaObject::MAX_BYTES + 1);

        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())->method('downloadToTemporaryFile')->with($media->storageKey())->willReturn($localPath);
        $storage->expects(self::never())->method('put');
        $storage->expects(self::once())->method('delete')->with($media->storageKey(), $mediaId);

        $detector = new class implements MimeTypeDetectorInterface {
            private int $calls = 0;

            public function detect(string $localPath): MediaMimeType
            {
                ++$this->calls;

                return MediaMimeType::Jpeg;
            }

            public function detectCallCount(): int
            {
                return $this->calls;
            }
        };

        $inspector = $this->createMock(ImageInspectorInterface::class);
        $inspector->expects(self::never())->method('measure');

        $handler = $this->handler($media, $storage, $detector, $inspector);

        $handler(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaRejectionReason::TooLarge, $media->rejectionReason());
        self::assertSame(0, $detector->detectCallCount());
    }

    public function testALocalFileVanishingBeforeInspectionIsRejectedAsUndecodableAndItsBytesArePurged(): void
    {
        // Le telechargement a deja reussi (c'est `downloadToTemporaryFile`
        // qui rend ce chemin) : l'objet EST dans le bucket. Ce test simule sa
        // disparition locale APRES le telechargement mais AVANT la lecture de
        // sa taille — un chemin qui pointe vers rien. `MissingObject`
        // mentirait ici, et `eraseBytes:false` laisserait les octets orphelins
        // dans le bucket : c'est pourquoi ce cas doit rejeter avec
        // `Undecodable` et purger.
        $mediaId = MediaId::fromString('01JQZ000000000000000090004');
        $media = $this->uploadedMedia($mediaId);
        $localPath = sprintf('%s/media-test-disparu-%s', sys_get_temp_dir(), $mediaId->toString());

        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())->method('downloadToTemporaryFile')->with($media->storageKey())->willReturn($localPath);
        $storage->expects(self::never())->method('put');
        $storage->expects(self::once())->method('delete')->with($media->storageKey(), $mediaId);

        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects(self::never())->method('detect');

        $inspector = $this->createMock(ImageInspectorInterface::class);
        $inspector->expects(self::never())->method('measure');

        $handler = $this->handler($media, $storage, $detector, $inspector);

        $handler(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::Undecodable, $media->rejectionReason());
    }

    public function testARedeliveredMessageForAnAlreadyTerminalMediaDoesNothing(): void
    {
        $mediaId = MediaId::fromString('01JQZ000000000000000090002');
        $media = $this->uploadedMedia($mediaId);
        $media->markImageReady(
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

        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects(self::never())->method('detect');

        $inspector = $this->createMock(ImageInspectorInterface::class);
        $inspector->expects(self::never())->method('measure');

        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('notice');
        $logger->expects(self::never())->method('warning');

        $handler = new ProcessMediaCommandHandler(
            $this->repositoryReturning($media),
            $storage,
            $detector,
            $inspector,
            $clock,
            $logger,
        );

        $handler(new ProcessMediaCommand($mediaId));

        // L'agregat ressort inchange : toujours Ready, meme miniature.
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(1600, $media->width());
    }

    public function testADocumentIsMarkedReadyWithoutMeasurementOrThumbnail(): void
    {
        $mediaId = MediaId::fromString('01JQZ000000000000000090005');
        $media = $this->uploadedMedia($mediaId);
        $localPath = $this->temporaryFile(4_096);

        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())->method('downloadToTemporaryFile')->with($media->storageKey())->willReturn($localPath);
        // Ni mesure ni miniature pour un document : `put()` ne doit jamais
        // etre atteint.
        $storage->expects(self::never())->method('put');
        $storage->expects(self::never())->method('delete');

        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detect')->willReturn(MediaMimeType::Text);

        $inspector = $this->createMock(ImageInspectorInterface::class);
        $inspector->expects(self::never())->method('measure');
        $inspector->expects(self::never())->method('thumbnail');

        $handler = $this->handler($media, $storage, $detector, $inspector);

        $handler(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(MediaMimeType::Text, $media->mimeType());
        self::assertNull($media->width());
        self::assertNull($media->height());
        self::assertNull($media->thumbnailKey());
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
        MimeTypeDetectorInterface $detector,
        ImageInspectorInterface $inspector,
    ): ProcessMediaCommandHandler {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-26T09:00:30+00:00'));

        return new ProcessMediaCommandHandler(
            $this->repositoryReturning($media),
            $storage,
            $detector,
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

    /** Chemin unique par appel. `$size` cree un fichier creux de la taille demandee, sans en ecrire vraiment les octets sur disque. */
    private function temporaryFile(int $size = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'process-media-test-');

        if (false === $path) {
            self::fail('Impossible de creer un fichier temporaire pour le test.');
        }

        if ($size > 0) {
            $handle = fopen($path, 'wb');

            if (false === $handle) {
                self::fail('Impossible d\'ouvrir le fichier temporaire pour le test.');
            }

            ftruncate($handle, $size);
            fclose($handle);
        }

        return $path;
    }
}
