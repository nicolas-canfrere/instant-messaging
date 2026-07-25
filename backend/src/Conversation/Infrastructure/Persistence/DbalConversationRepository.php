<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationNotFoundException;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Conversation\Domain\DirectKey;
use App\Conversation\Domain\Member;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalConversationRepository implements ConversationRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private ConversationMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function save(Conversation $conversation): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO conversations (id, type, title, direct_key, created_by, created_at)
                VALUES (:id, :type, :title, :direct_key, :created_by, :created_at)
                ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title
                SQL,
            [
                'id' => $conversation->id()->toString(),
                'type' => $conversation->type()->value,
                'title' => $conversation->title(),
                'direct_key' => $conversation->directKey()?->toString(),
                'created_by' => $conversation->createdBy()->toString(),
                'created_at' => $conversation->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        $memberIds = array_map(
            static fn(Member $member): string => $member->userId->toString(),
            $conversation->members(),
        );

        // Les membres retires disparaissent, les nouveaux apparaissent : l'etat
        // en base reflete exactement l'agregat, sans change tracking implicite.
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM conversation_members
                WHERE conversation_id = :id
                  AND user_id NOT IN (:member_ids)
                SQL,
            ['id' => $conversation->id()->toString(), 'member_ids' => $memberIds],
            ['member_ids' => ArrayParameterType::STRING],
        );

        foreach ($conversation->members() as $member) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                    VALUES (:conversation_id, :user_id, :role, :joined_at)
                    ON CONFLICT (conversation_id, user_id) DO UPDATE SET role = EXCLUDED.role
                    SQL,
                [
                    'conversation_id' => $conversation->id()->toString(),
                    'user_id' => $member->userId->toString(),
                    'role' => $member->role->value,
                    'joined_at' => $member->joinedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        // Le collecteur les remettra au middleware transactionnel, qui les
        // publiera apres le commit — jamais avant.
        $this->collector->collect(...$conversation->releaseEvents());
    }

    public function ofId(ConversationId $id): Conversation
    {
        /** @var array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, type, title, direct_key, created_by, created_at
                FROM conversations
                WHERE id = :id
                SQL,
            ['id' => $id->toString()],
        );

        if (false === $row) {
            throw ConversationNotFoundException::withId($id);
        }

        return $this->mapper->fromRows($row, $this->memberRows($row['id']));
    }

    public function ofDirectKey(DirectKey $key): ?Conversation
    {
        /** @var array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, type, title, direct_key, created_by, created_at
                FROM conversations
                WHERE direct_key = :direct_key
                SQL,
            ['direct_key' => $key->toString()],
        );

        return false === $row ? null : $this->mapper->fromRows($row, $this->memberRows($row['id']));
    }

    /** @return list<array{user_id: string, role: string, joined_at: string}> */
    private function memberRows(string $conversationId): array
    {
        /** @var list<array{user_id: string, role: string, joined_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT user_id, role, joined_at
                FROM conversation_members
                WHERE conversation_id = :conversation_id
                ORDER BY joined_at ASC
                SQL,
            ['conversation_id' => $conversationId],
        );

        return $rows;
    }
}
