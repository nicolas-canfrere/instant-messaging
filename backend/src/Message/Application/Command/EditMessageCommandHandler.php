<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class EditMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ConversationMembershipInterface $membership,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EditMessageCommand $command): void
    {
        if (!$this->membership->isMember($command->conversationId, $command->editorId)) {
            throw ConversationNotAccessibleException::withId($command->conversationId);
        }

        $message = $this->messages->ofId($command->conversationId, $command->messageId);

        $message->edit($command->editorId, $command->content, $this->clock->now());

        $this->messages->save($message);

        // Jamais le contenu, meme tronque : seulement sa longueur.
        $this->logger->notice('Message {message_id} modifie', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
            'editor_id' => $command->editorId->toString(),
            'content_length' => mb_strlen($command->content->toString()),
        ]);
    }
}
