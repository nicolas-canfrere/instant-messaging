<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ConversationMembershipInterface $membership,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteMessageCommand $command): void
    {
        // Le controle vit ici, DANS la transaction, comme pour l'envoi. Message
        // passe par le contrat publie de Conversation, jamais par sa table.
        if (!$this->membership->isMember($command->conversationId, $command->actorId)) {
            throw ConversationNotAccessibleException::withId($command->conversationId);
        }

        $message = $this->messages->ofId($command->conversationId, $command->messageId);

        $message->deleteForEveryone($command->actorId, $this->clock->now());

        $this->messages->save($message);

        $this->logger->notice('Message {message_id} supprime pour tous', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
            'actor_id' => $command->actorId->toString(),
        ]);
    }
}
