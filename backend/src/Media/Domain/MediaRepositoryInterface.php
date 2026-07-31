<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Identifier\MediaId;

interface MediaRepositoryInterface
{
    public function add(MediaObject $media): void;

    /** @throws MediaNotFoundException */
    public function ofId(MediaId $mediaId): MediaObject;

    public function save(MediaObject $media): void;

    /**
     * Efface la ligne. Prend un identifiant et non l'agregat : la purge n'a
     * aucune decision a prendre sur un media qu'elle a deja retenu, et
     * reconstituer l'objet pour l'effacer serait une lecture pour rien.
     *
     * Ne leve pas si la ligne est deja absente : effacer est idempotent.
     */
    public function remove(MediaId $mediaId): void;
}
