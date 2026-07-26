<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasEdited;

/**
 * Aucun `id` d'evenement SSE : editer un message ancien emettrait un id
 * ANTERIEUR a ceux deja recus, et le Last-Event-ID du client reculerait. Meme
 * raison que pour `message.deleted`.
 *
 * Pas de `client_message_id` non plus, et ce n'est pas un oubli : la cle de
 * reconciliation existe deja, c'est l'`id` serveur. Le message est en base, le
 * front le connait, et l'echo SSE comme la reponse du PATCH portent le meme
 * etat final.
 */
final readonly class PublishMessageWasEditedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasEdited $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.edited',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'content' => $event->content,
                'edited_at' => $event->editedAt->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
