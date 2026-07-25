<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Exception\NotFoundExceptionInterface;

final class DirectConversationNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function forKey(DirectKey $key): self
    {
        return new self(sprintf('Aucune conversation directe pour la cle %s.', $key->toString()));
    }
}
