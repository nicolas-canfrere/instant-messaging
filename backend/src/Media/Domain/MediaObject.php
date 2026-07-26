<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Event\MediaWasProcessed;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Un objet televerse, et rien de plus. Cet agregat IGNORE l'existence des
 * messages et des conversations : il connait un proprietaire et des octets.
 * C'est cette ignorance qui rendra l'extraction du contexte en service
 * triviale le jour venu (spec §1.1) — ne pas l'entamer.
 */
final class MediaObject
{
    use RecordsEventsTrait;

    /** Dix mebioctets. Regle metier, pas reglage d'exploitation. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private function __construct(
        private readonly MediaId $id,
        private readonly UserId $ownerId,
        private readonly StorageKey $storageKey,
        private ?StorageKey $thumbnailKey,
        private MediaStatus $status,
        private readonly MediaMimeType $declaredMimeType,
        private readonly int $declaredSize,
        private ?MediaMimeType $mimeType,
        private ?int $width,
        private ?int $height,
        private ?int $byteSize,
        private ?MediaRejectionReason $rejectionReason,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $processedAt,
    ) {
    }

    public static function request(
        MediaId $id,
        UserId $ownerId,
        StorageKey $storageKey,
        MediaMimeType $declaredMimeType,
        int $declaredSize,
        \DateTimeImmutable $now,
    ): self {
        // AUCUN evenement : la pre-signature n'est pas un fait metier. Personne
        // n'a a en etre averti tant que rien n'a ete televerse.
        return new self(
            $id,
            $ownerId,
            $storageKey,
            null,
            MediaStatus::Pending,
            $declaredMimeType,
            $declaredSize,
            null,
            null,
            null,
            null,
            null,
            $now,
            null,
        );
    }

    /** @see MediaObjectTest::testReconstituteRecordsNothing() — ne rien enregistrer ici. */
    public static function reconstitute(
        MediaId $id,
        UserId $ownerId,
        StorageKey $storageKey,
        ?StorageKey $thumbnailKey,
        MediaStatus $status,
        MediaMimeType $declaredMimeType,
        int $declaredSize,
        ?MediaMimeType $mimeType,
        ?int $width,
        ?int $height,
        ?int $byteSize,
        ?MediaRejectionReason $rejectionReason,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $processedAt,
    ): self {
        return new self(
            $id,
            $ownerId,
            $storageKey,
            $thumbnailKey,
            $status,
            $declaredMimeType,
            $declaredSize,
            $mimeType,
            $width,
            $height,
            $byteSize,
            $rejectionReason,
            $createdAt,
            $processedAt,
        );
    }

    /**
     * Rejouable sans condition dans le controleur : un media deja au-dela de
     * `Pending` ressort inchange, sans evenement, donc sans second traitement.
     */
    public function markUploaded(\DateTimeImmutable $now): void
    {
        if (MediaStatus::Pending !== $this->status) {
            return;
        }

        $this->status = MediaStatus::Processing;
    }

    public function markReady(
        MediaMimeType $mimeType,
        int $width,
        int $height,
        int $byteSize,
        StorageKey $thumbnailKey,
        \DateTimeImmutable $now,
    ): void {
        $this->guardNotTerminal(MediaStatus::Ready);

        $this->status = MediaStatus::Ready;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
        $this->byteSize = $byteSize;
        $this->thumbnailKey = $thumbnailKey;
        $this->processedAt = $now;

        $this->recordEvent(new MediaWasProcessed(
            $this->id,
            $this->status->value,
            $mimeType->value,
            $width,
            $height,
            $byteSize,
            $now,
        ));
    }

    public function markRejected(MediaRejectionReason $reason, \DateTimeImmutable $now): void
    {
        $this->guardNotTerminal(MediaStatus::Rejected);

        $this->status = MediaStatus::Rejected;
        $this->rejectionReason = $reason;
        $this->processedAt = $now;

        // Le refus est annonce comme la reussite : sans cet evenement, un
        // message porteur resterait « en cours… » pour toujours.
        $this->recordEvent(new MediaWasProcessed(
            $this->id,
            $this->status->value,
            null,
            null,
            null,
            null,
            $now,
        ));
    }

    public function id(): MediaId
    {
        return $this->id;
    }

    public function ownerId(): UserId
    {
        return $this->ownerId;
    }

    public function storageKey(): StorageKey
    {
        return $this->storageKey;
    }

    public function thumbnailKey(): ?StorageKey
    {
        return $this->thumbnailKey;
    }

    public function status(): MediaStatus
    {
        return $this->status;
    }

    public function declaredMimeType(): MediaMimeType
    {
        return $this->declaredMimeType;
    }

    public function declaredSize(): int
    {
        return $this->declaredSize;
    }

    public function mimeType(): ?MediaMimeType
    {
        return $this->mimeType;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function byteSize(): ?int
    {
        return $this->byteSize;
    }

    public function rejectionReason(): ?MediaRejectionReason
    {
        return $this->rejectionReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    private function guardNotTerminal(MediaStatus $to): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidMediaTransitionException::from($this->status, $to);
        }
    }
}
