<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\MediaId;

/** @implements QueryInterface<UploadTicket> */
final readonly class GetUploadTicketQuery implements QueryInterface
{
    public function __construct(public MediaId $mediaId)
    {
    }
}
