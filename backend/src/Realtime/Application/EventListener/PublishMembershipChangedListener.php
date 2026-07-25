<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MembershipChanged;
use App\Shared\Domain\IdGeneratorInterface;

/**
 * Publie sur le topic personnel du membre concerne. Ce topic est present dans
 * TOUS ses JWT et ne change jamais : c'est le seul canal par lequel il peut
 * apprendre qu'on vient de l'ajouter a une conversation — et donc qu'il doit
 * demander un nouveau jeton pour s'abonner a son topic.
 */
final readonly class PublishMembershipChangedListener implements DomainEventListenerInterface
{
    public function __construct(
        private EventPublisherInterface $publisher,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    public function __invoke(MembershipChanged $event): void
    {
        $this->publisher->publish(
            Topic::userSystem($event->userId),
            'membership.changed',
            [
                'conversation_id' => $event->conversationId->toString(),
                'change' => $event->change->value,
            ],
            $this->idGenerator->generate(),
        );
    }
}
