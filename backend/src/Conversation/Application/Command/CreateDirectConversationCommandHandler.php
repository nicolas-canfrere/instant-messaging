<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Conversation\Domain\DirectKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\IdGeneratorInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateDirectConversationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private IdGeneratorInterface $idGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ne rend rien : l'appelant qui veut l'identifiant pose ensuite
     * GetDirectConversationIdQuery. L'operation est idempotente — rouvrir un
     * direct deja ouvert n'est pas une erreur, on ne fait simplement rien.
     */
    public function __invoke(CreateDirectConversationCommand $command): void
    {
        $key = DirectKey::forPair($command->initiator, $command->peer);

        if (null !== $this->conversations->ofDirectKey($key)) {
            $this->logger->info('Conversation directe deja existante entre {initiator} et {peer}', [
                'initiator' => $command->initiator->toString(),
                'peer' => $command->peer->toString(),
            ]);

            return;
        }

        $conversation = Conversation::direct(
            ConversationId::fromString($this->idGenerator->generate()),
            $command->initiator,
            $command->peer,
            $this->clock->now(),
        );

        $this->conversations->save($conversation);

        $this->logger->notice('Conversation directe {conversation_id} creee', [
            'conversation_id' => $conversation->id()->toString(),
            'initiator' => $command->initiator->toString(),
        ]);
    }
}
