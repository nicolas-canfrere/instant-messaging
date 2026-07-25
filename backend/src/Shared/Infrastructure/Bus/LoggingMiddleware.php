<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Couverture de log uniforme de tous les messages de bus : aucun handler n'a
 * a repeter ces trois lignes, et aucun ne peut etre oublie.
 */
final readonly class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = $envelope->getMessage()::class;
        $startedAt = $this->clock->now();

        $this->logger->debug('Traitement de {message_class}', ['message_class' => $messageClass]);

        try {
            $result = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $throwable) {
            $this->logger->error('Echec de {message_class} apres {duration_ms} ms', [
                'message_class' => $messageClass,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        $this->logger->info('{message_class} traite en {duration_ms} ms', [
            'message_class' => $messageClass,
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);

        return $result;
    }

    private function elapsedMs(\DateTimeImmutable $startedAt): int
    {
        return (int) round(
            ((float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u')) * 1000,
        );
    }
}
