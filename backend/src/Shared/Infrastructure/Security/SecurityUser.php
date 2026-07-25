<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Identifier\UserId;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adaptateur entre le jeton de securite Symfony et le domaine.
 *
 * Dans Shared parce que TOUS les contextes en ont besoin : chaque controleur
 * doit connaitre l'utilisateur courant. Identity garde en revanche le
 * SecurityUserProvider, qui interroge la table users dont il est proprietaire.
 *
 * Ajouter OAuth plus tard consistera a peupler cet objet depuis un autre
 * authenticator : ni le domaine ni les use cases ne changeront.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * `getUserIdentifier()` doit rendre un `non-empty-string` : la contrainte
     * remonte jusqu'ici, et de la jusqu'aux colonnes NOT NULL de `users`.
     *
     * @param non-empty-string $id
     * @param non-empty-string $username
     */
    public function __construct(
        private string $id,
        private string $username,
        private ?string $passwordHash,
    ) {
    }

    public function userId(): UserId
    {
        return UserId::fromString($this->id);
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
