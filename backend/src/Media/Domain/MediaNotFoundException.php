<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

final class MediaNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(MediaId $mediaId): self
    {
        return new self(sprintf('Le media %s est introuvable.', $mediaId->toString()));
    }
}
