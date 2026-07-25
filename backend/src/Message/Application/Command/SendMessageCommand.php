<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\MessageContent;
use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * L'identifiant serveur est fourni par l'appelant. Il sert aussi de temoin :
 * si la relecture par (sender_id, client_message_id) rend cet identifiant, le
 * message vient d'etre cree (201) ; s'il en rend un autre, c'est un rejeu (200).
 */
final readonly class SendMessageCommand implements CommandInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public MessageContent $content,
        public ClientMessageId $clientMessageId,
    ) {
    }
}
