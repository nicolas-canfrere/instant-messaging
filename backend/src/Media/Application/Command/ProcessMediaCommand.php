<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;

/**
 * Demande de traitement asynchrone d'un media confirme. Dispatchee par
 * ConfirmMediaUploadCommandHandler apres la transition Pending -> Processing ;
 * consommee par le worker (le handler arrive avec lui).
 */
final readonly class ProcessMediaCommand implements CommandInterface
{
    public function __construct(public MediaId $mediaId)
    {
    }
}
