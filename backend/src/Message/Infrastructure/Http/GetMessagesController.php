<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Query\GetMessagePageQuery;
use App\Message\Infrastructure\Http\Payload\MessagePageQueryString;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Aucun #[IsGranted] : l'appartenance cadre la query elle-meme, donc un
 * non-membre recoit un 404 et non un 403.
 */
final readonly class GetMessagesController
{
    public function __construct(private QueryDispatcher $queries)
    {
    }

    #[Route('/api/conversations/{conversationId}/messages', name: 'messages_list', methods: ['GET'])]
    public function __invoke(
        ConversationId $conversationId,
        #[MapQueryString] MessagePageQueryString $pagination,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $page = $this->queries->ask(new GetMessagePageQuery(
            $conversationId,
            $securityUser->userId(),
            null === $pagination->before ? null : MessageId::fromString($pagination->before),
            $pagination->limit,
        ));

        return new JsonResponse($page->toArray());
    }
}
