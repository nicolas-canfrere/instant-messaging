<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Event\DomainEventCollectorInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Enveloppe chaque commande dans une transaction, puis publie les domain events
 * une fois le commit acquis. Publier avant le commit permettrait de pousser aux
 * clients un message qu'un rollback ferait ensuite disparaitre.
 */
final readonly class TransactionalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection,
        private DomainEventCollectorInterface $collector,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            /** @var Envelope $result */
            $result = $this->connection->transactional(
                static fn(): Envelope => $stack->next()->handle($envelope, $stack),
            );
        } catch (\Throwable $throwable) {
            $this->collector->clear();

            $this->logger->error('Transaction annulee, aucun evenement publie ({message_class})', [
                'message_class' => $envelope->getMessage()::class,
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        foreach ($this->collector->release() as $event) {
            $this->logger->debug('Publication d\'un domain event apres commit ({event_class})', [
                'event_class' => $event::class,
            ]);

            $this->eventBus->dispatch($event);
        }

        return $result;
    }
}
