<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

/**
 * Un ULID : 26 caracteres en base32 Crockford, triable chronologiquement
 * par simple comparaison de chaines. Le domaine ne genere jamais d'identifiant
 * (cf. IdGeneratorInterface), il se contente de valider ceux qu'il recoit.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractUlidIdentifier implements \Stringable
{
    /**
     * Base32 Crockford : ni I, ni L, ni O, ni U. Premier caractere <= 7
     * (timestamp sur 48 bits).
     *
     * Publique parce que les contraintes de validation des charges utiles s'y
     * referent : c'est LA definition du format, elle ne doit exister qu'ici.
     */
    public const string PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    protected function __construct(private readonly string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidIdentifierException::forType(static::class, $value);
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }
}
