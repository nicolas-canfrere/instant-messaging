<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Port du bus de commandes, pour qu'un abonne d'evenement puisse reagir avec sa
 * propre commande sans qu'Application connaisse Messenger.
 */
interface CommandDispatcherInterface
{
    public function dispatch(CommandInterface $command): void;
}
