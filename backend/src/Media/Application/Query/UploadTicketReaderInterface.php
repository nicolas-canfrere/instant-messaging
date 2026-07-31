<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;

/**
 * Le handler de query declare son besoin par un port ; `Dbal…Reader` le
 * realise. Jamais de SQL dans Application, y compris cote lecture.
 */
interface UploadTicketReaderInterface
{
    /** @return array{key: StorageKey, mimeType: MediaMimeType}|null */
    public function keyAndTypeOf(MediaId $mediaId): ?array;
}
