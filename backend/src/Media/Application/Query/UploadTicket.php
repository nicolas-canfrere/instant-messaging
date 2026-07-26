<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

/** DTO de lecture. Modifier cette forme est un changement cassant pour le front. */
final readonly class UploadTicket
{
    public function __construct(
        public string $mediaId,
        public string $uploadUrl,
        public string $expiresAt,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'media_id' => $this->mediaId,
            'upload_url' => $this->uploadUrl,
            'expires_at' => $this->expiresAt,
        ];
    }
}
