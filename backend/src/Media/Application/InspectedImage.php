<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaMimeType;

final readonly class InspectedImage
{
    public function __construct(
        public MediaMimeType $mimeType,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {
    }
}
