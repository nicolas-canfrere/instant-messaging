<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Application\Query\OrphanMediaReaderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Seule requete du contexte Media qui NOMME `message_media`, une table qu'il ne
 * possede pas — et c'est une entorse assumee a l'ADR 0001, faute de mieux.
 *
 * L'alternative propre serait un contrat publie par Message (« ces medias
 * sont-ils portes ? ») que Media consulterait. Elle a un cout reel : Media
 * dependrait alors de Message, alors que son ignorance des messages est
 * precisement ce qui rendra son extraction en service triviale (spec §1.1). Et
 * un `NOT EXISTS` corrélé ne se remplace pas par un aller-retour applicatif
 * sans lire d'abord TOUS les medias anciens pour les filtrer ensuite.
 *
 * A rouvrir le jour d'une extraction reelle : la purge deviendrait alors une
 * responsabilite de Message, qui sait ce qu'il porte, et publierait un fait que
 * Media ecouterait.
 */
final readonly class DbalOrphanMediaReader implements OrphanMediaReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function orphansOlderThan(\DateTimeImmutable $threshold, int $limit): array
    {
        /** @var list<array{id: string, storage_key: string, thumbnail_key: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT m.id,
                       m.storage_key,
                       m.thumbnail_key
                FROM media_objects m
                WHERE m.created_at < :threshold
                  AND NOT EXISTS (SELECT 1 FROM message_media mm WHERE mm.media_id = m.id)
                ORDER BY m.created_at
                LIMIT :limit
                SQL,
            [
                'threshold' => $threshold->format(\DateTimeInterface::ATOM),
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn(array $row): array => [
                'id' => $row['id'],
                'storageKey' => $row['storage_key'],
                'thumbnailKey' => $row['thumbnail_key'],
            ],
            $rows,
        );
    }
}
