<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Contract;

use App\Media\Application\Contract\MediaOwnershipInterface;
use App\Message\Domain\Port\MediaOwnershipPortInterface;
use App\Shared\Domain\Identifier\UserId;

/** Le SEUL endroit de Message qui nomme le contexte Media. */
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
