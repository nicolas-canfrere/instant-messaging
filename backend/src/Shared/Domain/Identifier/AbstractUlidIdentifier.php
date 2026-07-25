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

    /** @var non-empty-string */
    private readonly string $value;

    protected function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidIdentifierException::forType(static::class, $value);
        }

        // Apres ce `preg_match`, PHPStan sait deja que la chaine n'est pas vide :
        // c'est ce qui permet a `toString()` de promettre un `non-empty-string`
        // sans annotation de complaisance.
        $this->value = $value;
    }

    /** @return non-empty-string */
    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /** @return non-empty-string */
    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }
}
