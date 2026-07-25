<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\QueryInterface;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `HandleTrait` fait deja le deballage du HandledStamp et leve si aucun — ou si
 * plusieurs — handler ne repond. Inutile de le refaire a la main.
 */
final class QueryDispatcher
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    /**
     * @template TResult
     *
     * @param QueryInterface<TResult> $query
     *
     * @return TResult
     */
    public function ask(QueryInterface $query): mixed
    {
        /** @var TResult $result */
        $result = $this->handle($query);

        return $result;
    }
}
