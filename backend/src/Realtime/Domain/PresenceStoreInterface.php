<?php

declare(strict_types=1);

namespace App\Realtime\Domain;

use App\Shared\Domain\Identifier\UserId;

/**
 * La presence est un etat EPHEMERE : elle ne va jamais en base principale. Un
 * booleen `is_online` persiste devient faux au premier crash et n'est jamais
 * remis a false — c'est l'anti-pattern que ce port existe pour eviter.
 */
interface PresenceStoreInterface
{
    /** Marque l'utilisateur present et repousse son expiration. */
    public function touch(UserId $userId): void;

    /**
     * Filtre les candidats : ne rend que ceux qui sont presents.
     *
     * Prend les candidats en argument plutot que de rendre « tous les gens en
     * ligne » : la presence de personnes avec qui on ne partage aucun fil ne
     * doit pas pouvoir fuiter. La restriction vit dans la signature, pas dans
     * la discipline de l'appelant.
     *
     * @param  list<UserId> $candidates
     *
     * @return list<UserId>
     */
    public function onlineAmong(array $candidates): array;
}
