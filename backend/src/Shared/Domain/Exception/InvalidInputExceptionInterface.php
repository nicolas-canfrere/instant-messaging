<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Marqueur : la requete est bien formee mais son contenu est invalide. Traduit en 422. */
interface InvalidInputExceptionInterface extends \Throwable
{
}
