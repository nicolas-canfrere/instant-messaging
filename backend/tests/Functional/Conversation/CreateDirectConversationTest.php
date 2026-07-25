<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;

final class CreateDirectConversationTest extends DatabaseTestCase
{
    public function testCreatingTheSameDirectTwiceReturnsTheSameConversation(): void
    {
        $this->login('alice');
        $carolId = $this->userId('carol');

        $first = $this->createDirect($carolId);
        $second = $this->createDirect($carolId);

        self::assertSame($first, $second, 'La creation d\'un direct doit etre idempotente.');
    }

    /** La commutativite de DirectKey, verifiee de bout en bout. */
    public function testTheDirectIsTheSameWhicheverSideOpensIt(): void
    {
        $this->login('alice');
        $carolId = $this->userId('carol');
        $fromAlice = $this->createDirect($carolId);

        $this->login('carol');
        $aliceId = $this->userId('alice');
        $fromCarol = $this->createDirect($aliceId);

        self::assertSame($fromAlice, $fromCarol);
    }

    public function testOneCannotOpenADirectWithOneself(): void
    {
        $this->login('alice');

        $this->createDirect($this->userId('alice'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    public function testMyConversationsAreListed(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/conversations');

        self::assertResponseIsSuccessful();

        /** @var list<array{id: string, type: string}> $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertGreaterThanOrEqual(2, count($body), 'Alice a au moins le direct et le groupe des fixtures.');
    }

    /** On ne voit jamais les conversations des autres. */
    public function testTheListIsScopedToTheCurrentUser(): void
    {
        $this->login('carol');

        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, type: string}> $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        // Carol n'est membre que du groupe, pas du direct Alice-Bob.
        self::assertCount(1, $body);
        self::assertSame('group', $body[0]['type']);
    }

    public function testConversationsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/conversations');

        self::assertResponseStatusCodeSame(401);
    }

    private function createDirect(string $peerId): string
    {
        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['type' => 'direct', 'member_ids' => [$peerId]], \JSON_THROW_ON_ERROR),
        );

        /** @var array{id?: string} $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $body['id'] ?? '';
    }
}
