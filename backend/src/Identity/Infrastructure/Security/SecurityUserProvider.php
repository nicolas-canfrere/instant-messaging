<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Reste dans Identity : c'est ce contexte qui possede la table `users`.
 * Il produit un SecurityUser, qui lui vit dans Shared parce que tous les
 * controleurs en dependent.
 *
 * Le SQL est dans UserCredentialsReader ; ici ne reste que le contrat Symfony.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface
{
    public function __construct(private UserCredentialsReader $credentials)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->credentials->byUsername($identifier)
            ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
