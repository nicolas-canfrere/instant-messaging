<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasSent;

/** Un seul publish par message : le hub assure le fan-out, le metier reste en O(1). */
final readonly class PublishMessageWasSentListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasSent $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.created',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'content' => $event->content,
                'created_at' => $event->createdAt->format(\DateTimeInterface::ATOM),
            ],
            // L'id de l'evenement SSE est l'ULID du message : Last-Event-ID
            // deviendra exploitable en T2 sans changer ce format.
            $event->messageId->toString(),
        );
    }
}
