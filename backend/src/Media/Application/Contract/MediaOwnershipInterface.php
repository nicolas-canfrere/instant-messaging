<?php

declare(strict_types=1);

namespace App\Media\Application\Contract;

use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Media : « ces medias sont-ils a cette personne, et
 * libres ? ». Ne rend rien — elle leve, ou elle se tait.
 *
 * Elle n'expose ni l'agregat, ni le proprietaire, ni le statut : un
 * consommateur ne doit rien pouvoir deduire de plus que ce qu'il a demande.
 * Modifier cette signature est un changement cassant.
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
     * Un media deja porte par un message leve une exception de conflit (409).
     *
     * Pas de `@throws` FQCN ici : une signature de contrat ne nomme meme pas,
     * dans sa propre documentation, une classe de son propre `Domain` — la
     * couche `*Contract` ne depend que de `Shared` (ADR 0001).
     *
     * @param list<MediaId> $mediaIds
     */
    public function assertUsableBy(array $mediaIds, UserId $ownerId): void;
}
