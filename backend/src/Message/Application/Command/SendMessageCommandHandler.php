<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Message\Domain\Message;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class SendMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendMessageCommand $command): void
    {
        $message = Message::send(
            $command->messageId,
            $command->conversationId,
            $command->senderId,
            $command->content,
            $command->clientMessageId,
            $this->clock->now(),
        );

        $existing = $this->messages->insertIfAbsent($message);

        if (null === $existing) {
            // Jamais le contenu, meme tronque : seulement sa longueur.
            $this->logger->info('Message {message_id} envoye dans la conversation {conversation_id}', [
                'message_id' => $message->id()->toString(),
                'conversation_id' => $command->conversationId->toString(),
                'sender_id' => $command->senderId->toString(),
                'content_length' => mb_strlen($command->content->toString()),
            ]);

            return;
        }

        // Meme cle, contenu different : signe d'un bug ou d'un abus cote client.
        // Le premier message est conserve, et l'anomalie est signalee.
        if ($existing->content()->toString() !== $command->content->toString()) {
            $this->logger->warning(
                'Rejeu de {client_message_id} avec un contenu different : le premier message est conserve',
                [
                    'client_message_id' => $command->clientMessageId->toString(),
                    'message_id' => $existing->id()->toString(),
                    'sender_id' => $command->senderId->toString(),
                ],
            );
        }
    }
}
