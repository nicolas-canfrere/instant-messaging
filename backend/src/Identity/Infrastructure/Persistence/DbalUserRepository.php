<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\User;
use App\Identity\Domain\UserNotFoundException;
use App\Identity\Domain\UserRepositoryInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/**
 * Chaque requete est ecrite en entier, telle qu'elle part vers PostgreSQL.
 * Ni email ni password_hash dans les colonnes lues : le domaine ne les
 * manipule pas, seul l'adaptateur de securite les lit.
 */
final readonly class DbalUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private UserMapper $mapper,
    ) {
    }

    public function ofId(UserId $id): User
    {
        /** @var array{id: string, username: string, display_name: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, username, display_name
                FROM users
                WHERE id = :id
                SQL,
            ['id' => $id->toString()],
        );

        if (false === $row) {
            throw UserNotFoundException::withId($id);
        }

        return $this->mapper->fromRow($row);
    }

    public function ofUsername(string $username): ?User
    {
        /** @var array{id: string, username: string, display_name: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, username, display_name
                FROM users
                WHERE username = :username
                SQL,
            ['username' => $username],
        );

        return false === $row ? null : $this->mapper->fromRow($row);
    }

    public function all(): array
    {
        /** @var list<array{id: string, username: string, display_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, username, display_name
                FROM users
                ORDER BY display_name ASC
                SQL,
        );

        return array_map($this->mapper->fromRow(...), $rows);
    }
}
