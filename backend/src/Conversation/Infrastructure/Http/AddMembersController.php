<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\AddMembersCommand;
use App\Conversation\Application\Query\GetConversationQuery;
use App\Conversation\Infrastructure\Security\ConversationVoter;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class AddMembersController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[Route('/api/conversations/{conversationId}/members', name: 'conversation_members_add', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        Request $request,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // L'ordre des deux controles porte la regle : d'abord l'appartenance,
        // qui donne un 404 sans rien confirmer ; ensuite seulement le role, qui
        // donne un 403 puisque l'appartenance est deja etablie.
        $this->queries->ask(new GetConversationQuery($conversationId, $securityUser->userId()));

        if (!$this->authorization->isGranted(ConversationVoter::MANAGE_MEMBERS, $conversationId)) {
            throw new AccessDeniedException();
        }

        /** @var array{user_ids?: list<string>} $payload */
        $payload = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $userIds = array_map(
            static fn(string $id): UserId => UserId::fromString($id),
            $payload['user_ids'] ?? [],
        );

        if ([] === $userIds) {
            throw new UnsupportedConversationPayloadException();
        }

        $this->commands->dispatch(new AddMembersCommand($conversationId, $userIds));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
