<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RemoveMemberCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RemoveMemberCommand $command): void
    {
        $conversation = $this->conversations->ofId($command->conversationId);
        $conversation->removeMember($command->userId);

        $this->conversations->save($conversation);

        $this->logger->notice('Membre {user_id} retire de {conversation_id}', [
            'user_id' => $command->userId->toString(),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
