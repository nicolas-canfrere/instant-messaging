<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Conversation, ecoute par Realtime : evenement inter-contextes, donc
 * dans Shared. Charge utile faite de types Shared et de scalaires uniquement.
 *
 * Les watermarks voyagent en `string` et non en MessageId : ce sont des
 * curseurs, pas des references. Un curseur doit survivre a la suppression du
 * message qu'il designe, que la tranche 3 va introduire.
 *
 * Les DEUX curseurs sont transportes a chaque fois, meme si un seul a bouge :
 * le destinataire remplace l'etat du membre au lieu de le fusionner, ce qui
 * rend le traitement idempotent et supprime toute dependance a l'ordre
 * d'arrivee des evenements.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class ReceiptWatermarkAdvanced implements DomainEventInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public ?string $lastDeliveredMessageId,
        public ?string $lastReadMessageId,
    ) {
    }
}
