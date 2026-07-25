<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\LastMessagePointerWriterInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalLastMessagePointerWriter implements LastMessagePointerWriterInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(
        ConversationId $conversationId,
        MessageId $messageId,
        UserId $senderId,
        \DateTimeImmutable $sentAt,
        string $preview,
    ): bool {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversations
                SET last_message_id = :message_id,
                    last_message_at = :sent_at,
                    last_message_sender_id = :sender_id,
                    last_message_preview = :preview
                WHERE id = :conversation_id
                SQL,
            [
                'message_id' => $messageId->toString(),
                'sent_at' => $sentAt->format(\DateTimeInterface::ATOM),
                'sender_id' => $senderId->toString(),
                'preview' => $preview,
                'conversation_id' => $conversationId->toString(),
            ],
        );

        return $affected > 0;
    }
}
