<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Query\GetConversationQuery;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Aucun `#[IsGranted]` ici : la lecture est cadree par l'appartenance dans la
 * query elle-meme. Un non-membre obtient donc un 404, jamais un 403 — qui
 * confirmerait l'existence de la conversation.
 */
final readonly class GetConversationController
{
    public function __construct(private QueryDispatcher $queries)
    {
    }

    #[Route('/api/conversations/{conversationId}', name: 'conversations_get', methods: ['GET'])]
    public function __invoke(
        ConversationId $conversationId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $view = $this->queries->ask(new GetConversationQuery($conversationId, $securityUser->userId()));

        return new JsonResponse($view->toArray());
    }
}
