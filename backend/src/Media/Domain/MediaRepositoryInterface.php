<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Identifier\MediaId;

interface MediaRepositoryInterface
{
    public function add(MediaObject $media): void;

    /** @throws MediaNotFoundException */
    public function ofId(MediaId $mediaId): MediaObject;

    public function save(MediaObject $media): void;
}
