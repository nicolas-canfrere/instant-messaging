<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/** Frontiere unique ou la ligne brute devient un type precis (PHPStan max). */
final readonly class MediaMapper
{
    /**
     * @param array{id: string, owner_id: string, storage_key: string, thumbnail_key: string|null,
     *              status: string, declared_mime_type: string, declared_size: int,
     *              mime_type: string|null, width: int|null, height: int|null, byte_size: int|null,
     *              rejection_reason: string|null, created_at: string, processed_at: string|null} $row
     */
    public function fromRow(array $row): MediaObject
    {
        return MediaObject::reconstitute(
            MediaId::fromString($row['id']),
            UserId::fromString($row['owner_id']),
            StorageKey::fromString($row['storage_key']),
            null === $row['thumbnail_key'] ? null : StorageKey::fromString($row['thumbnail_key']),
            // `from` et non `tryFrom` : une valeur inconnue en base est une
            // corruption, pas un cas metier. Elle doit exploser bruyamment.
            MediaStatus::from($row['status']),
            MediaMimeType::from($row['declared_mime_type']),
            $row['declared_size'],
            null === $row['mime_type'] ? null : MediaMimeType::from($row['mime_type']),
            $row['width'],
            $row['height'],
            $row['byte_size'],
            null === $row['rejection_reason'] ? null : MediaRejectionReason::from($row['rejection_reason']),
            new \DateTimeImmutable($row['created_at']),
            null === $row['processed_at'] ? null : new \DateTimeImmutable($row['processed_at']),
        );
    }
}
