<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\Security\SecurityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Repond 204 a la deconnexion, la ou le pare-feu redirigerait vers `/`.
 *
 * Le pendant de `LoginSuccessHandler`, mais en listener et non en handler :
 * `logout.success_handler` a disparu de la configuration en Symfony 6.0, seul
 * `LogoutEvent` subsiste.
 *
 * Priorite superieure a 64, celle de `DefaultLogoutListener` : celui-ci ne pose
 * sa redirection que si personne n'a deja repondu. Passer apres lui ne servirait
 * a rien — c'est l'ordre qui fait la reponse.
 *
 * Le `dispatcher` est explicite : `LogoutEvent` n'est pas emis sur le
 * repartiteur global mais sur celui du pare-feu `api`. Sans lui, le listener
 * serait correctement enregistre et ne serait jamais appele.
 */
#[AsEventListener(
    event: LogoutEvent::class,
    priority: 128,
    dispatcher: 'security.event_dispatcher.api',
)]
final readonly class LogoutResponseListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        // Une deconnexion sans jeton n'est pas une anomalie : un appel sur une
        // session deja expiree passe ici. On repond 204 sans rien logguer, le
        // resultat pour l'appelant etant le meme.
        if ($user instanceof SecurityUser) {
            $this->logger->notice('Deconnexion de l\'utilisateur {user_id}', [
                'user_id' => $user->userId()->toString(),
            ]);
        }

        $event->setResponse(new Response(status: Response::HTTP_NO_CONTENT));
    }
}
