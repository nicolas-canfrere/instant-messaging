<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Event\DomainEventInterface;

/**
 * Rassemble les evenements de tous les agregats touches par une meme commande,
 * pour que le middleware transactionnel les publie d'un seul tenant apres commit.
 */
final class InMemoryDomainEventCollector implements DomainEventCollectorInterface
{
    /** @var list<DomainEventInterface> */
    private array $events = [];

    public function collect(DomainEventInterface ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    public function release(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
