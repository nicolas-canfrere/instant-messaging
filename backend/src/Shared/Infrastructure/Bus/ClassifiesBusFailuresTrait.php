<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;
use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

trait ClassifiesBusFailuresTrait
{
    /**
     * Une ressource introuvable ou une entree invalide sont des issues metier
     * normales, pas des pannes. Les logguer en `error` remplirait le journal de
     * lignes non actionnables — et apprendrait a ignorer les vraies.
     *
     * Messenger emballe les exceptions de ses handlers : on regarde la cause.
     */
    private function isExpectedOutcome(\Throwable $throwable): bool
    {
        $cause = $throwable instanceof HandlerFailedException
            ? ($throwable->getPrevious() ?? $throwable)
            : $throwable;

        return $cause instanceof NotFoundExceptionInterface
            || $cause instanceof InvalidInputExceptionInterface;
    }
}
