<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // On ne loggue jamais l'identifiant tente : ce serait consigner des
        // identifiants en clair, et une faute de frappe sur le mot de passe
        // saisi dans le champ « username » finirait dans les logs.
        $this->logger->warning('Echec d\'authentification');

        return new JsonResponse(
            [
                'type' => '/problems/authentication-required',
                'title' => 'Authentification requise',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Identifiants invalides.',
                'instance' => $request->getPathInfo(),
            ],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
