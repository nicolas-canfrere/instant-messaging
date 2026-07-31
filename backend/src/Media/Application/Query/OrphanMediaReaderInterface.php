<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

interface OrphanMediaReaderInterface
{
    /**
     * Les medias que plus aucun message ne porte, du plus ancien au plus
     * recent.
     *
     * Rend les CLES de stockage et pas l'agregat : le handler n'a rien a
     * decider ici, il efface. Reconstituer un MediaObject par orphelin
     * ajouterait une lecture complete pour une information dont on n'utilise
     * que deux colonnes.
     *
     * @param positive-int $limit plafond de sortie, borne par l'appelant
     *
     * @return list<array{id: string, storageKey: string, thumbnailKey: string|null}>
     */
    public function orphansOlderThan(\DateTimeImmutable $threshold, int $limit): array;
}
