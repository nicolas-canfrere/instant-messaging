<?php

declare(strict_types=1);

namespace App\Realtime\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Seul constructeur de chaines de topic du projet. Une faute de frappe dans une
 * concatenation manuelle serait un bug de securite silencieux : le message
 * partirait sur un topic auquel personne n'est abonne, ou mal cloisonne.
 *
 * Constructeur prive : on ne fabrique un Topic que par un des constructeurs
 * nommes, donc jamais a partir d'une chaine arbitraire.
 */
final readonly class Topic implements \Stringable
{
    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function conversation(ConversationId $conversationId): self
    {
        return new self(sprintf('/conversations/%s', $conversationId->toString()));
    }

    /**
     * Canal personnel, present dans TOUS les JWT et immuable : c'est le seul
     * par lequel un utilisateur apprend qu'on l'a ajoute a une conversation,
     * donc qu'il doit demander un nouveau jeton.
     */
    public static function userSystem(UserId $userId): self
    {
        return new self(sprintf('/users/%s/system', $userId->toString()));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
