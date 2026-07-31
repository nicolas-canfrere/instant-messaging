<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

/**
 * C'est Message, et non Media, qui possede la regle « un media ne s'attache
 * qu'une fois » : la question se pose sur `message_media`, une table de
 * Message. Le 409 ne revele rien que l'appelant ne sache deja, puisqu'il est
 * proprietaire du media (l'appartenance a deja ete etablie par Media).
 */
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
