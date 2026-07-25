<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Marqueur : la ressource n'existe pas, ou n'est pas accessible a l'appelant. Traduit en 404. */
interface NotFoundExceptionInterface extends \Throwable
{
}
