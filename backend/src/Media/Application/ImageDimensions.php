<?php

declare(strict_types=1);

namespace App\Media\Application;

/** Ce que seule une image possede. Un document n'en a jamais. */
final readonly class ImageDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
