<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\InvalidMediaTransitionException;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Event\MediaWasProcessed;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaObject::class)]
final class MediaObjectTest extends TestCase
{
    private const string MEDIA_ID = '01JQZ0000000000000000000AB';
    private const string OWNER_ID = '01JQZ0000000000000000000CD';

    public function testARequestedMediaIsPendingAndRecordsNothing(): void
    {
        $media = $this->request();

        self::assertSame(MediaStatus::Pending, $media->status());
        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $media->storageKey()->toString());
        self::assertNull($media->mimeType());
        // La pre-signature n'est pas un fait metier : personne n'a a en etre
        // averti tant que rien n'a ete televerse.
        self::assertSame([], $media->releaseEvents());
    }

    public function testConfirmingTheUploadMovesToProcessing(): void
    {
        $media = $this->request();

        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        self::assertSame(MediaStatus::Processing, $media->status());
    }

    public function testConfirmingTwiceIsANoOp(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));
        $media->markReady(
            MediaMimeType::Jpeg,
            1600,
            900,
            120_000,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );
        $media->releaseEvents();

        // Un reessai reseau du client ne doit produire NI seconde transition,
        // NI second traitement. Meme mecanique d'idempotence que le rejeu
        // d'envoi cote Message : rien d'enregistre, donc rien de republie.
        $media->markUploaded($this->at('2026-07-26T10:00:10+00:00'));

        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame([], $media->releaseEvents());
    }

    public function testBecomingReadyRecordsTheFactWithMeasuredValues(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        $media->markReady(
            MediaMimeType::Png,
            1600,
            900,
            120_000,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );

        self::assertSame(MediaStatus::Ready, $media->status());
        // Le type CONSTATE remplace le declare comme source de verite, et les
        // deux restent cote a cote : l'ecart est observable.
        self::assertSame(MediaMimeType::Png, $media->mimeType());
        self::assertSame(MediaMimeType::Jpeg, $media->declaredMimeType());
        self::assertSame(1600, $media->width());

        $events = $media->releaseEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MediaWasProcessed::class, $event);
        self::assertSame('ready', $event->status);
        self::assertSame('image/png', $event->mimeType);
        self::assertSame(1600, $event->width);
    }

    public function testARejectedMediaKeepsItsReasonAndAnnouncesItself(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        $media->markRejected(MediaRejectionReason::UnsupportedType, $this->at('2026-07-26T10:00:05+00:00'));

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());

        $events = $media->releaseEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MediaWasProcessed::class, $event);
        // Le front doit apprendre le refus : sans cet evenement, le message
        // resterait « en cours… » pour toujours.
        self::assertSame('rejected', $event->status);
        self::assertNull($event->mimeType);
    }

    public function testAReadyMediaCannotBeReprocessed(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));
        $media->markReady(
            MediaMimeType::Jpeg,
            10,
            10,
            100,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );

        $this->expectException(InvalidMediaTransitionException::class);

        $media->markRejected(MediaRejectionReason::TooLarge, $this->at('2026-07-26T10:00:10+00:00'));
    }

    public function testReconstituteRecordsNothing(): void
    {
        $media = MediaObject::reconstitute(
            MediaId::fromString(self::MEDIA_ID),
            UserId::fromString(self::OWNER_ID),
            StorageKey::forOriginal(MediaId::fromString(self::MEDIA_ID), MediaMimeType::Jpeg),
            OriginalFilename::fromString('photo.jpg'),
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            MediaStatus::Ready,
            MediaMimeType::Jpeg,
            2_000,
            MediaMimeType::Jpeg,
            10,
            10,
            100,
            null,
            $this->at('2026-07-26T09:00:00+00:00'),
            $this->at('2026-07-26T09:00:05+00:00'),
        );

        // Comme Message::reconstitute() : c'est par la qu'un rejeu ne republie
        // rien. Ne pas ajouter d'enregistrement ici.
        self::assertSame([], $media->releaseEvents());
    }

    private function request(): MediaObject
    {
        $mediaId = MediaId::fromString(self::MEDIA_ID);

        return MediaObject::request(
            $mediaId,
            UserId::fromString(self::OWNER_ID),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            OriginalFilename::fromString('photo.jpg'),
            MediaMimeType::Jpeg,
            2_000,
            $this->at('2026-07-26T09:59:00+00:00'),
        );
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
