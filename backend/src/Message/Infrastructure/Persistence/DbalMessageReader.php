<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Application\Query\MessageReaderInterface;
use App\Message\Domain\ClientMessageId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMessageReader implements MessageReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function idByClientKey(UserId $senderId, ClientMessageId $clientMessageId): ?MessageId
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM messages
                WHERE sender_id = :sender_id
                  AND client_message_id = :client_message_id
                SQL,
            [
                'sender_id' => $senderId->toString(),
                'client_message_id' => $clientMessageId->toString(),
            ],
        );

        return is_string($id) ? MessageId::fromString($id) : null;
    }
}
