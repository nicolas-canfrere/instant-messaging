<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;

/**
 * Lit les colonnes d'authentification de `users`, que le domaine ne porte pas.
 *
 * L'agregat User n'expose ni e-mail ni hash : aucun cas d'usage metier ne les
 * manipule. UserRepositoryInterface ne peut donc pas servir ce besoin, et il ne
 * doit pas : c'est un port de domaine, la notion de hash n'y a pas sa place.
 * D'ou cette classe de lecture, en Infrastructure, nommee pour ce qu'elle fait.
 *
 * Elle reste dans Identity, le contexte proprietaire de la table.
 */
final readonly class UserCredentialsReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function byUsername(string $username): ?SecurityUser
    {
        // `id` et `username` sont NOT NULL en base, donc jamais vides.
        /** @var array{id: non-empty-string, username: non-empty-string, password_hash: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, username, password_hash
                FROM users
                WHERE username = :username
                SQL,
            ['username' => $username],
        );

        if (false === $row) {
            return null;
        }

        return new SecurityUser($row['id'], $row['username'], $row['password_hash']);
    }
}
