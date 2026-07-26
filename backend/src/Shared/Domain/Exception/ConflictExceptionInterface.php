<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marqueur : l'appelant a le droit d'agir, mais l'ETAT de la ressource rend
 * l'operation impossible. Traduit en 409 — le client peut en deduire une action
 * utile : rafraichir, sa vue est perimee.
 */
interface ConflictExceptionInterface extends ProblemExceptionInterface
{
}
