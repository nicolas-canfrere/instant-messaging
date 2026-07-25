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
    use ClassifiesBusFailuresTrait;

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

            $context = [
                'message_class' => $envelope->getMessage()::class,
                'exception' => $throwable,
            ];

            if ($this->isExpectedOutcome($throwable)) {
                $this->logger->warning('Transaction annulee sur refus metier ({message_class})', $context);
            } else {
                $this->logger->error('Transaction annulee, aucun evenement publie ({message_class})', $context);
            }

            throw $throwable;
        }

        foreach ($this->collector->release() as $event) {
            $this->logger->debug('Publication d\'un domain event apres commit ({event_class})', [
                'event_class' => $event::class,
            ]);

            // La transaction est deja commitee : l'ecriture a REUSSI. Laisser
            // remonter l'echec d'une reaction transformerait ce succes en 500,
            // et le client croirait devoir reessayer une operation deja faite.
            //
            // Chaque evenement est isole des autres : un hub injoignable ne doit
            // pas empecher la mise a jour du pointeur de conversation.
            //
            // L'abonne qui echoue a deja loggue au niveau qui lui convient —
            // `alert` pour le hub Mercure, par exemple. On ne re-loggue donc pas
            // l'exception ici, on constate seulement que la reaction n'a pas eu
            // lieu (« une erreur se loggue une seule fois »).
            try {
                $this->eventBus->dispatch($event);
            } catch (\Throwable $throwable) {
                $this->logger->error('Reaction a {event_class} en echec apres commit', [
                    'event_class' => $event::class,
                    'message_class' => $envelope->getMessage()::class,
                    'exception' => $throwable,
                ]);
            }
        }

        return $result;
    }
}
