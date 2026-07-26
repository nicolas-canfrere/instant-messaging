<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

interface MessageRepositoryInterface
{
    /**
     * Insere le message, sauf si la cle (sender_id, client_message_id) existe.
     *
     * @return Message|null null si le message vient d'etre cree ; le message
     *                      deja present en cas de rejeu
     */
    public function insertIfAbsent(Message $message): ?Message;

    /**
     * Charge un message DANS SA CONVERSATION.
     *
     * Les deux identifiants sont exiges : un message demande dans le mauvais fil
     * est introuvable, point. La regle anti-oracle est ainsi portee par la
     * signature du port, pas par la vigilance de l'appelant.
     *
     * @throws MessageNotFoundException
     */
    public function ofId(ConversationId $conversationId, MessageId $messageId): Message;

    /** Persiste les colonnes mutables et collecte les evenements enregistres. */
    public function save(Message $message): void;
}
