<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * L'URL signee et son expiration REELLE, portees par le meme objet : c'est le
 * signataire, et lui seul, qui sait jusqu'a quand la signature est valide. Le
 * decoupler en deux constantes independantes (une ici, une recopiee dans le
 * handler de query) laisserait `expires_at` mentir au front pendant que la
 * signature, elle, aurait deja expire.
 */
final readonly class PresignedUpload
{
    public function __construct(
        public string $url,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
