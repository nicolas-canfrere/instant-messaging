<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Event\MessageWasSent;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final class Message
{
    use RecordsEventsTrait;

    private function __construct(
        private readonly MessageId $id,
        private readonly ConversationId $conversationId,
        private readonly UserId $senderId,
        private ?MessageContent $content,
        private readonly ClientMessageId $clientMessageId,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $editedAt = null,
        private ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    public static function send(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        MessageContent $content,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $now,
    ): self {
        $message = new self($id, $conversationId, $senderId, $content, $clientMessageId, $now, null, null);
        $message->recordEvent(
            new MessageWasSent(
                $id,
                $conversationId,
                $senderId,
                $content->toString(),
                $clientMessageId->toString(),
                $now,
            ),
        );

        return $message;
    }

    /**
     * N'enregistre AUCUN evenement, et c'est le mecanisme central de
     * l'idempotence : un rejeu relit le message existant, donc ne republie
     * rien — sans le moindre `if`. Ne pas ajouter d'enregistrement ici.
     */
    public static function reconstitute(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        ?MessageContent $content,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $editedAt = null,
        ?\DateTimeImmutable $deletedAt = null,
    ): self {
        return new self($id, $conversationId, $senderId, $content, $clientMessageId, $createdAt, $editedAt, $deletedAt);
    }

    public function id(): MessageId
    {
        return $this->id;
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function senderId(): UserId
    {
        return $this->senderId;
    }

    public function content(): ?MessageContent
    {
        return $this->content;
    }

    public function clientMessageId(): ClientMessageId
    {
        return $this->clientMessageId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function editedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /** `content` nul et `deletedAt` non nul sont la meme information : un seul point la lit. */
    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}
