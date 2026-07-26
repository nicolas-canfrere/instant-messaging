<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Presence;

use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class RedisPresenceStore implements PresenceStoreInterface
{
    /**
     * 30 s pour un battement client toutes les 20 s. Le rapport de 1 a 1,5
     * absorbe un aller-retour lent ou un battement manque sans faire clignoter
     * la pastille ; plus serre, la presence devient instable ; plus large, une
     * deconnexion met trop longtemps a se voir.
     */
    public const int TTL_SECONDS = 30;

    private const string KEY_PREFIX = 'presence:';

    public function __construct(private \Redis $redis)
    {
    }

    public function touch(UserId $userId): void
    {
        // La valeur ne porte rien : seule l'EXISTENCE de la cle est
        // l'information. Y stocker un horodatage inviterait a s'en servir, donc
        // a reintroduire une duree de vie geree a la main a cote du TTL.
        $this->redis->setex(self::key($userId), self::TTL_SECONDS, '1');
    }

    public function onlineAmong(array $candidates): array
    {
        if ([] === $candidates) {
            // MGET sans cle est une erreur cote Redis, et un aller-retour pour
            // rien : un utilisateur sans aucune conversation passe par ici.
            return [];
        }

        $keys = array_map(static fn(UserId $id): string => self::key($id), $candidates);

        /** @var list<string|false> $values un `false` par cle absente ou expiree */
        $values = $this->redis->mget($keys);

        $online = [];
        foreach ($candidates as $index => $candidate) {
            if (false !== ($values[$index] ?? false)) {
                $online[] = $candidate;
            }
        }

        return $online;
    }

    private static function key(UserId $userId): string
    {
        return sprintf('%s%s', self::KEY_PREFIX, $userId->toString());
    }
}
