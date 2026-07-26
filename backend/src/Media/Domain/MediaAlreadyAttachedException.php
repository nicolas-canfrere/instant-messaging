<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

/** C'est Media qui possede la regle « un media ne s'attache qu'une fois ». */
final class MediaAlreadyAttachedException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function withId(MediaId $mediaId): self
    {
        return new self(sprintf('Le media %s est deja attache a un message.', $mediaId->toString()));
    }

    public function problemSlug(): string
    {
        return 'media-already-attached';
    }

    public function problemTitle(): string
    {
        return 'Media deja attache';
    }
}
