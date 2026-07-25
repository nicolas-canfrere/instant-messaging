<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\UserId;

final class UserNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(UserId $id): self
    {
        return new self(sprintf('Utilisateur %s introuvable.', $id->toString()));
    }
}
