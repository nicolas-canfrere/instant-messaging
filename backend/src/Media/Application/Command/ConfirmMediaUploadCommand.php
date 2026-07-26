<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

final readonly class ConfirmMediaUploadCommand implements CommandInterface
{
    public function __construct(
        public MediaId $mediaId,
        public UserId $confirmedBy,
    ) {
    }
}
