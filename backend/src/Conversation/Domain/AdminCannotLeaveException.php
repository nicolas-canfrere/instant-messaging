<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\UserId;

/**
 * Traduite en 409 et non en 403 : le role ne MANQUE pas, il est trop eleve.
 * L'appelant ne peut rien corriger dans sa requete — il doit changer l'etat de
 * la ressource en transferant l'administration. C'est exactement ce que le
 * marqueur Conflict decrit.
 */
final class AdminCannotLeaveException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function forUser(UserId $userId): self
    {
        return new self(sprintf(
            'L\'administrateur %s doit transferer ses droits avant de quitter le groupe.',
            $userId->toString(),
        ));
    }

    public function problemSlug(): string
    {
        return 'admin-cannot-leave';
    }

    public function problemTitle(): string
    {
        return 'Un administrateur ne peut pas quitter le groupe';
    }
}
