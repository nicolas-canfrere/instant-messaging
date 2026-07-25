<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\UserId;

/**
 * Cle d'unicite d'une conversation 1-1. Commutative par construction :
 * l'invariant vit dans le type, pas dans la discipline de l'appelant.
 *
 * C'est cette propriete, combinee a l'unicite de la colonne direct_key, qui
 * rend la creation d'un direct idempotente — deux demandes concurrentes entre
 * les memes personnes convergent vers la meme ligne, quel que soit qui demande.
 */
final readonly class DirectKey implements \Stringable
{
    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function forPair(UserId $one, UserId $other): self
    {
        if ($one->equals($other)) {
            throw SelfConversationException::create();
        }

        $pair = [$one->toString(), $other->toString()];
        sort($pair);

        return new self(sprintf('%s:%s', $pair[0], $pair[1]));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
