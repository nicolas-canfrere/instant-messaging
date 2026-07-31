<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\DomainEventDispatcherInterface;
use App\Shared\Domain\Event\DomainEventInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `event.bus` a `allow_no_handlers: true` : un evenement que personne n'ecoute
 * ne fait pas echouer l'abonne qui l'a emis.
 */
final readonly class DomainEventDispatcher implements DomainEventDispatcherInterface
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
