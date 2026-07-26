<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Une exception qui sait nommer SA classe de probleme, sans rien savoir de HTTP.
 *
 * Le domaine fournit un slug stable et un libelle ; c'est le listener de
 * Shared/Infrastructure, et lui seul, qui en fait une URI `/problems/...` et
 * choisit un statut. La regle « les exceptions de Domain ignorent le protocole »
 * tient donc toujours.
 */
interface ProblemExceptionInterface extends \Throwable
{
    /** Slug stable de la CLASSE de probleme, en kebab-case. Jamais de valeur variable. */
    public function problemSlug(): string;

    /** Libelle court, constant pour un slug donne. Le variable va dans le message. */
    public function problemTitle(): string;
}
