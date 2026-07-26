<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\LeaveConversationCommand;
use App\Conversation\Application\Query\GetConversationQuery;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Partir de son propre chef, par opposition a RemoveMemberController, qui est
 * le geste d'un admin sur quelqu'un d'autre.
 *
 * Aucun appel au voter : tout membre a le droit de TENTER de partir, c'est le
 * domaine qui tranche sur le role. La query prealable, elle, cadre la reponse
 * sur l'appartenance — un non-membre recoit un 404, qui ne confirme pas
 * l'existence de la conversation.
 */
final readonly class LeaveConversationController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route(
        '/api/conversations/{conversationId}/members/me',
        name: 'conversation_members_leave',
        methods: ['DELETE'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $this->queries->ask(new GetConversationQuery($conversationId, $securityUser->userId()));

        $this->commands->dispatch(new LeaveConversationCommand($conversationId, $securityUser->userId()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
