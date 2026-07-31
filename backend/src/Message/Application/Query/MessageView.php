<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Media\Application\Contract\MediaView;

/** DTO de lecture. Modifier cette forme est un changement cassant pour le front. */
final readonly class MessageView
{
    /**
     * @param list<MediaView> $media dans l'ordre d'affichage, vide pour un message texte-seul
     */
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        /** `null` veut dire supprime pour tous : il n'y a plus de charge utile. */
        public ?string $content,
        public string $clientMessageId,
        public string $createdAt,
        public ?string $editedAt,
        public ?string $deletedAt,
        public array $media,
    ) {
    }

    /**
     * `mixed` et non plus `string|null` : `media` y met des tableaux. C'est le
     * prix d'un DTO qui porte autre chose que des scalaires, et PHPStan `max`
     * refuserait l'ancienne annotation.
     *
     * @return array<string, mixed>
     */
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
            'edited_at' => $this->editedAt,
            'deleted_at' => $this->deletedAt,
            'media' => array_map(static fn(MediaView $view): array => $view->toArray(), $this->media),
        ];
    }
}
