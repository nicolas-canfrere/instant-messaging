<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marqueur d'une lecture, parametre par le type de son resultat.
 *
 * C'est ce parametre qui permet a QueryDispatcher::ask() de rendre un type
 * precis plutot que `mixed` : l'appelant n'a plus a restreindre le resultat
 * lui-meme.
 *
 * @template-covariant TResult
 */
interface QueryInterface
{
}
