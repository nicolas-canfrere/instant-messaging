<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

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
        $updated = $this->pointer->record(
            $command->conversationId,
            $command->messageId,
            $command->senderId,
            $command->sentAt,
            $command->preview,
        );

        if ($updated) {
            return;
        }

        // Anormal, mais non bloquant : le message est deja persiste, seul
        // l'apercu de la liste restera perime jusqu'au message suivant.
        $this->logger->error('Pointeur de dernier message impossible a mettre a jour ({conversation_id})', [
            'conversation_id' => $command->conversationId->toString(),
            'message_id' => $command->messageId->toString(),
        ]);
    }
}
