<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class MessageContentTooLongException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self(sprintf('Un message ne peut pas depasser %d caracteres.', MessageContent::MAX_LENGTH));
    }
}
