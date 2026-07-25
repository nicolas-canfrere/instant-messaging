<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Realtime\Application\Command\RecordHeartbeatCommand;
use App\Realtime\Application\Query\GetOnlinePeersQuery;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Un seul aller-retour toutes les 20 s : il rafraichit le TTL ET rend la
 * presence. Deux routes separees doubleraient le trafic pour faire respecter
 * une separation qui l'est deja ici — la commande ecrit et ne rend rien, la
 * query lit. C'est exactement le « pour connaitre l'effet d'une ecriture, on
 * pose ensuite une query » du CQS, applique dans un adaptateur primaire.
 */
final readonly class HeartbeatController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route('/api/presence/heartbeat', name: 'presence_heartbeat', methods: ['POST'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $this->commands->dispatch(new RecordHeartbeatCommand($securityUser->userId()));

        return new JsonResponse([
            'online_user_ids' => $this->queries->ask(new GetOnlinePeersQuery($securityUser->userId())),
        ]);
    }
}
