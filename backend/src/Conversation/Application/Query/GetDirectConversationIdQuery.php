<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Identifiant du direct entre deux personnes, quel que soit l'ordre.
 *
 * C'est ainsi qu'on retrouve l'effet d'une ecriture maintenant que les
 * commandes ne rendent rien : on ecrit, puis on demande.
 *
 * @implements QueryInterface<ConversationId>
 */
final readonly class GetDirectConversationIdQuery implements QueryInterface
{
    public function __construct(
        public UserId $initiator,
        public UserId $peer,
    ) {
    }
}
