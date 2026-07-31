<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Application\Query\UploadTicketReaderInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use Doctrine\DBAL\Connection;

final readonly class DbalUploadTicketReader implements UploadTicketReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{key: StorageKey, mimeType: MediaMimeType}|null */
    public function keyAndTypeOf(MediaId $mediaId): ?array
    {
        /** @var array{storage_key: string, declared_mime_type: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT storage_key, declared_mime_type
                FROM media_objects
                WHERE id = :id
                SQL,
            ['id' => $mediaId->toString()],
        );

        if (false === $row) {
            return null;
        }

        return [
            'key' => StorageKey::fromString($row['storage_key']),
            'mimeType' => MediaMimeType::from($row['declared_mime_type']),
        ];
    }
}
