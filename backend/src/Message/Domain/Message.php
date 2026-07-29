<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Event\MessageWasDeleted;
use App\Shared\Domain\Event\MessageWasEdited;
use App\Shared\Domain\Event\MessageWasSent;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final class Message
{
    use RecordsEventsTrait;

    /**
     * Quinze minutes. C'est une regle metier, pas un reglage d'exploitation :
     * elle vit donc dans l'agregat et non dans la configuration.
     */
    public const int EDIT_WINDOW_SECONDS = 900;

    /** @param list<MediaId> $mediaIds */
    private function __construct(
        private readonly MessageId $id,
        private readonly ConversationId $conversationId,
        private readonly UserId $senderId,
        private ?MessageContent $content,
        private array $mediaIds,
        private readonly ClientMessageId $clientMessageId,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $editedAt = null,
        private ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    /** @param list<MediaId> $mediaIds */
    public static function send(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        ?MessageContent $content,
        array $mediaIds,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $now,
    ): self {
        if (null === $content && [] === $mediaIds) {
            throw EmptyMessageException::create();
        }

        $message = new self($id, $conversationId, $senderId, $content, $mediaIds, $clientMessageId, $now, null, null);
        $message->recordEvent(
            new MessageWasSent(
                $id,
                $conversationId,
                $senderId,
                // MessageWasSent n'est PAS modifie : sa charge utile reste un
                // `string`, sans les medias. Un message image-seule y voyage
                // avec un contenu vide ; la charge utile media arrivera par la
                // tache 8, dans un evenement distinct.
                //
                // Consequence visible avant la tache 8 : Conversation ecoute cet
                // evenement pour son `last_message_preview`, et un message
                // image-seule y ecrit donc une chaine vide — l'ecran d'accueil
                // affiche une ligne blanche jusqu'a ce que la tache 8 y mette un
                // apercu ("📷 Photo" ou equivalent). Pas un bug de cette tache.
                $content?->toString() ?? '',
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
     *
     * @param list<MediaId> $mediaIds
     */
    public static function reconstitute(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        ?MessageContent $content,
        array $mediaIds,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $editedAt = null,
        ?\DateTimeImmutable $deletedAt = null,
    ): self {
        return new self($id, $conversationId, $senderId, $content, $mediaIds, $clientMessageId, $createdAt, $editedAt, $deletedAt);
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

    /** @return list<MediaId> */
    public function mediaIds(): array
    {
        return $this->mediaIds;
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

    /**
     * « Supprimer pour tous » : record soft, payload hard. L'enregistrement
     * reste — id, expediteur, instant, donc l'ordre et les watermarks qui le
     * designent — mais la charge utile est reellement effacee.
     *
     * Rejouer la suppression n'enregistre AUCUN evenement et conserve le premier
     * instant. C'est ce qui fait de DELETE une operation idempotente par
     * construction, sans condition dans le handler ni dans la couche HTTP.
     */
    public function deleteForEveryone(UserId $actor, \DateTimeImmutable $now): void
    {
        if (!$this->senderId->equals($actor)) {
            throw NotTheAuthorException::forMessage($this->id);
        }

        if ($this->isDeleted()) {
            return;
        }

        $this->content = null;
        // Les images partent avec le texte. Un media detache devient orphelin
        // et sera ramasse par la purge : les octets sont detruits, comme le
        // texte l'etait (spec §7.2).
        $this->mediaIds = [];
        $this->deletedAt = $now;

        $this->recordEvent(new MessageWasDeleted($this->id, $this->conversationId, $this->senderId, $now));
    }

    /**
     * Editer avec le contenu actuel n'enregistre AUCUN evenement — meme
     * mecanique que le rejeu d'envoi : rien d'enregistre, donc rien de republie,
     * sans un seul `if` dans le handler.
     *
     * L'ordre des gardes est significatif : auteur, puis supprime, puis contenu
     * inchange, puis fenetre.
     *  - la suppression passe AVANT le no-op parce qu'un tombstone a un `content`
     *    nul : la comparaison ne matcherait jamais, et editer un tombstone doit
     *    rendre 409 quelle que soit la charge utile envoyee ;
     *  - la fenetre passe APRES le no-op parce qu'un PATCH rejoue a l'identique
     *    ne change rien : le refuser en 403 punirait un reessai reseau pour une
     *    ecriture qui n'aurait de toute facon pas eu lieu. La fenetre protege
     *    une reecriture de l'histoire, pas une repetition sans effet.
     */
    public function edit(UserId $editor, MessageContent $content, \DateTimeImmutable $now): void
    {
        if (!$this->senderId->equals($editor)) {
            throw NotTheAuthorException::forMessage($this->id);
        }

        if ($this->isDeleted()) {
            throw MessageAlreadyDeletedException::forMessage($this->id);
        }

        if ($content->toString() === $this->content?->toString()) {
            return;
        }

        if ($now->getTimestamp() - $this->createdAt->getTimestamp() > self::EDIT_WINDOW_SECONDS) {
            throw EditWindowExpiredException::create();
        }

        $this->content = $content;
        $this->editedAt = $now;

        $this->recordEvent(new MessageWasEdited(
            $this->id,
            $this->conversationId,
            $this->senderId,
            $content->toString(),
            $now,
        ));
    }
}
