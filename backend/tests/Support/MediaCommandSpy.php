<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Compte les messages effectivement routes vers un transport, AVANT toute
 * serialisation. `InMemoryTransport::getSent()` ne convient pas ici : le
 * `ServicesResetter` de Symfony vide les services `kernel.reset` apres
 * CHAQUE requete HTTP — y compris avec `KernelBrowser::disableReboot()`, qui
 * n'empeche que la RECONSTRUCTION du conteneur, pas ce nettoyage entre deux
 * requetes d'un meme test. C'est ce qui rendait
 * `UploadFlowTest::testConfirmingTheUploadIsIdempotent` incapable de
 * distinguer un dispatch unique de deux : le transport etait deja vide au
 * moment de l'assertion.
 *
 * `SendMessageToTransportsEvent` est deliberement dispatche UNE SEULE fois
 * par message effectivement envoye a un transport (meme s'il en vise
 * plusieurs) : compter ses occurrences donne le nombre reel de dispatches,
 * sans dependre de la serialisation ni du cycle de vie de la requete.
 */
final class MediaCommandSpy
{
    /** @var list<object> */
    private array $sent = [];

    public function onSendMessageToTransports(SendMessageToTransportsEvent $event): void
    {
        $this->sent[] = $event->getEnvelope()->getMessage();
    }

    /** @return list<object> */
    public function sent(): array
    {
        return $this->sent;
    }
}
