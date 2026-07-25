<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\RemoveMemberCommand;
use App\Conversation\Application\Query\GetConversationQuery;
use App\Conversation\Infrastructure\Security\ConversationVoter;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class RemoveMemberController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[Route(
        '/api/conversations/{conversationId}/members/{userId}',
        name: 'conversation_members_remove',
        methods: ['DELETE'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        UserId $userId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $this->queries->ask(new GetConversationQuery($conversationId, $securityUser->userId()));

        if (!$this->authorization->isGranted(ConversationVoter::MANAGE_MEMBERS, $conversationId)) {
            throw new AccessDeniedException();
        }

        $this->commands->dispatch(new RemoveMemberCommand($conversationId, $userId));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
