<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Identifier\UserId;

/**
 * DirectKey n'a qu'un constructeur nomme metier, `forPair()`. La reconstitution
 * depuis la base passe donc par cet hydrateur d'infrastructure : le domaine
 * n'expose pas de constructeur « depuis une chaine », qui permettrait de
 * fabriquer une cle incoherente.
 */
final class DirectKeyHydrator
{
    public static function fromString(string $value): DirectKey
    {
        [$one, $other] = explode(':', $value, 2);

        return DirectKey::forPair(UserId::fromString($one), UserId::fromString($other));
    }
}
