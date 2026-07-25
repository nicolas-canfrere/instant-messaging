<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class NotAGroupException extends \LogicException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('La composition d\'une conversation directe ne peut pas etre modifiee.');
    }
}
