<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\MessageId;

final class MessageAlreadyDeletedException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function forMessage(MessageId $messageId): self
    {
        return new self(sprintf('Le message %s a ete supprime.', $messageId->toString()));
    }

    public function problemSlug(): string
    {
        return 'message-already-deleted';
    }

    public function problemTitle(): string
    {
        return 'Ce message a ete supprime';
    }
}
