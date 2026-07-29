<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Tests\Functional\DatabaseTestCase;

final class SendMessageWithMediaTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string OTHER_CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';

    private const string ALICE_MEDIA_ID = '01JQZ0000000000000000001AA';
    private const string BOB_MEDIA_ID = '01JQZ0000000000000000002AA';

    public function testAMessageWithOnlyAnImageIsCreatedWithoutContent(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID]);

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $created */
        $created = $this->json();

        /** @var string|null $content */
        $content = $this->connection->fetchOne('SELECT content FROM messages WHERE id = :id', [
            'id' => $created['id'],
        ]);

        self::assertNull($content);

        // Le point de la fonctionnalite : la liaison existe reellement, pas
        // seulement le contenu nul. Prouve directement plutot que de
        // l'inferer via le test de conflit (409).
        /** @var array{media_id: string, position: int}|false $link */
        $link = $this->connection->fetchAssociative(
            'SELECT media_id, position FROM message_media WHERE message_id = :message_id',
            ['message_id' => $created['id']],
        );

        self::assertNotFalse($link, 'La liaison message_media doit exister.');
        self::assertSame(self::ALICE_MEDIA_ID, $link['media_id']);
        self::assertSame(0, $link['position']);
    }

    public function testTheTenMediaCapIsEnforced(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        // Onze identifiants VALIDES au format, mais jamais crees en base : la
        // contrainte de comptage doit refuser la requete avant meme que
        // l'appartenance ne soit verifiee.
        $elevenMediaIds = array_map(
            static fn(int $i): string => sprintf('01JQZ%019dAA', $i),
            range(1, 11),
        );

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, $elevenMediaIds);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    /** Une meme cle deux fois dans UNE requete doit etre refusee avant d'atteindre le repository. */
    public function testDuplicateMediaIdsInTheSameRequestAreRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID, self::ALICE_MEDIA_ID]);

        self::assertResponseStatusCodeSame(422);

        /** @var array{type: string, violations: list<array{field: string, message: string}>} $problem */
        $problem = $this->json();

        self::assertSame('/problems/validation-failed', $problem['type']);
        self::assertSame('media_ids', $problem['violations'][0]['field']);
    }

    /**
     * Un corps `{"media_ids": {"a": "...", "b": "..."}}` deserialise en
     * tableau a cles NON sequentielles. Sans `array_values()` dans le
     * controleur, `position` (SMALLINT) recevrait une cle de tableau au lieu
     * d'un entier — un 500 la ou l'entree meritait d'etre simplement
     * normalisee.
     */
    public function testAnObjectShapedMediaIdsBodyIsNormalisedToAList(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            // json_encode() serialise un tableau a cles non entieres
            // sequentielles en objet JSON.
            content: json_encode(
                ['client_message_id' => self::CLIENT_ID, 'media_ids' => ['x' => self::ALICE_MEDIA_ID]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $created */
        $created = $this->json();

        /** @var int $position */
        $position = $this->connection->fetchOne(
            'SELECT position FROM message_media WHERE message_id = :message_id AND media_id = :media_id',
            ['message_id' => $created['id'], 'media_id' => self::ALICE_MEDIA_ID],
        );

        self::assertSame(0, $position);
    }

    public function testAMessageWithNeitherTextNorMediaIsRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    /** Un media qui n'est pas a l'expediteur rend 404 : un 403 confirmerait son existence. */
    public function testAttachingSomeoneElsesMediaIsRefusedAsNotFound(): void
    {
        // Le login le temps de lire l'annuaire, puis on repart sur la session d'Alice.
        $this->login('bob');
        $bobId = $this->userId('bob');
        $this->createMedia(self::BOB_MEDIA_ID, $bobId);

        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::BOB_MEDIA_ID]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/resource-not-found', $this->json()['type']);
    }

    public function testAttachingTheSameMediaTwiceIsRejectedAsAConflict(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID]);
        self::assertResponseStatusCodeSame(201);

        $this->postWithMedia($conversationId, self::OTHER_CLIENT_ID, null, [self::ALICE_MEDIA_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/media-already-attached', $this->json()['type']);
    }

    /** Le convertisseur snake_case s'applique aussi aux chemins indexes. */
    public function testAMalformedMediaIdReportsItsIndexedFieldPath(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, ['pas-un-ulid']);

        self::assertResponseStatusCodeSame(422);

        /** @var array{violations: list<array{field: string, message: string}>} $problem */
        $problem = $this->json();

        self::assertSame('media_ids[0]', $problem['violations'][0]['field']);
    }

    /**
     * Regression : sans `mediaIds` obligatoire dans `MessageMapper::fromRow()`,
     * `ofId()`/`ofClientKey()` reconstituaient TOUJOURS l'agregat avec une
     * liste vide, quelle que soit la realite en base. `save()` traduit une
     * liste vide en `DELETE FROM message_media` — donc `edit()` aurait
     * detache silencieusement les medias d'un message au premier `PATCH`,
     * bien avant toute suppression.
     */
    public function testEditingAMessageThatCarriesMediaKeepsItsAttachment(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, 'avant', [self::ALICE_MEDIA_ID]);
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $created */
        $created = $this->json();

        $this->client->request(
            'PATCH',
            sprintf('/api/conversations/%s/messages/%s', $conversationId, $created['id']),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['content' => 'apres'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $attachedCount = $this->connection->fetchOne(
            'SELECT count(*) FROM message_media WHERE message_id = :message_id',
            ['message_id' => $created['id']],
        );

        // `fetchOne` rend `mixed` : on restreint avant de convertir.
        self::assertIsNumeric($attachedCount);
        self::assertSame(1, (int) $attachedCount, 'Editer le texte ne doit pas detacher les medias.');
    }

    /** L'autre moitie du detachement : rien ne prouvait encore que le DELETE se produit reellement. */
    public function testDeletingForEveryoneDetachesTheMediaInTheDatabase(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $this->createMedia(self::ALICE_MEDIA_ID, $this->userId('alice'));

        $this->postWithMedia($conversationId, self::CLIENT_ID, null, [self::ALICE_MEDIA_ID]);
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $created */
        $created = $this->json();

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $created['id']));

        self::assertResponseStatusCodeSame(204);

        $attachedCount = $this->connection->fetchOne(
            'SELECT count(*) FROM message_media WHERE message_id = :message_id',
            ['message_id' => $created['id']],
        );

        // `fetchOne` rend `mixed` : on restreint avant de convertir.
        self::assertIsNumeric($attachedCount);
        self::assertSame(0, (int) $attachedCount, 'Supprimer pour tous doit detacher les medias en base, pas seulement dans l\'agregat.');
    }

    /**
     * Cree un media appartenant a l'utilisateur donne. Le statut n'importe
     * pas au contrat d'attachement (`DbalMediaOwnership`) : seule
     * l'appartenance et l'absence de rattachement prealable comptent.
     */
    private function createMedia(string $mediaIdString, string $ownerId): void
    {
        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        $mediaId = MediaId::fromString($mediaIdString);
        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($ownerId),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);
    }

    /** @param list<string> $mediaIds */
    private function postWithMedia(string $conversationId, string $clientMessageId, ?string $content, array $mediaIds): void
    {
        $body = ['client_message_id' => $clientMessageId, 'media_ids' => $mediaIds];

        if (null !== $content) {
            $body['content'] = $content;
        }

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
