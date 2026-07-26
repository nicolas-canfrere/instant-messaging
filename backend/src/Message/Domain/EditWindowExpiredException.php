<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;

/**
 * Supprimer tardivement reste legitime : le regret n'a pas de date de
 * peremption, et le resultat est un tombstone visible de tous, donc honnete.
 * Editer tardivement REECRIT l'histoire d'une conversation deja lue, sans que
 * rien ne dise aux destinataires ce que le message disait. D'ou l'asymetrie.
 */
final class EditWindowExpiredException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function create(): self
    {
        return new self(sprintf(
            'Un message n\'est modifiable que dans les %d minutes suivant son envoi.',
            intdiv(Message::EDIT_WINDOW_SECONDS, 60),
        ));
    }

    public function problemSlug(): string
    {
        return 'edit-window-expired';
    }

    public function problemTitle(): string
    {
        return 'Ce message n\'est plus modifiable';
    }
}
