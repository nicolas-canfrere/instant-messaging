<?php

declare(strict_types=1);

namespace App\Message\Domain\Port;

use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Le BESOIN de Message, exprime dans son propre langage. L'adaptateur qui le
 * realise delegue au contrat publie de Media.
 *
 * Ce port existe pour que le contexte n'ait pas a nommer directement un autre
 * contexte dans sa couche Application : le seul endroit ou Media apparait est
 * l'adaptateur, en Infrastructure.
 */
interface MediaOwnershipPortInterface
{
    /**
     * Un media inconnu ou appartenant a quelqu'un d'autre leve une exception
     * « introuvable » (404) ; un media deja porte par un message leve une
     * exception de conflit (409). Pas de `@throws` FQCN : Message ne nomme
     * meme pas, dans une docblock, une classe du `Domain` de Media.
     *
     * @param list<MediaId> $mediaIds
     */
    public function assertUsableBy(array $mediaIds, UserId $ownerId): void;
}
