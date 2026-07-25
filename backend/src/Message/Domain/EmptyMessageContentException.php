<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class EmptyMessageContentException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('Un message ne peut pas etre vide.');
    }
}
