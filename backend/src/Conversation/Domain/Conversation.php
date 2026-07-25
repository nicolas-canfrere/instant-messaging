<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Racine d'agregat pour l'appartenance. Les messages ne sont PAS dans cet
 * agregat : la frontiere est choisie selon la taille de la transaction
 * d'ecriture, pas selon la logique de contenance.
 */
final class Conversation
{
    use RecordsEventsTrait;

    /** @param list<Member> $members */
    private function __construct(
        private readonly ConversationId $id,
        private readonly ConversationType $type,
        private readonly ?string $title,
        private readonly ?DirectKey $directKey,
        private readonly UserId $createdBy,
        private readonly \DateTimeImmutable $createdAt,
        private array $members,
    ) {
    }

    /** Les deux participants sont admin : il n'y a pas de hierarchie a deux. */
    public static function direct(
        ConversationId $id,
        UserId $initiator,
        UserId $peer,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            ConversationType::Direct,
            null,
            DirectKey::forPair($initiator, $peer),
            $initiator,
            $now,
            [
                new Member($initiator, MemberRole::Admin, $now),
                new Member($peer, MemberRole::Admin, $now),
            ],
        );
    }

    /** @param list<Member> $members */
    public static function reconstitute(
        ConversationId $id,
        ConversationType $type,
        ?string $title,
        ?DirectKey $directKey,
        UserId $createdBy,
        \DateTimeImmutable $createdAt,
        array $members,
    ): self {
        return new self($id, $type, $title, $directKey, $createdBy, $createdAt, $members);
    }

    public function id(): ConversationId
    {
        return $this->id;
    }

    public function type(): ConversationType
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function directKey(): ?DirectKey
    {
        return $this->directKey;
    }

    public function createdBy(): UserId
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<Member> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return list<UserId> */
    public function memberIds(): array
    {
        return array_map(static fn(Member $member): UserId => $member->userId, $this->members);
    }

    public function hasMember(UserId $userId): bool
    {
        foreach ($this->members as $member) {
            if ($member->userId->equals($userId)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(UserId $userId): bool
    {
        foreach ($this->members as $member) {
            if ($member->userId->equals($userId)) {
                return MemberRole::Admin === $member->role;
            }
        }

        return false;
    }
}
