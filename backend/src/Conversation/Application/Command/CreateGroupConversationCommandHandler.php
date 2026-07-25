<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateGroupConversationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateGroupConversationCommand $command): void
    {
        $conversation = Conversation::group(
            $command->conversationId,
            $command->title,
            $command->creator,
            $command->members,
            $this->clock->now(),
        );

        $this->conversations->save($conversation);

        $this->logger->notice('Groupe {conversation_id} cree avec {member_count} membres', [
            'conversation_id' => $conversation->id()->toString(),
            'member_count' => count($conversation->memberIds()),
            'creator' => $command->creator->toString(),
        ]);
    }
}
