<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Media\Application\Contract\MediaFinderInterface;
use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageMediaBecameReady;

/**
 * Le dernier saut. La vue est relue et RESIGNEE ici, au moment de pousser :
 * une URL pre-signee vit quinze minutes, elle ne pouvait donc pas voyager dans
 * l'evenement.
 *
 * Nomme `MediaFinderInterface`, le contrat publie de Media, sans port
 * intermediaire : il n'y aurait rien a traduire (ADR 0001, amendement du
 * 2026-07-29). Meme usage que le contrat de Conversation ailleurs dans ce
 * contexte.
 */
final readonly class PublishMessageMediaBecameReadyListener implements DomainEventListenerInterface
{
    public function __construct(
        private MediaFinderInterface $media,
        private EventPublisherInterface $publisher,
    ) {
    }

    public function __invoke(MessageMediaBecameReady $event): void
    {
        $views = $this->media->viewsFor([$event->mediaId]);
        $view = $views[$event->mediaId->toString()] ?? null;

        // Le media a disparu entre les deux sauts (une purge, par exemple). Il
        // n'y a alors rien a pousser : republier une vue absente afficherait un
        // emplacement vide chez tous les membres du fil.
        if (null === $view) {
            return;
        }

        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.media_ready',
            [
                'message_id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'media' => $view->toArray(),
            ],
            // AUCUN id : l'ULID du message est deja celui de `message.created`.
            // Le reutiliser mettrait deux evenements distincts sous un meme
            // Last-Event-ID (spec §6.2, decision de T3).
            null,
        );
    }
}
