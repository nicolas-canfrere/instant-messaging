<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Application\LastMessagePointerWriterInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RefreshLastMessagePreviewCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private LastMessagePointerWriterInterface $pointer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshLastMessagePreviewCommand $command): void
    {
        $refreshed = $this->pointer->refreshPreview(
            $command->conversationId,
            $command->messageId,
            $command->preview,
        );

        if ($refreshed) {
            return;
        }

        // Cas nominal et frequent : le message n'est plus le dernier du fil,
        // l'apercu ne le concerne donc pas. Rien a corriger.
        $this->logger->debug('Message {message_id} n\'est plus le pointeur : apercu inchange', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
