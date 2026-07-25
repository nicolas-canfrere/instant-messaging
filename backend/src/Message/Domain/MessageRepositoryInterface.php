<?php

declare(strict_types=1);

namespace App\Message\Domain;

interface MessageRepositoryInterface
{
    /**
     * Insere le message, sauf si la cle (sender_id, client_message_id) existe.
     *
     * @return Message|null null si le message vient d'etre cree ; le message
     *                      deja present en cas de rejeu
     */
    public function insertIfAbsent(Message $message): ?Message;
}
