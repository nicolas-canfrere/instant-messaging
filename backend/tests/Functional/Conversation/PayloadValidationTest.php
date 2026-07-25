<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Une charge utile malformee ne doit JAMAIS produire de 500 : c'est une entree
 * cliente invalide, donc un 422, avec de quoi corriger la requete.
 */
final class PayloadValidationTest extends DatabaseTestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'member_ids est une chaine' => ['{"type":"direct","member_ids":"pas-un-tableau"}'];
        yield 'member_ids contient un entier' => ['{"type":"direct","member_ids":[123]}'];
        yield 'member_ids contient un ULID invalide' => ['{"type":"direct","member_ids":["pas-un-ulid"]}'];
        yield 'title est un tableau' => ['{"type":"group","title":[],"member_ids":[]}'];
        yield 'title manquant pour un groupe' => ['{"type":"group","member_ids":[]}'];
        yield 'title vide pour un groupe' => ['{"type":"group","title":"   ","member_ids":[]}'];
        yield 'type inconnu' => ['{"type":"broadcast","member_ids":[]}'];
        yield 'type manquant' => ['{"member_ids":[]}'];
        yield 'corps null' => ['null'];
        yield 'corps = tableau JSON' => ['[1,2,3]'];
    }

    #[DataProvider('malformedPayloads')]
    public function testAMalformedPayloadIsRejectedWithA422(string $content): void
    {
        $this->login('alice');

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    /** Un corps qui n'est pas du JSON est une requete malformee, pas invalide. */
    public function testAnUnparseableBodyIsA400(): void
    {
        $this->login('alice');

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{oops',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /** Le client doit savoir QUEL champ corriger, sans avoir a deviner. */
    public function testTheProblemDocumentNamesTheOffendingFields(): void
    {
        $this->login('alice');

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"type":"broadcast","member_ids":["pas-un-ulid"]}',
        );

        $problem = $this->json();

        self::assertArrayHasKey('violations', $problem);

        /** @var list<array{field: string, message: string}> $violations */
        $violations = $problem['violations'];

        self::assertNotEmpty($violations);
        self::assertSame('type', $violations[0]['field']);

        // Le chemin sort en snake_case comme le reste de l'API : le client n'a
        // pas a connaitre le nom de nos proprietes PHP.
        self::assertSame('member_ids[0]', $violations[1]['field']);
    }

    public function testAddingMembersRejectsAMalformedPayload(): void
    {
        $this->login('alice');

        $this->client->request(
            'POST',
            '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZZ/members',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"user_ids":"pas-un-tableau"}',
        );

        self::assertResponseStatusCodeSame(422);
    }
}
