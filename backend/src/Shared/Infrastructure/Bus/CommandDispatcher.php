<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Deballe le resultat du handler. Sans cela, chaque controleur repeterait la
 * gymnastique des stamps Messenger — et pourrait se tromper de bus.
 */
final readonly class CommandDispatcher
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function dispatch(object $command): mixed
    {
        $envelope = $this->commandBus->dispatch($command);
        $stamp = $envelope->last(HandledStamp::class);

        if (!$stamp instanceof HandledStamp) {
            throw new LogicException(sprintf('Aucun handler n\'a traite %s.', $command::class));
        }

        return $stamp->getResult();
    }
}
