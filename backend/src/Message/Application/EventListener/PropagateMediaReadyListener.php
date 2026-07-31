<?php

declare(strict_types=1);

namespace App\Message\Application\EventListener;

use App\Message\Application\Query\MessagesCarryingMediaReaderInterface;
use App\Shared\Application\Bus\DomainEventDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MediaWasProcessed;
use App\Shared\Domain\Event\MessageMediaBecameReady;

/**
 * Le saut du milieu de la choregraphie. Media a publie « ces octets sont
 * traites » sans rien savoir des messages ; Message seul peut dire QUELS
 * messages portent ce media et dans QUEL fil. Il republie donc un fait a lui,
 * que Realtime saura pousser.
 *
 * Media n'a jamais appele Message, et Message n'appelle pas Realtime : chacun
 * ne fait que constater le fait du precedent (ADR 0001).
 */
final readonly class PropagateMediaReadyListener implements DomainEventListenerInterface
{
    public function __construct(
        private MessagesCarryingMediaReaderInterface $carriers,
        private DomainEventDispatcherInterface $events,
    ) {
    }

    public function __invoke(MediaWasProcessed $event): void
    {
        // Aucun `if`. Quand le traitement se termine AVANT l'envoi — cas
        // nominal, le worker peut etre plus rapide que l'utilisateur — aucun
        // message ne porte encore ce media : la requete ne rend rien et la
        // boucle ne tourne pas. Le comportement correct tombe de la requete,
        // il n'est pas une branche a maintenir (spec §3.5).
        //
        // Le statut de `$event` n'est pas relu ici : un refus se propage
        // exactement comme une reussite, sinon le message porteur resterait
        // « en cours… » pour toujours.
        foreach ($this->carriers->carrying($event->mediaId) as $carrier) {
            $this->events->dispatch(new MessageMediaBecameReady(
                $carrier['messageId'],
                $carrier['conversationId'],
                $event->mediaId,
            ));
        }
    }
}
