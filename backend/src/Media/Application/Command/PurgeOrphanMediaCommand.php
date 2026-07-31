<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;

/**
 * Ramasse les medias que plus rien ne reference. Aucun argument : le seuil et
 * le plafond sont des regles, pas des parametres d'appel — les rendre
 * ajustables inviterait a purger « juste ce coup-ci » un peu plus large, sur
 * une base de production, sans trace de la decision.
 *
 * Declenchee a la main par `media:purge-orphans`. Pas de planificateur : il n'y
 * en a aucun dans le projet, et en ajouter un pour une seule commande serait de
 * l'infrastructure non justifiee (spec §7.3).
 */
final readonly class PurgeOrphanMediaCommand implements CommandInterface
{
}
