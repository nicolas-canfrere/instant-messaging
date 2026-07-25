<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\Security\SecurityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var SecurityUser $user */
        $user = $token->getUser();

        // Un identifiant, jamais l'identifiant de connexion saisi.
        $this->logger->notice('Connexion de l\'utilisateur {user_id}', [
            'user_id' => $user->userId()->toString(),
        ]);

        return new JsonResponse(['status' => 'ok']);
    }
}
