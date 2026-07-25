<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * La frappe ne passe PAS par la choregraphie, contrairement aux accuses.
 *
 * Elle n'ecrit rien : ni agregat, ni transaction, ni domain event a enregistrer.
 * La faire transiter par une commande vide, a travers un middleware
 * transactionnel qui n'aurait aucune transaction a ouvrir, serait du ceremonial.
 * La choregraphie sert a ne pas publier ce qui n'est pas commite ; sans
 * ecriture, elle n'a rien a proteger.
 */
final readonly class TypingController
{
    public function __construct(
        private ConversationMembershipInterface $membership,
        private EventPublisherInterface $publisher,
    ) {
    }

    #[Route('/api/conversations/{conversationId}/typing', name: 'conversation_typing', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // 404 et non 403 : un 403 confirmerait que la conversation existe.
        if (!$this->membership->isMember($conversationId, $securityUser->userId())) {
            throw new NotFoundHttpException();
        }

        $this->publisher->publish(
            Topic::conversation($conversationId),
            'typing.started',
            [
                'conversation_id' => $conversationId->toString(),
                'user_id' => $securityUser->userId()->toString(),
            ],
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
