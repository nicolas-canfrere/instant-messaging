<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;

final class MessageNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function forClientKey(ClientMessageId $clientMessageId): self
    {
        return new self(sprintf('Aucun message pour la cle client %s.', $clientMessageId->toString()));
    }
}
