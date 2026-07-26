<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;

/**
 * UN SEUL publish, sur le topic de la conversation — pas un par expediteur.
 *
 * Le topic personnel `/users/{id}/receipts` aurait impose de connaitre les
 * expediteurs distincts des messages compris entre l'ancien et le nouveau
 * watermark : une requete dans la table de Message depuis Conversation, ce que
 * l'ADR 0001 interdit, puis un publish par expediteur. Le metier serait repasse
 * en O(N) la ou la tranche 1 avait obtenu O(1).
 *
 * Aucun identifiant SSE : un accuse est autoreparateur — l'etat complet est
 * recharge au GET du detail, et le watermark suivant corrige tout ecart.
 */
final readonly class PublishReceiptUpdatedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(ReceiptWatermarkAdvanced $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'receipt.updated',
            [
                'conversation_id' => $event->conversationId->toString(),
                'user_id' => $event->userId->toString(),
                // Les DEUX curseurs a chaque fois : le client remplace l'etat du
                // membre au lieu de le fusionner, donc l'ordre d'arrivee des
                // evenements n'a aucune importance.
                'last_delivered_message_id' => $event->lastDeliveredMessageId,
                'last_read_message_id' => $event->lastReadMessageId,
            ],
        );
    }
}
