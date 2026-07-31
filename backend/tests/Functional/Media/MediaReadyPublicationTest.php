<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

/**
 * La choregraphie de bout en bout : Media publie un fait, Message le traduit en
 * fait metier, Realtime pousse. Aucun contexte n'en pilote un autre.
 *
 * Ce test passe deliberement par le VRAI `command.bus` puis par le transport,
 * la ou `MediaProcessingTest` invoque le handler a la main. C'est la seule
 * facon de faire tourner `TransactionalMiddleware`, seul composant qui publie
 * les domain events apres commit — sans lui, rien de cette chaine ne demarre.
 */
final class MediaReadyPublicationTest extends DatabaseTestCase
{
    use InteractsWithMessenger;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/media/';

    private const string READY_MEDIA_ID = '01JQZ0000000000000000030AA';
    private const string ORPHAN_MEDIA_ID = '01JQZ0000000000000000031AA';
    private const string REJECTED_MEDIA_ID = '01JQZ0000000000000000032AA';

    private const string CREATED_MEDIA_ID = '01JQZ0000000000000000033AA';

    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TC1';

    public function testAMediaProcessedAfterItsMessageIsPushedToTheConversation(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $mediaId = $this->uploadedMedia(self::READY_MEDIA_ID, 'valide.jpg', MediaMimeType::Jpeg);
        $this->sendWithMedia($conversationId, self::CLIENT_ID, $mediaId);

        $this->confirmAndProcess($mediaId);

        $published = $this->mediaReadyPublications();

        self::assertCount(1, $published, 'Un seul publish, comme pour message.created.');
        self::assertSame(sprintf('/conversations/%s', $conversationId), $published[0]['topic']);

        /** @var array{message_id: string, conversation_id: string, media: array<string, mixed>} $payload */
        $payload = $published[0]['payload'];

        self::assertSame($conversationId, $payload['conversation_id']);
        self::assertSame('ready', $payload['media']['status']);
        self::assertIsString($payload['media']['url']);
        self::assertStringContainsString('X-Amz-Signature=', $payload['media']['url']);

        // Exactement la forme de MediaView, rien de plus : aucune cle de
        // stockage ne doit fuir dans une charge utile poussee aux clients.
        self::assertSame(
            ['id', 'status', 'mime_type', 'width', 'height', 'url', 'thumbnail_url', 'filename'],
            array_keys($payload['media']),
        );

        // Pas d'id SSE : l'ULID du message est deja celui de `message.created`.
        // Le reutiliser mettrait deux evenements distincts sous un meme
        // Last-Event-ID (spec §6.2).
        self::assertNull($published[0]['id']);
    }

    /**
     * Le coeur de la tache avec le cas precedent : quand le worker finit avant
     * l'envoi, aucun message ne porte encore le media. Rien n'est publie, et
     * ce comportement ne doit passer par AUCUN `if` — c'est la requete du
     * reader qui ne rend rien.
     */
    public function testAMediaProcessedBeforeAnySendPublishesNothing(): void
    {
        $this->login('alice');

        $mediaId = $this->uploadedMedia(self::ORPHAN_MEDIA_ID, 'valide.jpg', MediaMimeType::Jpeg);

        $this->confirmAndProcess($mediaId);

        self::assertSame([], $this->mediaReadyPublications());
    }

    public function testARejectedMediaIsPushedJustLikeAReadyOne(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        // `piege.jpg` est un fichier PHP deguise : le worker le refuse.
        $mediaId = $this->uploadedMedia(self::REJECTED_MEDIA_ID, 'piege.jpg', MediaMimeType::Jpeg);
        $this->sendWithMedia($conversationId, self::CLIENT_ID, $mediaId);

        $this->confirmAndProcess($mediaId);

        $published = $this->mediaReadyPublications();

        // Le refus s'annonce comme la reussite : sans cette publication, le
        // message porteur resterait « en cours… » pour toujours chez tous les
        // membres du fil.
        self::assertCount(1, $published);

        /** @var array{media: array<string, mixed>} $payload */
        $payload = $published[0]['payload'];

        self::assertSame('rejected', $payload['media']['status']);
        self::assertNull($payload['media']['url']);
        self::assertNull($payload['media']['thumbnail_url']);
    }

    /**
     * Le pendant de la choregraphie, et ce qui la rend rarement necessaire :
     * `message.created` porte deja les medias. Sans cela, un destinataire
     * ignorerait jusqu'a l'EXISTENCE des images du message qu'il vient de
     * recevoir, et devrait redemander la page a l'aveugle pour le decouvrir.
     */
    public function testMessageCreatedAlreadyCarriesItsMedia(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $mediaId = $this->uploadedMedia(self::CREATED_MEDIA_ID, 'valide.jpg', MediaMimeType::Jpeg);
        $this->sendWithMedia($conversationId, self::CLIENT_ID, $mediaId);

        $created = $this->publicationsOfType('message.created');

        self::assertCount(1, $created);

        /** @var array{media: list<array<string, mixed>>} $payload */
        $payload = $created[0]['payload'];

        self::assertCount(1, $payload['media']);
        self::assertSame($mediaId->toString(), $payload['media'][0]['id']);
        // Non terminal, et aucune URL signee : c'est un EMPLACEMENT qu'on
        // annonce, pas une image. `pending` ici parce que ce test envoie le
        // message avant de confirmer le televersement — l'ordre est libre.
        self::assertSame('pending', $payload['media'][0]['status']);
        self::assertNull($payload['media'][0]['url']);
    }

    /**
     * Confirme le televersement par la vraie route, puis fait consommer la file
     * `media`. Le transport (`test://`) intercepte sans consommer : sans ce
     * `process()`, `ProcessMediaCommand` resterait en file et le worker ne
     * tournerait jamais. C'est `process()` qui rejoue le message a travers le
     * bus, donc a travers `TransactionalMiddleware` — la ou la choregraphie
     * demarre.
     */
    private function confirmAndProcess(MediaId $mediaId): void
    {
        $this->client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId->toString()));

        self::assertResponseStatusCodeSame(204);

        $this->transport('media')->process();
    }

    /**
     * Depose les octets dans MinIO et rend un media a l'etat `pending` : la
     * confirmation de televersement, qui le fait passer a `processing`, est le
     * geste de `confirmAndProcess()`. Les tests qui envoient le message AVANT
     * de confirmer voient donc bien un `pending` — l'ordre est libre, le client
     * n'a aucune raison d'attendre.
     */
    private function uploadedMedia(string $mediaIdString, string $fixture, MediaMimeType $declared): MediaId
    {
        $mediaId = MediaId::fromString($mediaIdString);
        $key = StorageKey::forOriginal($mediaId, $declared);

        /** @var MediaRepositoryInterface $repository */
        $repository = static::getContainer()->get(MediaRepositoryInterface::class);

        $media = MediaObject::request(
            $mediaId,
            UserId::fromString($this->userId('alice')),
            $key,
            OriginalFilename::fromString($fixture),
            $declared,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);

        /** @var MediaStorageInterface $storage */
        $storage = static::getContainer()->get(MediaStorageInterface::class);
        $storage->put($key, self::FIXTURES . $fixture, $declared);

        return $mediaId;
    }

    private function sendWithMedia(string $conversationId, string $clientMessageId, MediaId $mediaId): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'media_ids' => [$mediaId->toString()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Filtre sur le type : l'envoi du message a deja pousse un `message.created`
     * sur le meme topic, et il n'a rien a voir avec ce qu'on observe ici.
     *
     * @return list<array{topic: string, type: string, payload: array<string, mixed>, id: string|null}>
     */
    private function mediaReadyPublications(): array
    {
        return $this->publicationsOfType('message.media_ready');
    }

    /** @return list<array{topic: string, type: string, payload: array<string, mixed>, id: string|null}> */
    private function publicationsOfType(string $type): array
    {
        /** @var InMemoryEventPublisher $publisher */
        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        return array_values(array_filter(
            $publisher->published(),
            static fn(array $event): bool => $type === $event['type'],
        ));
    }
}
