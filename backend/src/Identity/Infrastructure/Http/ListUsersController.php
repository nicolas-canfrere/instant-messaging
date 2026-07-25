<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListUsersController
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    #[Route('/api/users', name: 'users_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // Ni e-mail ni hash : l'annuaire sert a choisir un interlocuteur.
        return new JsonResponse(array_map(
            static fn(User $user): array => [
                'id' => $user->id()->toString(),
                'username' => $user->username(),
                'display_name' => $user->displayName(),
            ],
            $this->users->all(),
        ));
    }
}
