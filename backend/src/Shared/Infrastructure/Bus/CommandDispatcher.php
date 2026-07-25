<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\CommandInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Une commande ne rend rien. Pour connaitre l'effet d'une ecriture, on pose
 * ensuite une question au `query.bus` — c'est la separation CQS, pas une gene.
 *
 * Aucune verification « aucun handler » ici : le bus leve deja
 * NoHandlerForMessageException de lui-meme.
 */
final readonly class CommandDispatcher
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function dispatch(CommandInterface $command): void
    {
        $this->commandBus->dispatch($command);
    }
}
