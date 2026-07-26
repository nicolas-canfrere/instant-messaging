<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\MediaId;

/**
 * Emis par Media, ecoute par Message (qui le traduit en fait metier).
 *
 * Charge utile en SCALAIRES uniquement : `status` et `mimeType` voyagent en
 * `string`, pas en MediaStatus ni MediaMimeType. L'inverse ferait dependre
 * Shared du contexte Media (ADR 0001).
 *
 * Ni cle de stockage, ni URL signee : une URL vit 15 minutes, la mettre dans
 * un evenement serait y mettre quelque chose de perimable.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MediaWasProcessed implements DomainEventInterface
{
    public function __construct(
        public MediaId $mediaId,
        public string $status,
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public ?int $byteSize,
        public \DateTimeImmutable $processedAt,
    ) {
    }
}
