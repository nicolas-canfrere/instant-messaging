<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

/**
 * DTO de lecture : ne traverse jamais le domaine (CQS).
 *
 * Modifier cette forme est un changement cassant pour le front.
 */
final readonly class ConversationView
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $title,
        public ?string $lastMessageAt,
        public ?string $lastMessagePreview,
        public ?string $lastMessageSenderId,
        public int $unreadCount = 0,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'last_message_at' => $this->lastMessageAt,
            'last_message_preview' => $this->lastMessagePreview,
            'last_message_sender_id' => $this->lastMessageSenderId,
            'unread_count' => $this->unreadCount,
        ];
    }
}
