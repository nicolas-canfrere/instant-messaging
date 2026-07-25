<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Security;

use App\Conversation\Application\MembershipCheckerInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Security\SecurityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Ce voter ne traite QUE la dimension du role, qui se traduit en 403.
 *
 * L'appartenance, elle, se traduit en 404 et non en 403 — un 403 confirmerait
 * l'existence de la conversation. Elle est donc appliquee en amont, en cadrant
 * lectures et ecritures sur les conversations dont on est membre.
 *
 * @extends Voter<string, ConversationId>
 */
final class ConversationVoter extends Voter
{
    public const string VIEW = 'CONVERSATION_VIEW';
    public const string POST_MESSAGE = 'CONVERSATION_POST_MESSAGE';
    public const string MANAGE_MEMBERS = 'CONVERSATION_MANAGE_MEMBERS';

    public function __construct(
        private readonly MembershipCheckerInterface $membership,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::POST_MESSAGE, self::MANAGE_MEMBERS], true)
            && $subject instanceof ConversationId;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof SecurityUser) {
            return false;
        }

        $granted = match ($attribute) {
            self::MANAGE_MEMBERS => $this->membership->isAdmin($subject, $user->userId()),
            default => $this->membership->isMember($subject, $user->userId()),
        };

        if (!$granted) {
            // `warning` : signale soit une interface qui propose une action
            // interdite, soit une tentative deliberee. Les deux sont actionnables.
            $this->logger->warning('Acces refuse a {conversation_id} pour {user_id} ({attribute})', [
                'conversation_id' => $subject->toString(),
                'user_id' => $user->userId()->toString(),
                'attribute' => $attribute,
            ]);
        }

        return $granted;
    }
}
