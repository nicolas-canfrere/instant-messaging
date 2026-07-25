<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Application\LastMessagePointerOutcome;
use App\Conversation\Application\LastMessagePointerWriterInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RecordLastMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private LastMessagePointerWriterInterface $pointer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordLastMessageCommand $command): void
    {
        $outcome = $this->pointer->record(
            $command->conversationId,
            $command->messageId,
            $command->senderId,
            $command->sentAt,
            $command->preview,
        );

        $context = [
            'conversation_id' => $command->conversationId->toString(),
            'message_id' => $command->messageId->toString(),
        ];

        match ($outcome) {
            LastMessagePointerOutcome::Recorded => null,

            // Course benigne entre deux envois : l'apercu affiche deja un
            // message plus recent, il n'y a rien a corriger.
            LastMessagePointerOutcome::Superseded => $this->logger->debug(
                'Pointeur deja plus recent que {message_id}, mise a jour ignoree',
                $context,
            ),

            // Anormal, mais non bloquant : le message est deja persiste, seul
            // l'apercu de la liste restera perime.
            LastMessagePointerOutcome::ConversationMissing => $this->logger->error(
                'Pointeur impossible a mettre a jour : conversation {conversation_id} absente',
                $context,
            ),
        };
    }
}
