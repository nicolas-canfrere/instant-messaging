<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Ce handler ne formate aucune reponse : il journalise puis relance, et
 * ProblemDetailsListener produit le document RFC 7807. C'est le seul endroit du
 * projet ou une exception rencontre HTTP, et il le reste.
 *
 * Il faut malgre tout un handler : sans lui, JsonLoginAuthenticator renverrait
 * son `{"error": "..."}` par defaut, qui n'est pas un Problem Details.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // On ne loggue jamais l'identifiant tente : ce serait consigner des
        // identifiants en clair, une faute de frappe pouvant amener un mot de
        // passe dans le champ « username ».
        $this->logger->warning('Echec d\'authentification');

        throw $exception;
    }
}
