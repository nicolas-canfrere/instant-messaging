<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
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
     * « Ce media est-il deja porte par un message ? » se pose sur
     * `message_media`, une table que Message possede : ce n'est PAS une
     * question pour Media (cf. MediaOwnershipPortInterface). L'unicite est
     * de toute facon garantie en base par `UNIQUE (media_id)` ; ce garde-fou
     * transforme une violation de contrainte imprevisible (500) en un
     * refus nomme (409).
     *
     * @param list<MediaId> $mediaIds
     *
     * @throws MediaAlreadyAttachedException un des medias est deja attache a un message
     */
    public function assertNoneAttached(array $mediaIds): void;

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
