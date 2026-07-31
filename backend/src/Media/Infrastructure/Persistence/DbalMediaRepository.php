<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Domain\MediaNotFoundException;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\MediaId;
use Doctrine\DBAL\Connection;

final readonly class DbalMediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private MediaMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function add(MediaObject $media): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO media_objects (id, owner_id, storage_key, status, declared_mime_type, declared_size, created_at)
                VALUES (:id, :owner_id, :storage_key, :status, :declared_mime_type, :declared_size, :created_at)
                SQL,
            [
                'id' => $media->id()->toString(),
                'owner_id' => $media->ownerId()->toString(),
                'storage_key' => $media->storageKey()->toString(),
                'status' => $media->status()->value,
                'declared_mime_type' => $media->declaredMimeType()->value,
                'declared_size' => $media->declaredSize(),
                'created_at' => $media->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        $this->collector->collect(...$media->releaseEvents());
    }

    public function ofId(MediaId $mediaId): MediaObject
    {
        /** @var array{id: string, owner_id: string, storage_key: string, thumbnail_key: string|null, status: string, declared_mime_type: string, declared_size: int, mime_type: string|null, width: int|null, height: int|null, byte_size: int|null, rejection_reason: string|null, created_at: string, processed_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, owner_id, storage_key, thumbnail_key, status, declared_mime_type, declared_size,
                       mime_type, width, height, byte_size, rejection_reason, created_at, processed_at
                FROM media_objects
                WHERE id = :id
                SQL,
            ['id' => $mediaId->toString()],
        );

        if (false === $row) {
            throw MediaNotFoundException::withId($mediaId);
        }

        return $this->mapper->fromRow($row);
    }

    public function save(MediaObject $media): void
    {
        // Seules les colonnes mutables : l'id, le proprietaire, la cle de
        // l'original, le declare et l'instant de creation ne sont pas
        // remplacables. Un media ne change pas de proprietaire.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE media_objects
                SET status = :status,
                    thumbnail_key = :thumbnail_key,
                    mime_type = :mime_type,
                    width = :width,
                    height = :height,
                    byte_size = :byte_size,
                    rejection_reason = :rejection_reason,
                    processed_at = :processed_at
                WHERE id = :id
                SQL,
            [
                'status' => $media->status()->value,
                'thumbnail_key' => $media->thumbnailKey()?->toString(),
                'mime_type' => $media->mimeType()?->value,
                'width' => $media->width(),
                'height' => $media->height(),
                'byte_size' => $media->byteSize(),
                'rejection_reason' => $media->rejectionReason()?->value,
                'processed_at' => $media->processedAt()?->format(\DateTimeInterface::ATOM),
                'id' => $media->id()->toString(),
            ],
        );

        $this->collector->collect(...$media->releaseEvents());
    }

    public function remove(MediaId $mediaId): void
    {
        // Aucun evenement : la disparition d'un media que personne ne portait
        // n'interesse personne. Le journal de la purge suffit a en garder trace.
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM media_objects WHERE id = :id
                SQL,
            ['id' => $mediaId->toString()],
        );
    }
}
