<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Application\Bus\QueryHandlerInterface;
use App\Shared\Domain\Identifier\MessageId;

final readonly class FindMessageByClientKeyQueryHandler implements QueryHandlerInterface
{
    public function __construct(private MessageReaderInterface $messages)
    {
    }

    public function __invoke(FindMessageByClientKeyQuery $query): ?MessageId
    {
        return $this->messages->idByClientKey($query->senderId, $query->clientMessageId);
    }
}
