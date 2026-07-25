<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Query\ConversationView;
use App\Conversation\Application\Query\ListMyConversationsQuery;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ListConversationsController
{
    public function __construct(private QueryDispatcher $queries)
    {
    }

    #[Route('/api/conversations', name: 'conversations_list', methods: ['GET'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        // Le parametre de QueryInterface donne le type du resultat : plus besoin
        // de le restreindre a la main cote appelant.
        $views = $this->queries->ask(new ListMyConversationsQuery($securityUser->userId()));

        return new JsonResponse(array_map(
            static fn(ConversationView $view): array => $view->toArray(),
            $views,
        ));
    }
}
