<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\User;
use App\Shared\Domain\Identifier\UserId;

/**
 * Frontiere unique entre la ligne SQL brute et le domaine.
 * C'est ici que le type large rendu par DBAL devient un type precis (PHPStan max).
 */
final readonly class UserMapper
{
    /** @param array{id: string, username: string, display_name: string} $row */
    public function fromRow(array $row): User
    {
        return new User(
            UserId::fromString($row['id']),
            $row['username'],
            $row['display_name'],
        );
    }
}
