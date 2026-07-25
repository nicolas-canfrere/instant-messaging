<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Identifier\UserId;

/**
 * L'agregat ne porte ni e-mail ni hash de mot de passe : la tranche 1 n'a aucun
 * cas d'usage metier qui les manipule. Ils vivent en base et ne sont lus que par
 * l'adaptateur de securite.
 */
final readonly class User
{
    public function __construct(
        private UserId $id,
        private string $username,
        private string $displayName,
    ) {
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }
}
