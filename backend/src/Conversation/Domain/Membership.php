<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * L'etat de lecture d'un membre. Un watermark est une propriete de
 * l'APPARTENANCE — « ce membre a lu jusqu'a X » — au meme titre que son role,
 * pas une entite avec sa propre identite.
 *
 * L'invariant de la tranche : un watermark ne recule JAMAIS. Il est enonce ici,
 * en PHP lisible et testable, et garanti une seconde fois par le `WHERE` du
 * repository — celui qui tranche sous concurrence est celui qui touche la base.
 *
 * Les curseurs sont des `string` et non des MessageId : ils designent une
 * position dans un ordre, pas une reference a une ligne existante. Le tri
 * lexicographique des ULID EST le tri chronologique — c'est la propriete qui a
 * justifie leur choix, et elle sert ici directement.
 */
final class Membership
{
    use RecordsEventsTrait;

    private bool $advanced = false;

    private function __construct(
        private readonly ConversationId $conversationId,
        private readonly UserId $userId,
        private ?string $lastDeliveredMessageId,
        private ?string $lastReadMessageId,
    ) {
    }

    public static function reconstitute(
        ConversationId $conversationId,
        UserId $userId,
        ?string $lastDeliveredMessageId,
        ?string $lastReadMessageId,
    ): self {
        return new self($conversationId, $userId, $lastDeliveredMessageId, $lastReadMessageId);
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function lastDeliveredMessageId(): ?string
    {
        return $this->lastDeliveredMessageId;
    }

    public function lastReadMessageId(): ?string
    {
        return $this->lastReadMessageId;
    }

    public function advanceDeliveredTo(?string $watermark): void
    {
        if (!self::movesForward($this->lastDeliveredMessageId, $watermark)) {
            return;
        }

        $this->lastDeliveredMessageId = $watermark;
        $this->markAdvanced();
    }

    public function advanceReadTo(?string $watermark): void
    {
        if (!self::movesForward($this->lastReadMessageId, $watermark)) {
            return;
        }

        $this->lastReadMessageId = $watermark;
        $this->markAdvanced();
    }

    /**
     * Un seul evenement, meme si les deux curseurs bougent : il porte de toute
     * facon l'etat complet. En emettre deux ferait publier deux fois la meme
     * information sur le hub.
     */
    private function markAdvanced(): void
    {
        if ($this->advanced) {
            $this->replaceRecordedEvent();

            return;
        }

        $this->advanced = true;
        $this->recordEvent($this->toEvent());
    }

    private function replaceRecordedEvent(): void
    {
        $this->releaseEvents();
        $this->recordEvent($this->toEvent());
    }

    private function toEvent(): ReceiptWatermarkAdvanced
    {
        return new ReceiptWatermarkAdvanced(
            $this->conversationId,
            $this->userId,
            $this->lastDeliveredMessageId,
            $this->lastReadMessageId,
        );
    }

    /** `null` n'est pas une demande de recul : c'est « ce curseur ne bouge pas ». */
    private static function movesForward(?string $current, ?string $candidate): bool
    {
        if (null === $candidate) {
            return false;
        }

        return null === $current || strcmp($candidate, $current) > 0;
    }
}
