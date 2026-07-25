<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime\Domain;

use App\Realtime\Domain\Topic;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class TopicTest extends TestCase
{
    private const string ULID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';

    public function testConversationTopic(): void
    {
        $topic = Topic::conversation(ConversationId::fromString(self::ULID));

        self::assertSame('/conversations/' . self::ULID, $topic->toString());
    }

    public function testUserSystemTopic(): void
    {
        $topic = Topic::userSystem(UserId::fromString(self::ULID));

        self::assertSame('/users/' . self::ULID . '/system', $topic->toString());
    }

    /**
     * Un identifiant de conversation et un identifiant d'utilisateur peuvent
     * porter la meme valeur : le prefixe est ce qui empeche un message de partir
     * sur le canal personnel de quelqu'un.
     */
    public function testTopicsOfDifferentKindsNeverCollide(): void
    {
        self::assertNotSame(
            Topic::conversation(ConversationId::fromString(self::ULID))->toString(),
            Topic::userSystem(UserId::fromString(self::ULID))->toString(),
        );
    }
}
