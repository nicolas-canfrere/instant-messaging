<?php

declare(strict_types=1);

namespace App\Realtime\Domain;

interface EventPublisherInterface
{
    /**
     * @param non-empty-string     $eventType type logique de l'evenement, ex. "message.created"
     * @param array<string, mixed> $payload
     * @param non-empty-string     $eventId   identifiant de l'evenement SSE (ULID du message)
     */
    public function publish(Topic $topic, string $eventType, array $payload, string $eventId): void;
}
