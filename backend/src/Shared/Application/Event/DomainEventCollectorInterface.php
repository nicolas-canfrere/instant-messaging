<?php

declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\DomainEventInterface;

interface DomainEventCollectorInterface
{
    public function collect(DomainEventInterface ...$events): void;

    /** @return list<DomainEventInterface> vide le collecteur en meme temps */
    public function release(): array;

    public function clear(): void;
}
