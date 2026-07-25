<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\DirectKey;
use App\Conversation\Domain\SelfConversationException;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class DirectKeyTest extends TestCase
{
    private const string ALICE = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string BOB = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';

    /** C'est cette propriete qui rend la creation d'un direct idempotente. */
    public function testTheKeyIsCommutative(): void
    {
        $alice = UserId::fromString(self::ALICE);
        $bob = UserId::fromString(self::BOB);

        self::assertSame(
            DirectKey::forPair($alice, $bob)->toString(),
            DirectKey::forPair($bob, $alice)->toString(),
        );
    }

    public function testDifferentPairsProduceDifferentKeys(): void
    {
        $carol = UserId::fromString('01J9ZQ7X8K3M4N5P6Q7R8S9TAD');

        self::assertNotSame(
            DirectKey::forPair(UserId::fromString(self::ALICE), UserId::fromString(self::BOB))->toString(),
            DirectKey::forPair(UserId::fromString(self::ALICE), $carol)->toString(),
        );
    }

    public function testOneCannotOpenADirectWithOneself(): void
    {
        $this->expectException(SelfConversationException::class);

        DirectKey::forPair(UserId::fromString(self::ALICE), UserId::fromString(self::ALICE));
    }

    /** La cle fait exactement 53 caracteres : deux ULID et un separateur. */
    public function testTheKeyFitsTheColumnWidth(): void
    {
        $key = DirectKey::forPair(UserId::fromString(self::ALICE), UserId::fromString(self::BOB));

        self::assertSame(53, strlen($key->toString()));
    }
}
