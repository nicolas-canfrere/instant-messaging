<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

/** DTO de lecture. Modifier cette forme est un changement cassant pour le front. */
final readonly class ConversationDetailView
{
    /** @param list<array{user_id: string, role: string}> $members */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $title,
        public array $members,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'members' => $this->members,
        ];
    }
}
