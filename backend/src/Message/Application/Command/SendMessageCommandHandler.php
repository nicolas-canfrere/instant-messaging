<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ClientMessageIdReusedException;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\Message;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class SendMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ConversationMembershipInterface $membership,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendMessageCommand $command): void
    {
        // Le controle vit ici, DANS la transaction de la commande. Le faire
        // seulement dans le controleur laisserait une fenetre entre la
        // verification et l'insertion, pendant laquelle l'expediteur peut avoir
        // ete retire du fil.
        //
        // Message passe par le contrat publie de Conversation, jamais par ses
        // internes ni par sa table.
        if (!$this->membership->isMember($command->conversationId, $command->senderId)) {
            throw ConversationNotAccessibleException::withId($command->conversationId);
        }

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

        // La cle d'idempotence est unique par EXPEDITEUR, pas par conversation.
        // Rendre le message d'un autre fil serait une reponse silencieusement
        // fausse : on refuse bruyamment.
        if (!$existing->conversationId()->equals($command->conversationId)) {
            $this->logger->warning(
                'Cle client {client_message_id} reutilisee dans une autre conversation',
                [
                    'client_message_id' => $command->clientMessageId->toString(),
                    'sender_id' => $command->senderId->toString(),
                    'attempted_conversation_id' => $command->conversationId->toString(),
                    'existing_conversation_id' => $existing->conversationId()->toString(),
                ],
            );

            throw ClientMessageIdReusedException::inAnotherConversation($command->clientMessageId);
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
