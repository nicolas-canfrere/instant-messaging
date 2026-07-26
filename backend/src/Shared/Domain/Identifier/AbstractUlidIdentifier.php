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
     * Motif nu, sans delimiteurs : base32 Crockford, ni I, ni L, ni O, ni U.
     * Premier caractere <= 7 (timestamp sur 48 bits). 26 caracteres au total.
     *
     * Utilisable en `requirements:` de route.
     */
    public const string ROUTE_PATTERN = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

    /**
     * Forme delimitee et ancree du motif, consommee par les contraintes de
     * validation des charges utiles et par le constructeur. C'est ici, et
     * nulle part ailleurs, que le format est defini.
     *
     * `\A` et `\z` plutot que `^` et `$` : `$` accepte un saut de ligne final,
     * si bien qu'un ULID suivi d'un `\n` passait la validation avant de casser
     * sur la colonne CHAR(26) — un 500 la ou l'entree meritait un 422.
     * `ROUTE_PATTERN`, lui, n'a pas ce besoin : Symfony compile les
     * `requirements:` de route avec le modificateur `D`.
     */
    public const string PATTERN = '/\A' . self::ROUTE_PATTERN . '\z/';

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
