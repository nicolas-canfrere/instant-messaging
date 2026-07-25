<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

/** DTO de lecture. Modifier cette forme est un changement cassant pour le front. */
final readonly class MessageView
{
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        public string $content,
        public string $clientMessageId,
        public string $createdAt,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'content' => $this->content,
            // Renvoye au client pour qu'il puisse reconcilier son message
            // optimiste avec celui que le serveur confirme.
            'client_message_id' => $this->clientMessageId,
            'created_at' => $this->createdAt,
        ];
    }
}
