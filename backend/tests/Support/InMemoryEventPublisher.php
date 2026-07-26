<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;

/** Permet d'assertionner topic ET charge utile sans lever de hub Mercure en CI. */
final class InMemoryEventPublisher implements EventPublisherInterface
{
    /** @var list<array{topic: string, type: string, payload: array<string, mixed>, id: string|null}> */
    private array $published = [];

    public function publish(Topic $topic, string $eventType, array $payload, ?string $eventId = null): void
    {
        $this->published[] = [
            'topic' => $topic->toString(),
            'type' => $eventType,
            'payload' => $payload,
            'id' => $eventId,
        ];
    }

    /** @return list<array{topic: string, type: string, payload: array<string, mixed>, id: string|null}> */
    public function published(): array
    {
        return $this->published;
    }
}
