<?php

declare(strict_types=1);

namespace App\Conversation\Application;

/**
 * Distingue les deux raisons pour lesquelles un pointeur n'est pas mis a jour.
 * Sans cela, une course benigne entre deux messages serait journalisee au meme
 * niveau qu'une conversation manquante, qui elle est anormale.
 */
enum LastMessagePointerOutcome
{
    case Recorded;

    /** Un message PLUS RECENT a deja ete enregistre : normal en concurrence. */
    case Superseded;

    case ConversationMissing;
}
