<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Identifier\UserId;

interface UserRepositoryInterface
{
    /** @throws UserNotFoundException */
    public function ofId(UserId $id): User;

    public function ofUsername(string $username): ?User;

    /** @return list<User> */
    public function all(): array;
}
