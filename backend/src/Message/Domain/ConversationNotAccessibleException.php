<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\ConversationId;

/**
 * 404 et non 403 : confirmer l'existence du fil a un non-membre donnerait un
 * oracle d'enumeration.
 */
final class ConversationNotAccessibleException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(ConversationId $id): self
    {
        return new self(sprintf('Conversation %s introuvable.', $id->toString()));
    }
}
