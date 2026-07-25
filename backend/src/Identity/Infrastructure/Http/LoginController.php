<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Ce corps ne s'execute jamais.
 *
 * `json_login` intercepte la requete depuis le pare-feu, bien avant que le
 * controleur ne soit appele. La route doit malgre tout exister : le
 * RouterListener s'execute a la priorite 32 et le pare-feu a 8, donc sans elle
 * le routeur leve un 404 avant que l'authentificateur ait sa chance.
 *
 * La reponse renvoyee en cas de succes vient de LoginSuccessHandler, celle
 * d'echec de LoginFailureHandler.
 */
final class LoginController
{
    #[Route('/api/login', name: 'login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('Cette route est interceptee par le pare-feu json_login.');
    }
}
