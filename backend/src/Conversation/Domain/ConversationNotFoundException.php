<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\ConversationId;

final class ConversationNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(ConversationId $id): self
    {
        return new self(sprintf('Conversation %s introuvable.', $id->toString()));
    }
}
