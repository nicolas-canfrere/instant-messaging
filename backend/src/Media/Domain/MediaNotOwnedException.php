<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

final class MediaNotOwnedException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function forMedia(MediaId $mediaId): self
    {
        return new self(sprintf('Le media %s ne vous appartient pas.', $mediaId->toString()));
    }

    public function problemSlug(): string
    {
        return 'media-not-owned';
    }

    public function problemTitle(): string
    {
        return 'Media non possede';
    }
}
