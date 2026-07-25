<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class InvalidIdentifierException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function forType(string $type, string $value): self
    {
        // `sprintf` est interdit dans les logs, pas dans les messages d'exception :
        // une exception n'est ni agregee ni groupee sur son message.
        return new self(sprintf('"%s" n\'est pas un %s valide.', $value, $type));
    }
}
