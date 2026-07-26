<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class EmptyMessageException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('Un message doit porter du texte ou au moins une image.');
    }
}
