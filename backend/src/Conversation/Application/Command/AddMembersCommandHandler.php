<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AddMembersCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddMembersCommand $command): void
    {
        $conversation = $this->conversations->ofId($command->conversationId);
        $now = $this->clock->now();

        foreach ($command->userIds as $userId) {
            $conversation->addMember($userId, $now);
        }

        $this->conversations->save($conversation);

        $this->logger->notice('{added_count} membres ajoutes a {conversation_id}', [
            'added_count' => count($command->userIds),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
