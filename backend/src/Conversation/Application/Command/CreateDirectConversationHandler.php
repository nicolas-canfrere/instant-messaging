<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\IdGeneratorInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateDirectConversationHandler
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private IdGeneratorInterface $idGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateDirectConversation $command): ConversationId
    {
        $key = DirectKey::forPair($command->initiator, $command->peer);
        $existing = $this->conversations->ofDirectKey($key);

        // Rendre l'existante plutot que d'echouer : rouvrir un direct deja
        // ouvert est une demande legitime, pas une erreur.
        if (null !== $existing) {
            $this->logger->info('Conversation directe deja existante entre {initiator} et {peer}', [
                'initiator' => $command->initiator->toString(),
                'peer' => $command->peer->toString(),
                'conversation_id' => $existing->id()->toString(),
            ]);

            return $existing->id();
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

        return $conversation->id();
    }
}
