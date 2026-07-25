<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\CreateDirectConversationCommand;
use App\Conversation\Application\Command\CreateGroupConversationCommand;
use App\Conversation\Application\Query\FindDirectConversationQuery;
use App\Conversation\Domain\ConversationType;
use App\Conversation\Infrastructure\Http\Payload\CreateConversationPayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class CreateConversationController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    #[Route('/api/conversations', name: 'conversations_create', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] CreateConversationPayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // La charge utile est deja desserialisee et validee : le type est
        // forcement l'une des deux valeurs de l'enum, et memberIds une liste
        // d'ULID bien formes.
        $memberIds = array_map(
            static fn(string $id): UserId => UserId::fromString($id),
            $payload->memberIds,
        );

        return match (ConversationType::from($payload->type)) {
            ConversationType::Direct => $this->openDirect($securityUser->userId(), $memberIds),
            ConversationType::Group => $this->createGroup($securityUser->userId(), $payload->title, $memberIds),
        };
    }

    /**
     * Ouvrir un direct est un create-or-get : 200 s'il existait deja, 201
     * sinon. Distinguer les deux impose de savoir s'il preexistait, donc la
     * lecture prealable n'est pas un cout supplementaire, c'est la question.
     *
     * @param list<UserId> $memberIds
     */
    private function openDirect(UserId $me, array $memberIds): JsonResponse
    {
        if (1 !== count($memberIds)) {
            throw new UnsupportedConversationPayloadException('Un direct se cree avec exactement un autre membre.');
        }

        $peer = $memberIds[0];
        $existing = $this->queries->ask(new FindDirectConversationQuery($me, $peer));

        if (null !== $existing) {
            return new JsonResponse(['id' => $existing->toString()], Response::HTTP_OK);
        }

        $this->commands->dispatch(new CreateDirectConversationCommand($me, $peer));

        // La relecture apres ecriture est ce qui rend le resultat correct en
        // concurrence : l'unicite de direct_key elimine un des deux inserts, et
        // c'est le gagnant que l'on rend, jamais un identifiant jamais ecrit.
        $created = $this->queries->ask(new FindDirectConversationQuery($me, $peer));

        if (null === $created) {
            throw new \LogicException('La conversation directe vient d\'etre creee mais reste introuvable.');
        }

        return new JsonResponse(['id' => $created->toString()], Response::HTTP_CREATED);
    }

    /**
     * Un groupe n'a pas de cle naturelle : sa creation n'est pas idempotente,
     * rien n'est a reconcilier. L'identifiant est donc genere ici et porte par
     * la commande, ce qui evite toute relecture.
     *
     * @param list<UserId> $memberIds
     */
    private function createGroup(UserId $me, ?string $title, array $memberIds): JsonResponse
    {
        // Contrainte conditionnelle : un titre n'a de sens que pour un groupe,
        // la validation de la charge utile ne peut donc pas l'exiger seule.
        if (null === $title || '' === trim($title)) {
            throw new UnsupportedConversationPayloadException('Un groupe requiert un titre.');
        }

        $conversationId = ConversationId::fromString($this->idGenerator->generate());

        $this->commands->dispatch(new CreateGroupConversationCommand($conversationId, $me, $title, $memberIds));

        return new JsonResponse(['id' => $conversationId->toString()], Response::HTTP_CREATED);
    }
}
