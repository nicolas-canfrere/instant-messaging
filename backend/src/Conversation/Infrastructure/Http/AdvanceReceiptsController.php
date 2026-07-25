<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\AdvanceReceiptsCommand;
use App\Conversation\Infrastructure\Http\Payload\AdvanceReceiptsPayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * 204 : l'appelant apprendra l'effet par le flux temps reel, comme tout le
 * monde. Renvoyer le watermark resultant creerait un second chemin
 * d'information a garder coherent avec le premier.
 *
 * L'appartenance est verifiee par le repository, qui leve
 * ConversationNotFoundException — donc 404, jamais 403.
 */
final readonly class AdvanceReceiptsController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route('/api/conversations/{conversationId}/receipts', name: 'conversation_receipts_advance', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        #[MapRequestPayload] AdvanceReceiptsPayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $this->commands->dispatch(new AdvanceReceiptsCommand(
            $conversationId,
            $securityUser->userId(),
            $payload->deliveredUpTo,
            $payload->readUpTo,
        ));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
