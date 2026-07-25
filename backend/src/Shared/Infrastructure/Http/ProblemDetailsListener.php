<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;
use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Infrastructure\Log\CorrelationIdHolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Seul endroit du projet ou une exception rencontre HTTP.
 * Les exceptions de Domain n'ont aucune connaissance du protocole (regle de dependance).
 */
#[AsEventListener(event: 'kernel.exception')]
final readonly class ProblemDetailsListener
{
    public function __construct(
        private CorrelationIdHolder $correlationIdHolder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $this->unwrap($event->getThrowable());
        [$status, $type, $title, $detail] = $this->describe($throwable);

        $this->log($status, $type, $throwable);

        $event->setResponse(new JsonResponse(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getPathInfo(),
                'correlation_id' => $this->correlationIdHolder->get(),
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    /**
     * Retrouve la cause reelle sous les emballages des composants.
     *
     * Messenger encapsule les exceptions de ses handlers dans une
     * HandlerFailedException. Et le listener de securite, faute d'entry point
     * configure, emballe les exceptions d'authentification et d'autorisation
     * dans une HttpException : sans ce deballage, tout 401 ressortirait en
     * `/problems/http-error` au lieu de dire ce qui s'est reellement passe.
     */
    private function unwrap(\Throwable $throwable): \Throwable
    {
        while ($throwable instanceof HandlerFailedException) {
            $previous = $throwable->getPrevious();

            if (null === $previous) {
                return $throwable;
            }

            $throwable = $previous;
        }

        $previous = $throwable->getPrevious();

        if ($throwable instanceof HttpExceptionInterface
            && ($previous instanceof AuthenticationException || $previous instanceof AccessDeniedException)
        ) {
            return $previous;
        }

        return $throwable;
    }

    /** @return array{int, string, string, string} */
    private function describe(\Throwable $throwable): array
    {
        return match (true) {
            // Avant AuthenticationException, dont elle herite. Le detail ne dit
            // jamais laquelle des deux valeurs est fausse : Symfony masque deja
            // « utilisateur inconnu » en « identifiants invalides », et le dire
            // ici donnerait un oracle pour enumerer les comptes.
            $throwable instanceof BadCredentialsException => [
                Response::HTTP_UNAUTHORIZED,
                '/problems/invalid-credentials',
                'Identifiants invalides',
                'Le nom d\'utilisateur ou le mot de passe est incorrect.',
            ],
            $throwable instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                '/problems/authentication-required',
                'Authentification requise',
                'Cette ressource necessite une session valide.',
            ],
            $throwable instanceof AccessDeniedException => [
                Response::HTTP_FORBIDDEN,
                '/problems/access-denied',
                'Acces refuse',
                'Votre role ne permet pas cette operation.',
            ],
            $throwable instanceof NotFoundExceptionInterface,
            $throwable instanceof NotFoundHttpException => [
                Response::HTTP_NOT_FOUND,
                '/problems/resource-not-found',
                'Ressource introuvable',
                'Cette ressource n\'existe pas ou ne vous est pas accessible.',
            ],
            $throwable instanceof InvalidInputExceptionInterface => [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                '/problems/validation-failed',
                'Requete invalide',
                // Les messages des exceptions de domaine sont ecrits pour etre lus
                // par un humain et ne portent ni contenu de message ni secret.
                $throwable->getMessage(),
            ],
            $throwable instanceof \JsonException => [
                Response::HTTP_BAD_REQUEST,
                '/problems/malformed-request',
                'Requete malformee',
                'Le corps de la requete n\'est pas un JSON valide.',
            ],
            $throwable instanceof HttpExceptionInterface => [
                $throwable->getStatusCode(),
                '/problems/http-error',
                'Erreur HTTP',
                'La requete n\'a pas pu etre traitee.',
            ],
            // `detail` constant : en 500, aucun message d'exception ni fragment SQL ne sort.
            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                '/problems/internal-error',
                'Erreur interne',
                'Une erreur interne est survenue.',
            ],
        };
    }

    private function log(int $status, string $type, \Throwable $throwable): void
    {
        $context = ['problem_type' => $type, 'status' => $status, 'exception' => $throwable];

        if ($status >= 500) {
            $this->logger->error('Requete API en erreur interne ({problem_type})', $context);

            return;
        }

        $this->logger->warning('Requete API rejetee ({problem_type})', $context);
    }
}
