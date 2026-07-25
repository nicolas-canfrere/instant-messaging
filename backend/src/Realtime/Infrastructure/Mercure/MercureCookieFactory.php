<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Shared\Domain\Identifier\UserId;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

/**
 * Le cookie produit s'appelle `mercureAuthorization`, est HttpOnly et porte le
 * chemin du hub. Le front ne le lit jamais : il autorise, il ne selectionne pas.
 */
final readonly class MercureCookieFactory
{
    public function __construct(
        private Authorization $authorization,
        private SubscribeTopicsProvider $topicsProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function forUser(Request $request, UserId $userId): Cookie
    {
        $topics = $this->topicsProvider->forUser($userId);

        // On loggue un identifiant et un compte, jamais le JWT ni les topics
        // eux-memes : le jeton est un secret de session.
        $this->logger->debug('Emission d\'un JWT Mercure pour {user_id} sur {topic_count} topics', [
            'user_id' => $userId->toString(),
            'topic_count' => count($topics),
        ]);

        return $this->authorization->createCookie($request, $topics);
    }
}
