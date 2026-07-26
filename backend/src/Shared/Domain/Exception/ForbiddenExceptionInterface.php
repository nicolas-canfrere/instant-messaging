<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marqueur : l'appartenance est etablie, seule l'autorisation manque. Traduit
 * en 403 — ce qui ne revele rien que l'appelant ne sache deja. Un non-membre,
 * lui, doit continuer de recevoir un 404 (NotFoundExceptionInterface).
 */
interface ForbiddenExceptionInterface extends ProblemExceptionInterface
{
}
