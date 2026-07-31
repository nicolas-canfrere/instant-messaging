<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Media\Application\Contract\MediaFinderInterface;
use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasSent;
use App\Shared\Domain\Identifier\MediaId;

/** Un seul publish par message : le hub assure le fan-out, le metier reste en O(1). */
final readonly class PublishMessageWasSentListener implements DomainEventListenerInterface
{
    public function __construct(
        private MediaFinderInterface $media,
        private EventPublisherInterface $publisher,
    ) {
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
                // Indispensable a la premiere passe de dedup du front : l'echo
                // SSE le double, l'envoi optimiste porte deja cette cle. Sans
                // elle, l'expediteur verrait son propre message deux fois.
                'client_message_id' => $event->clientMessageId,
                'created_at' => $event->createdAt->format(\DateTimeInterface::ATOM),
                'media' => $this->mediaPayload($event->mediaIds),
            ],
            // L'id de l'evenement SSE est l'ULID du message : Last-Event-ID
            // deviendra exploitable en T2 sans changer ce format.
            $event->messageId->toString(),
        );
    }

    /**
     * Les vues des medias, resignees ici comme dans
     * `PublishMessageMediaBecameReadyListener`.
     *
     * Sans elles, un destinataire recevrait une bulle qu'il ne saurait pas
     * remplir : il ignorerait jusqu'a l'EXISTENCE des images, et devrait
     * redemander la page a l'aveugle a chaque message pour le decouvrir.
     *
     * Ce n'est PAS un doublon de `message.media_ready`, qui reste necessaire
     * pour les traitements lents. C'est meme l'inverse qui est frequent : le
     * worker met une cinquantaine de millisecondes la ou l'utilisateur met
     * plusieurs secondes a ecrire, donc le media part souvent deja `ready` et
     * l'image s'affiche d'emblee, sans qu'aucun second evenement ne soit publie.
     *
     * @param list<string> $mediaIds dans l'ordre d'affichage
     *
     * @return list<array<string, scalar|null>>
     */
    private function mediaPayload(array $mediaIds): array
    {
        if ([] === $mediaIds) {
            return [];
        }

        $views = $this->media->viewsFor(array_map(
            static fn(string $mediaId): MediaId => MediaId::fromString($mediaId),
            $mediaIds,
        ));

        // Parcourt `$mediaIds`, pas `$views` : c'est l'ordre d'affichage, et
        // `viewsFor()` rend un tableau indexe par ULID, sans ordre garanti. Un
        // media introuvable est omis plutot que rendu a moitie.
        $payload = [];
        foreach ($mediaIds as $mediaId) {
            $view = $views[$mediaId] ?? null;

            if (null !== $view) {
                $payload[] = $view->toArray();
            }
        }

        return $payload;
    }
}
