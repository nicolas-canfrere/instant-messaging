<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercureEventPublisher implements EventPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(Topic $topic, string $eventType, array $payload, string $eventId): void
    {
        $data = json_encode(['type' => $eventType, 'payload' => $payload], \JSON_THROW_ON_ERROR);

        try {
            $this->hub->publish(new Update(
                topics: $topic->toString(),
                data: $data,
                // Seuls les abonnes dont le JWT autorise ce topic recevront la
                // mise a jour. Sans ce drapeau, le hub diffuserait a tous.
                private: true,
                id: $eventId,
                type: $eventType,
            ));
        } catch (\Throwable $throwable) {
            // Le hub est injoignable : plus aucun temps reel, l'application est
            // fonctionnellement cassee, d'ou le niveau `alert`.
            $this->logger->alert('Publication Mercure impossible sur {topic} ({event_type})', [
                'topic' => $topic->toString(),
                'event_type' => $eventType,
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        $this->logger->info('Evenement {event_type} publie sur {topic}', [
            'event_type' => $eventType,
            'topic' => $topic->toString(),
            'event_id' => $eventId,
        ]);
    }
}
