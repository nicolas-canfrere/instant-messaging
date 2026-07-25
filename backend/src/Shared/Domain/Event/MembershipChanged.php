<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Conversation, ecoute par Realtime : evenement inter-contextes, donc
 * dans Shared. Un abonne doit connaitre l'evenement auquel il s'abonne.
 *
 * Charge utile faite de types Shared et de scalaires uniquement. Modifier cette
 * signature est un changement cassant.
 */
final readonly class MembershipChanged implements DomainEventInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public MembershipChange $change,
    ) {
    }
}
