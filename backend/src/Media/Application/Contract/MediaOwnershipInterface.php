<?php

declare(strict_types=1);

namespace App\Media\Application\Contract;

use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Media : « ces medias sont-ils a cette personne ? ». Ne
 * rend rien — elle leve, ou elle se tait.
 *
 * Elle ne repond QUE de l'appartenance. Savoir si un media est deja porte par
 * un message est une question sur une table que Media ne possede pas
 * (`message_media`) : cette question reste entierement du cote de Message,
 * qui la pose a ses propres donnees (cf. ADR 0001 — pas de lecture de la
 * table d'un contexte voisin, meme via un contrat).
 *
 * Elle n'expose ni l'agregat, ni le statut : un consommateur ne doit rien
 * pouvoir deduire de plus que ce qu'il a demande. Modifier cette signature
 * est un changement cassant.
 */
interface MediaOwnershipInterface
{
    /**
     * Un media inconnu et un media appartenant a quelqu'un d'autre sont
     * INDISTINGUABLES pour l'appelant : les deux levent la meme exception
     * « introuvable » (404). Un statut different aurait confirme l'existence
     * du media a quelqu'un qui n'y a aucun droit — un oracle d'enumeration
     * (CLAUDE.md, "404 pas 403").
     *
     * Pas de `@throws` FQCN ici : une signature de contrat ne nomme meme pas,
     * dans sa propre documentation, une classe de son propre `Domain` — la
     * couche `*Contract` ne depend que de `Shared` (ADR 0001).
     *
     * @param list<MediaId> $mediaIds
     */
    public function assertOwnedBy(array $mediaIds, UserId $ownerId): void;
}
