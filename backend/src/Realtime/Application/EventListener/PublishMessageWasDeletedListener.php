<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasDeleted;

/**
 * Aucun `id` d'evenement SSE, et c'est une decision.
 *
 * L'id d'un evenement Mercure est l'ULID du message. Supprimer un message
 * ancien emettrait donc un id ANTERIEUR a ceux deja recus : le Last-Event-ID du
 * client reculerait, et le hub lui rejouerait tout l'historique depuis ce point
 * a la reconnexion suivante. Un identifiant de reprise qui recule est pire que
 * pas de reprise du tout.
 *
 * Consequence assumee : un client deconnecte pendant une suppression la
 * decouvre en rechargeant l'historique, qui porte deja l'etat a jour.
 */
final readonly class PublishMessageWasDeletedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasDeleted $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.deleted',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'deleted_at' => $event->deletedAt->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
