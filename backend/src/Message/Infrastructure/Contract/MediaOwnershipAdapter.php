<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Contract;

use App\Media\Application\Contract\MediaOwnershipInterface;
use App\Message\Domain\Port\MediaOwnershipPortInterface;
use App\Shared\Domain\Identifier\UserId;

/**
 * Traduit le besoin d'ECRITURE de Message vers le contrat publie de Media.
 *
 * L'adaptateur existe parce que le consommateur, `SendMessageCommandHandler`,
 * vit en `Application` : sans lui, un use case nommerait un autre contexte. Le
 * cote LECTURE n'a pas cet adaptateur — ses consommateurs sont deja en
 * `Infrastructure`, ou nommer `MediaFinderInterface` ne coute rien.
 */
final readonly class MediaOwnershipAdapter implements MediaOwnershipPortInterface
{
    public function __construct(private MediaOwnershipInterface $ownership)
    {
    }

    public function assertOwnedBy(array $mediaIds, UserId $ownerId): void
    {
        $this->ownership->assertOwnedBy($mediaIds, $ownerId);
    }
}
