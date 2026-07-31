<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\File;

use App\Media\Application\MimeTypeDetectorInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

final readonly class FinfoMimeTypeDetector implements MimeTypeDetectorInterface
{
    public function detect(string $localPath): MediaMimeType|MediaRejectionReason
    {
        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return MediaRejectionReason::UnsupportedType;
        }

        return MediaMimeType::tryFrom($detected) ?? MediaRejectionReason::UnsupportedType;
    }
}
