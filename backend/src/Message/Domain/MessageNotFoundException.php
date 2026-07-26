<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

final class MessageNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function forClientKey(ClientMessageId $clientMessageId): self
    {
        return new self(sprintf('Aucun message pour la cle client %s.', $clientMessageId->toString()));
    }

    public static function inConversation(ConversationId $conversationId, MessageId $messageId): self
    {
        return new self(sprintf(
            'Message %s introuvable dans la conversation %s.',
            $messageId->toString(),
            $conversationId->toString(),
        ));
    }
}
