<?php

declare(strict_types=1);

namespace App\Media\Application\Contract;

use App\Shared\Domain\Identifier\MediaId;

/**
 * Lecture par LOT, et c'est la seule forme offerte : un `viewFor(MediaId)`
 * unitaire inviterait le N+1 que ce contrat existe pour rendre impossible.
 * L'appelant rassemble ses identifiants, puis demande une fois.
 */
interface MediaFinderInterface
{
    /**
     * Indexe par ULID plutot que rendu en liste : l'appelant doit pouvoir
     * recoller chaque vue a la liaison qui la reclame, sans re-parcourir.
     *
     * Un identifiant inconnu est simplement ABSENT du tableau rendu — ce n'est
     * pas une erreur : une liaison peut survivre a une purge, et une lecture
     * n'a pas a echouer pour autant.
     *
     * @param list<MediaId> $mediaIds
     *
     * @return array<string, MediaView>
     */
    public function viewsFor(array $mediaIds): array;
}
