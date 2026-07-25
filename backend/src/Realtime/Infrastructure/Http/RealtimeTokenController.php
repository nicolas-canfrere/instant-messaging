<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Realtime\Infrastructure\Mercure\MercureCookieFactory;
use App\Realtime\Infrastructure\Mercure\SubscribeTopicsProvider;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Repond 200 avec `{"hub_url": …, "topics": [...]}` ET pose le cookie.
 *
 * Mercure exige les deux : le cookie AUTORISE, tandis que l'abonne doit
 * SELECTIONNER ses topics dans l'URL du hub (`?topic=…`). Renvoyer la liste ici
 * evite que le front reconstruise les chaines de topic de son cote, ce qui
 * recreerait exactement la duplication que le VO Topic supprime cote serveur.
 */
final readonly class RealtimeTokenController
{
    public function __construct(
        private MercureCookieFactory $cookieFactory,
        private SubscribeTopicsProvider $topicsProvider,
        private string $mercurePublicUrl,
    ) {
    }

    #[Route('/api/realtime/token', name: 'realtime_token', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $userId = $securityUser->userId();

        $response = new JsonResponse([
            'hub_url' => $this->mercurePublicUrl,
            'topics' => $this->topicsProvider->forUser($userId),
        ]);

        $response->headers->setCookie($this->cookieFactory->forUser($request, $userId));

        return $response;
    }
}
