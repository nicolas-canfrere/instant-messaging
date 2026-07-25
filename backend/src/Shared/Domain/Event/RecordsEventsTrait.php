<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

trait RecordsEventsTrait
{
    /** @var list<DomainEventInterface> */
    private array $recordedEvents = [];

    /** @return list<DomainEventInterface> vide l'agregat en meme temps */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function recordEvent(DomainEventInterface $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
