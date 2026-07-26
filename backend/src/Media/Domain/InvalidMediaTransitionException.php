<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Garde d'etat interne. AUCUNE entree utilisateur ne doit pouvoir l'atteindre
 * — les routes passent par des transitions idempotentes. Elle n'implemente
 * donc pas d'interface de traduction HTTP : si elle sort, c'est un 500, et
 * c'est correct.
 */
final class InvalidMediaTransitionException extends \LogicException
{
    public static function from(MediaStatus $from, MediaStatus $to): self
    {
        return new self(sprintf('Transition interdite de %s vers %s.', $from->value, $to->value));
    }
}
