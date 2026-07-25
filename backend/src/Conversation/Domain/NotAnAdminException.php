<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

/**
 * Traduit en 403, et seulement quand l'appartenance est deja etablie : un
 * non-membre recoit un 404, qui ne confirme pas l'existence de la conversation.
 */
final class NotAnAdminException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Seul un administrateur peut modifier la composition du groupe.');
    }
}
