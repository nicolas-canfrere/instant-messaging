<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\CreateDirectConversationCommand;
use App\Conversation\Application\Query\GetDirectConversationIdQuery;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Volontairement limite aux directs : la tache 9 ajoutera les groupes et
 * distinguera 201 de 200.
 */
final readonly class CreateConversationController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route('/api/conversations', name: 'conversations_create', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        /** @var array{type?: string, member_ids?: list<string>} $payload */
        $payload = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $memberIds = $payload['member_ids'] ?? [];

        if ('direct' !== ($payload['type'] ?? null) || 1 !== count($memberIds)) {
            throw new UnsupportedConversationPayloadException();
        }

        $me = $securityUser->userId();
        $peer = UserId::fromString($memberIds[0]);

        // La commande ne rend rien : on ecrit, puis on demande. La creation
        // etant idempotente, la question a une reponse dans les deux cas.
        $this->commands->dispatch(new CreateDirectConversationCommand($me, $peer));

        $conversationId = $this->queries->ask(new GetDirectConversationIdQuery($me, $peer));

        return new JsonResponse(['id' => $conversationId->toString()], Response::HTTP_CREATED);
    }
}
