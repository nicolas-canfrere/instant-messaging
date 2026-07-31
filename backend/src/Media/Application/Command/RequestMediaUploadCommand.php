<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaMimeType;
use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/** L'identifiant est fourni par l'appelant, comme pour SendMessageCommand. */
final readonly class RequestMediaUploadCommand implements CommandInterface
{
    public function __construct(
        public MediaId $mediaId,
        public UserId $ownerId,
        public MediaMimeType $declaredMimeType,
        public int $declaredSize,
    ) {
    }
}
