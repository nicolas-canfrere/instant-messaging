<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Domain\UserRepositoryInterface;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class MeController
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    #[Route('/api/me', name: 'me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $user = $this->users->ofId($securityUser->userId());

        return new JsonResponse([
            'id' => $user->id()->toString(),
            'username' => $user->username(),
            'display_name' => $user->displayName(),
        ]);
    }
}
