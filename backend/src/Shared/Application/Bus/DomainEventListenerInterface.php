<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marqueur, taggue automatiquement sur `event.bus` via `_instanceof`.
 * Ces abonnes ne tournent qu'apres le commit de la transaction qui a produit
 * l'evenement.
 */
interface DomainEventListenerInterface
{
}
