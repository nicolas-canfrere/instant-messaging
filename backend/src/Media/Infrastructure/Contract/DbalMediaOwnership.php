<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Contract;

use App\Media\Application\Contract\MediaOwnershipInterface;
use App\Media\Domain\MediaNotFoundException;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Realise le contrat publie. Interroge UNIQUEMENT `media_objects`, la table
 * que Media possede : elle ignore l'existence de `message_media`, qui
 * appartient a Message. C'est cette ignorance qui rend l'extraction de Media
 * en service triviale le jour venu (spec §1.1).
 */
final readonly class DbalMediaOwnership implements MediaOwnershipInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertOwnedBy(array $mediaIds, UserId $ownerId): void
    {
        /** @var list<array{id: string, owner_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT m.id,
                       m.owner_id
                FROM media_objects m
                WHERE m.id IN (:ids)
                SQL,
            ['ids' => array_map(static fn(MediaId $id): string => $id->toString(), $mediaIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[$row['id']] = $row;
        }

        foreach ($mediaIds as $mediaId) {
            $row = $rowsById[$mediaId->toString()] ?? null;

            // Media inconnu ET media appartenant a quelqu'un d'autre levent la
            // MEME exception : les distinguer donnerait un oracle d'enumeration
            // a qui tente d'attacher le media d'un tiers (CLAUDE.md, "404 pas 403").
            if (null === $row || $row['owner_id'] !== $ownerId->toString()) {
                throw MediaNotFoundException::withId($mediaId);
            }
        }
    }
}
