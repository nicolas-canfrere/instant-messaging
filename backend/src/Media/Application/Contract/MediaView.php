<?php

declare(strict_types=1);

namespace App\Media\Application\Contract;

/**
 * Surface PUBLIEE de Media pour la lecture. Modifier cette forme est un
 * changement cassant : le front en depend, et `MessageView` la recopie telle
 * quelle dans ses reponses.
 *
 * Elle n'expose jamais la cle de stockage — seulement des URLs deja signees.
 * Une cle donnerait au client de quoi fabriquer un chemin vers le bucket ; une
 * URL signee ne vaut que pour l'objet qu'elle nomme, et pour quinze minutes.
 */
final readonly class MediaView
{
    public function __construct(
        public string $id,
        public string $status,
        /** Renseignes UNIQUEMENT quand `status` vaut `ready` : on ne signe pas
         *  l'acces a des octets qu'on n'a pas encore valides (spec §4.3). */
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public ?string $url,
        public ?string $thumbnailUrl,
        public string $filename,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
            'filename' => $this->filename,
        ];
    }
}
