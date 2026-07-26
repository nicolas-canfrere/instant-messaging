<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\UserId;

/**
 * Un groupe ne perd jamais son dernier administrateur.
 *
 * Sans cette regle, l'invariant porte par AdminCannotLeaveException se
 * contourne par la porte d'a cote : l'unique admin s'exclut lui-meme par la
 * route de retrait, sur laquelle il a evidemment les droits. Le groupe se
 * retrouve alors sans personne pour en gerer la composition — et comme
 * `addMember()` attribue toujours le role de simple membre, plus aucun admin
 * ne peut y etre nomme.
 *
 * Traduite en 409 comme AdminCannotLeaveException, et pour la meme raison : le
 * role ne manque pas, c'est l'etat du groupe qui rend l'operation impossible.
 */
final class LastAdminCannotBeRemovedException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function forUser(UserId $userId): self
    {
        return new self(sprintf(
            'L\'administrateur %s est le dernier du groupe et ne peut pas en etre retire.',
            $userId->toString(),
        ));
    }

    public function problemSlug(): string
    {
        return 'last-admin-cannot-be-removed';
    }

    public function problemTitle(): string
    {
        return 'Le dernier administrateur ne peut pas etre retire';
    }
}
