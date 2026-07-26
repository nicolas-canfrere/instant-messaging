<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class LeaveConversationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LeaveConversationCommand $command): void
    {
        $conversation = $this->conversations->ofId($command->conversationId);
        $conversation->leave($command->userId);

        $this->conversations->save($conversation);

        // `notice` : evenement metier normal mais significatif — c'est ce qui
        // explique, plus tard, pourquoi quelqu'un ne recoit plus rien d'un fil.
        $this->logger->notice('Membre {user_id} a quitte {conversation_id}', [
            'user_id' => $command->userId->toString(),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
