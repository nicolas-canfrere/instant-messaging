<?php

declare(strict_types=1);

namespace App\Message\Domain;

final readonly class MessageContent implements \Stringable
{
    /**
     * Comptee en CARACTERES, pas en octets : `strlen` rejetterait un message
     * valide des qu'il contient un accent.
     *
     * Publique parce que la contrainte de validation de la charge utile s'y
     * refere — la limite ne doit exister qu'ici.
     */
    public const int MAX_LENGTH = 4000;

    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw EmptyMessageContentException::create();
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw MessageContentTooLongException::create();
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
