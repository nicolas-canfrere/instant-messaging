<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\LastMessagePointerOutcome;
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
    ): LastMessagePointerOutcome {
        // Garde de monotonie. Ces mises a jour arrivent dans une SECONDE
        // transaction, dont l'ordre est independant de celui des messages :
        // sans elle, deux envois rapproches pourraient laisser l'apercu du plus
        // ancien. La comparaison de chaines suffit, l'ordre lexicographique
        // d'un ULID etant son ordre chronologique.
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversations
                SET last_message_id = :message_id,
                    last_message_at = :sent_at,
                    last_message_sender_id = :sender_id,
                    last_message_preview = :preview
                WHERE id = :conversation_id
                  AND (last_message_id IS NULL OR last_message_id < :message_id)
                SQL,
            [
                'message_id' => $messageId->toString(),
                'sent_at' => $sentAt->format(\DateTimeInterface::ATOM),
                'sender_id' => $senderId->toString(),
                'preview' => $preview,
                'conversation_id' => $conversationId->toString(),
            ],
        );

        if ($affected > 0) {
            return LastMessagePointerOutcome::Recorded;
        }

        // Zero ligne : soit la garde a mordu, soit la conversation n'existe
        // pas. Seul le second cas est anormal, d'ou cette verification — payee
        // uniquement dans le cas rare.
        $exists = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM conversations
                WHERE id = :conversation_id
                SQL,
            ['conversation_id' => $conversationId->toString()],
        );

        return false === $exists
            ? LastMessagePointerOutcome::ConversationMissing
            : LastMessagePointerOutcome::Superseded;
    }
}
