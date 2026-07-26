# Tranche 3 — cycle de vie des messages : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** donner un cycle de vie aux messages — édition dans une fenêtre de 15 minutes et suppression pour tous avec effacement réel du contenu — puis rendre les dates dans le fuseau du lecteur.

**Architecture:** l'agrégat `Message` cesse d'être immuable et porte les trois invariants (auteur, fenêtre, tombstone). Il publie `MessageWasEdited` / `MessageWasDeleted` ; `Realtime` les diffuse sur le topic de la conversation, `Conversation` rafraîchit son aperçu par chorégraphie. Le front applique un état complet par `id` serveur, jamais un delta.

**Tech Stack:** PHP 8.4 / Symfony 7, Doctrine DBAL (sans ORM), PostgreSQL 17, Mercure, PHPUnit ; React 19 / TypeScript / Vite / Tailwind, Vitest.

**Spec de référence :** `docs/superpowers/specs/2026-07-26-instant-messaging-tranche-3-design.md`

## Global Constraints

- **Branche unique pour toute la tranche : `feat/tranche-3-cycle-de-vie`.** Jamais de commit sur `main`.
- **Ni PHP ni Node ne sont installés sur l'hôte.** Toute commande passe par `make` ou `docker compose run --rm <service> <cmd>`. Ne jamais invoquer `php`, `composer`, `npm`, `vendor/bin/*` directement.
- **Ne pas installer de paquet Composer ni npm.** Cette tranche n'en demande aucun. Si un manque apparaît, le signaler à Nicolas, ne pas l'installer.
- **`Domain/` ne dépend de rien** — zéro paquet Composer, pas même `symfony/uid`. `deptrac` échoue le build en cas de violation.
- **`Application` ne connaît aucun vendor hors `Psr\*`.**
- **Un contexte ne dépend que du contrat publié d'un autre.** Aucun `SELECT` dans la table d'un contexte voisin.
- **Un handler de commande rend toujours `void`.** Pour connaître l'effet d'une écriture, poser une query.
- **SQL littéral**, jamais de `QueryBuilder`, toujours des paramètres liés, requête écrite en entier.
- **Logs** : placeholders `{entre_accolades}`, variables dans le second argument, jamais de `sprintf` ni d'interpolation. **Ne jamais logguer le `content` d'un message** — seulement des identifiants et, à la rigueur, une longueur.
- **`sprintf()` partout ailleurs**, jamais de concaténation avec `.`.
- **Conventions Symfony** : interfaces suffixées `Interface`, exceptions suffixées `Exception`, cas d'enum en `UpperCamelCase`, constantes en `SCREAMING_SNAKE_CASE`, une classe par fichier.
- **Le code du dépôt est écrit sans accents dans les commentaires et messages d'exception PHP.** Suivre l'existant.
- **PHPStan niveau `max`** : génériques annotés, lignes DBAL typées précisément, aucun `mixed` implicite. **Jamais de baseline ni de `@phpstan-ignore`.**
- **TDD** : le test qui décrit le comportement avant le code.
- **Portes de qualité, vertes avant chaque commit** : `make unit-test`, `make functional-test`, `make static-code-analysis`, `make check-cs`, `make deptrac`, `make front-test`, `make front-typecheck`.
- **Commits conventionnels, en français, à l'impératif.** Terminer chaque message par `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Après tout `git checkout` / `merge` / `rebase`, redémarrer le conteneur frontend : `make restart SERVICE=frontend` (Vite sert du code périmé sinon).

---

## File Structure

**Backend — créés**

| Fichier | Responsabilité |
|---|---|
| `backend/migrations/VersionYYYYMMDDHHMMSS.php` | `content` nullable, `edited_at`, `deleted_at`, contrainte `CHECK` |
| `src/Shared/Domain/Exception/ProblemExceptionInterface.php` | slug et libellé stables d'une classe de problème, sans notion de HTTP |
| `src/Shared/Domain/Exception/ForbiddenExceptionInterface.php` | marqueur traduit en 403 par le listener |
| `src/Shared/Domain/Exception/ConflictExceptionInterface.php` | marqueur traduit en 409 par le listener |
| `src/Message/Domain/NotTheAuthorException.php` | seul l'auteur édite ou supprime |
| `src/Message/Domain/EditWindowExpiredException.php` | fenêtre de 15 min dépassée |
| `src/Message/Domain/MessageAlreadyDeletedException.php` | édition d'un tombstone |
| `src/Message/Application/Command/DeleteMessageCommand.php` + `…Handler.php` | suppression pour tous |
| `src/Message/Application/Command/EditMessageCommand.php` + `…Handler.php` | édition |
| `src/Message/Application/Query/GetMessageQuery.php` + `…Handler.php` | relire un message après un `PATCH` |
| `src/Message/Infrastructure/Http/DeleteMessageController.php` | `DELETE /api/conversations/{id}/messages/{messageId}` |
| `src/Message/Infrastructure/Http/EditMessageController.php` | `PATCH` idem |
| `src/Message/Infrastructure/Http/Payload/EditMessagePayload.php` | corps validé du `PATCH` |
| `src/Shared/Domain/Event/MessageWasDeleted.php` | fait publié, sans charge utile |
| `src/Shared/Domain/Event/MessageWasEdited.php` | fait publié, avec le nouveau contenu |
| `src/Realtime/Application/EventListener/PublishMessageWasDeletedListener.php` | `message.deleted` sur le topic conversation |
| `src/Realtime/Application/EventListener/PublishMessageWasEditedListener.php` | `message.edited` idem |
| `src/Conversation/Application/LastMessagePreview.php` | troncature de l'aperçu, un seul endroit |
| `src/Conversation/Application/Command/RefreshLastMessagePreviewCommand.php` + `…Handler.php` | rafraîchissement de l'aperçu |
| `src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasDeletedListener.php` | chorégraphie, suppression |
| `src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasEditedListener.php` | chorégraphie, édition |

**Backend — modifiés**

| Fichier | Changement |
|---|---|
| `src/Message/Domain/Message.php` | état mutable, `edit()`, `deleteForEveryone()`, `content()` nullable |
| `src/Message/Domain/MessageRepositoryInterface.php` | `ofId()`, `save()` |
| `src/Message/Infrastructure/Persistence/DbalMessageRepository.php` | implémente les deux, colonnes nouvelles |
| `src/Message/Infrastructure/Persistence/MessageMapper.php` | ligne élargie, `content` nullable |
| `src/Message/Infrastructure/Persistence/DbalMessagePageReader.php` | trois colonnes de plus |
| `src/Message/Infrastructure/Persistence/DbalMessageReader.php` | `view()` |
| `src/Message/Application/Query/MessageView.php` | `?string $content`, `?string $editedAt`, `?string $deletedAt` |
| `src/Message/Application/Query/MessageReaderInterface.php` | `view()` |
| `src/Message/Application/Command/SendMessageCommandHandler.php` | `content()` devient nullable, comparaison de rejeu adaptée |
| `src/Shared/Infrastructure/Http/ProblemDetailsListener.php` | deux nouveaux marqueurs |
| `src/Conversation/Application/LastMessagePointerWriterInterface.php` | `refreshPreview()` |
| `src/Conversation/Infrastructure/Persistence/DbalLastMessagePointerWriter.php` | l'`UPDATE` gardé |
| `src/Conversation/Application/EventListener/RecordLastMessageOnMessageWasSentListener.php` | utilise `LastMessagePreview` |
| `config/services.yaml` | rien à ajouter — vérifier que c'est bien le cas (tâche 2) |

**Frontend — créés :** `src/ui/dates.ts`, `src/ui/dates.test.ts`, `src/ui/MessageActions.tsx`, `src/ui/MessageEditor.tsx`.
**Frontend — modifiés :** `src/api/types.ts`, `src/api/client.ts`, `src/store/messagesReducer.ts` (+ son test), `src/hooks/useAppState.ts`, `src/ui/MessageList.tsx`, `src/ui/ConversationView.tsx`, `src/ui/ConversationList.tsx`, `src/ui/labels.ts`.

---

## Task 1 : migration et contrat de lecture nullable

Tâche délibérément **inerte** : elle porte à elle seule le changement cassant du contrat, ce qui rend les six suivantes additives. Aucun comportement nouveau.

**Files:**
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (nom généré)
- Modify: `backend/src/Message/Domain/Message.php`
- Modify: `backend/src/Message/Infrastructure/Persistence/MessageMapper.php`
- Modify: `backend/src/Message/Infrastructure/Persistence/DbalMessagePageReader.php`
- Modify: `backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php`
- Modify: `backend/src/Message/Application/Query/MessageView.php`
- Modify: `backend/src/Message/Application/Command/SendMessageCommandHandler.php`
- Modify: `frontend/src/api/types.ts`, `frontend/src/store/messagesReducer.ts`, `frontend/src/hooks/useAppState.ts`, `frontend/src/ui/MessageList.tsx`
- Test: `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php` (créé), `backend/tests/Functional/Message/MessagePaginationTest.php` (lu, pas modifié)

**Interfaces:**
- Consumes: rien.
- Produces:
  - `Message::reconstitute(MessageId $id, ConversationId $conversationId, UserId $senderId, ?MessageContent $content, ClientMessageId $clientMessageId, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $editedAt = null, ?\DateTimeImmutable $deletedAt = null): self`
  - `Message::content(): ?MessageContent`, `Message::editedAt(): ?\DateTimeImmutable`, `Message::deletedAt(): ?\DateTimeImmutable`, `Message::isDeleted(): bool`
  - `MessageView::__construct(string $id, string $conversationId, string $senderId, ?string $content, string $clientMessageId, string $createdAt, ?string $editedAt, ?string $deletedAt)`
  - TypeScript : `ApiMessage.content: string | null`, `ApiMessage.edited_at: string | null`, `ApiMessage.deleted_at: string | null` ; `StoredMessage.content: string | null`, `StoredMessage.editedAt: string | null`, `StoredMessage.deletedAt: string | null`

- [ ] **Step 1 : générer le squelette de migration**

```bash
make generate-migration
```

Note le nom du fichier créé dans `backend/migrations/` (`VersionYYYYMMDDHHMMSS.php`). C'est celui qu'on remplit à l'étape suivante.

- [ ] **Step 2 : écrire la migration**

Remplace le contenu du fichier généré (garde son nom de classe) :

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionYYYYMMDDHHMMSS extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cycle de vie des messages : edition et suppression pour tous (tranche 3).';
    }

    public function up(Schema $schema): void
    {
        // `content` devient nullable parce que la suppression pour tous EFFACE
        // reellement la charge utile : record soft, payload hard. Masquer a
        // l'affichage ne suffirait pas, un client modifie lirait encore le texte.
        //
        // Ni `deleted_by` ni `deletion_scope` : seul l'auteur supprime, la
        // premiere vaudrait donc toujours `sender_id` ; et une seule portee
        // existe, la seconde n'aurait qu'une valeur. La moderation (tranche 5)
        // les ajoutera quand elle aura un lecteur.
        //
        // Aucun index nouveau : rien ne filtre sur `deleted_at`, les tombstones
        // sont rendus et non masques. La requete dominante reste
        // (conversation_id, id DESC).
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                ALTER COLUMN content DROP NOT NULL,
                ADD COLUMN edited_at  TIMESTAMPTZ DEFAULT NULL,
                ADD COLUMN deleted_at TIMESTAMPTZ DEFAULT NULL
            SQL);

        // L'invariant central de la tranche, ecrit la ou la base peut le tenir
        // elle-meme : un message est vivant si et seulement si il a une charge
        // utile. Ce n'est pas une redondance avec l'agregat — c'est ce qui
        // protege d'une migration future, d'une correction en psql ou d'une
        // fixture bâclee.
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_tombstone_has_no_payload
                CHECK ((deleted_at IS NULL) = (content IS NOT NULL))
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.content IS 'Texte du message. NULL uniquement sur un message supprime pour tous : la charge utile est reellement effacee.'
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.edited_at IS 'Instant de la derniere edition, en UTC. NULL si jamais edite.'
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.deleted_at IS 'Instant de la suppression pour tous, en UTC. NULL si le message est vivant.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Le contenu des tombstones est definitivement perdu : on les supprime
        // plutot que de laisser `ALTER COLUMN SET NOT NULL` echouer.
        $this->addSql('DELETE FROM messages WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                DROP COLUMN edited_at,
                DROP COLUMN deleted_at,
                ALTER COLUMN content SET NOT NULL
            SQL);
    }
}
```

- [ ] **Step 3 : jouer la migration**

Run: `make migrate`
Expected: la migration s'applique sans erreur. Vérifier avec `make migration-status` qu'elle est bien la dernière appliquée.

- [ ] **Step 4 : écrire le test qui décrit l'état nouveau de l'agrégat**

Crée `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message\Domain;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\Message;
use App\Message\Domain\MessageContent;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class MessageLifecycleTest extends TestCase
{
    private const string MESSAGE_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA1';
    private const string CONVERSATION_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA2';
    private const string AUTHOR_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA3';
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA5';

    public function testAFreshMessageIsNeitherEditedNorDeleted(): void
    {
        $message = self::send();

        self::assertFalse($message->isDeleted());
        self::assertNull($message->editedAt());
        self::assertNull($message->deletedAt());
        self::assertSame('bonjour', $message->content()?->toString());
    }

    /** Un tombstone se relit sans contenu : c'est ce que la colonne nullable permet. */
    public function testATombstoneCanBeReconstitutedWithoutContent(): void
    {
        $deletedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $message = Message::reconstitute(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::AUTHOR_ID),
            null,
            ClientMessageId::fromString(self::CLIENT_ID),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            null,
            $deletedAt,
        );

        self::assertTrue($message->isDeleted());
        self::assertNull($message->content());
        self::assertEquals($deletedAt, $message->deletedAt());
        self::assertSame([], $message->releaseEvents(), 'reconstitute() n\'enregistre jamais d\'evenement.');
    }

    private static function send(): Message
    {
        return Message::send(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('bonjour'),
            ClientMessageId::fromString(self::CLIENT_ID),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
    }
}
```

- [ ] **Step 5 : lancer le test, vérifier qu'il échoue**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: FAIL — `Message::isDeleted()` n'existe pas, et `reconstitute()` n'accepte pas encore un contenu nul.

- [ ] **Step 6 : rendre l'agrégat nullable**

Dans `backend/src/Message/Domain/Message.php` : le constructeur privé et `reconstitute()` prennent `?MessageContent $content`, plus `?\DateTimeImmutable $editedAt = null` et `?\DateTimeImmutable $deletedAt = null`. Les propriétés `$content`, `$editedAt`, `$deletedAt` **cessent d'être `readonly`** (les tâches 2 et 4 les muteront) ; `$id`, `$conversationId`, `$senderId`, `$clientMessageId`, `$createdAt` restent `readonly`.

`send()` conserve sa signature — un message envoyé a toujours un contenu — et passe `null, null` pour les deux nouveaux paramètres.

Ajoute :

```php
    public function content(): ?MessageContent
    {
        return $this->content;
    }

    public function editedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /** `content` nul et `deletedAt` non nul sont la meme information : un seul point la lit. */
    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
```

Garde intact le commentaire de `reconstitute()` qui interdit d'y enregistrer un événement.

- [ ] **Step 7 : lancer le test, vérifier qu'il passe**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: PASS

- [ ] **Step 8 : propager la nullabilité dans la persistance et le contrat**

`MessageMapper::fromRow()` — le type de ligne s'élargit :

```php
    /** @param array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null} $row */
    public function fromRow(array $row): Message
    {
        return Message::reconstitute(
            MessageId::fromString($row['id']),
            ConversationId::fromString($row['conversation_id']),
            UserId::fromString($row['sender_id']),
            // `null` n'est pas une absence de donnee : c'est un tombstone, dont
            // la charge utile a ete reellement effacee.
            null === $row['content'] ? null : MessageContent::fromString($row['content']),
            ClientMessageId::fromString($row['client_message_id']),
            new \DateTimeImmutable($row['created_at']),
            null === $row['edited_at'] ? null : new \DateTimeImmutable($row['edited_at']),
            null === $row['deleted_at'] ? null : new \DateTimeImmutable($row['deleted_at']),
        );
    }
```

`DbalMessageRepository::ofClientKey()` — le `SELECT` et son annotation `@var` listent les trois colonnes nouvelles :

```sql
SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
FROM messages
WHERE sender_id = :sender_id
  AND client_message_id = :client_message_id
```

`DbalMessagePageReader::page()` — les deux requêtes listent aussi les trois colonnes, l'annotation `@var` s'élargit (`content: string|null, …, edited_at: string|null, deleted_at: string|null`), et la construction du `MessageView` devient :

```php
            static fn(array $row): MessageView => new MessageView(
                $row['id'],
                $row['conversation_id'],
                $row['sender_id'],
                $row['content'],
                $row['client_message_id'],
                DatabaseTimestamp::toAtom($row['created_at']),
                DatabaseTimestamp::toAtom($row['edited_at']),
                DatabaseTimestamp::toAtom($row['deleted_at']),
            ),
```

`DatabaseTimestamp::toAtom()` accepte déjà `?string` et rend `null` pour `null` : rien à y changer.

`MessageView` :

```php
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        /** `null` veut dire supprime pour tous : il n'y a plus de charge utile. */
        public ?string $content,
        public string $clientMessageId,
        public string $createdAt,
        public ?string $editedAt,
        public ?string $deletedAt,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'content' => $this->content,
            // Renvoye au client pour qu'il puisse reconcilier son message
            // optimiste avec celui que le serveur confirme.
            'client_message_id' => $this->clientMessageId,
            'created_at' => $this->createdAt,
            'edited_at' => $this->editedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
```

`SendMessageCommandHandler` — la comparaison du rejeu doit traverser le nullable :

```php
        // Un tombstone n'a plus de contenu : `?->` rend alors `null`, qui ne
        // peut egaler la chaine entrante. Un rejeu sur un message supprime est
        // donc signale comme un contenu different, ce qui est exact.
        if ($existing->content()?->toString() !== $command->content->toString()) {
```

- [ ] **Step 9 : lancer les tests backend**

Run: `make unit-test && make functional-test`
Expected: PASS. `MessagePaginationTest` et `SendMessageTest` passent sans modification — c'est la preuve que la tâche est bien inerte côté comportement.

- [ ] **Step 10 : adapter le front au type nullable**

`frontend/src/api/types.ts` :

```ts
export type ApiMessage = {
  id: string;
  conversation_id: string;
  sender_id: string;
  /** `null` veut dire supprime pour tous : le serveur n'a plus la charge utile. */
  content: string | null;
  client_message_id: string;
  created_at: string;
  edited_at: string | null;
  deleted_at: string | null;
};
```

`frontend/src/store/messagesReducer.ts` — `StoredMessage` :

```ts
export type StoredMessage = {
  id: string | null;
  clientMessageId: string;
  conversationId: string;
  senderId: string;
  content: string | null;
  createdAt: string;
  editedAt: string | null;
  deletedAt: string | null;
  status: MessageStatus;
};
```

`frontend/src/hooks/useAppState.ts` — `fromApiMessage()` et `toStoredMessage()` reportent les deux champs. Dans `toStoredMessage()`, `content` passe par un lecteur qui distingue « absent » de « nul » :

```ts
function fromApiMessage(message: ApiMessage): StoredMessage {
  return {
    id: message.id,
    clientMessageId: message.client_message_id,
    conversationId: message.conversation_id,
    senderId: message.sender_id,
    content: message.content,
    createdAt: message.created_at,
    editedAt: message.edited_at,
    deletedAt: message.deleted_at,
    status: 'sent',
  };
}
```

```ts
function toStoredMessage(payload: Record<string, unknown>): StoredMessage {
  const id = readString(payload, 'id');
  const clientMessageId = readString(payload, 'client_message_id');

  return {
    id,
    clientMessageId: clientMessageId === '' ? id : clientMessageId,
    conversationId: readString(payload, 'conversation_id'),
    senderId: readString(payload, 'sender_id'),
    content: readNullableString(payload, 'content'),
    createdAt: readString(payload, 'created_at'),
    editedAt: readNullableString(payload, 'edited_at'),
    deletedAt: readNullableString(payload, 'deleted_at'),
    status: 'sent',
  };
}
```

Dans le fichier de test `frontend/src/store/messagesReducer.test.ts`, la fabrique de `StoredMessage` (ou les littéraux) doit recevoir `editedAt: null, deletedAt: null` — TypeScript indiquera chaque endroit.

`frontend/src/ui/MessageList.tsx` — un `content` nul ne doit plus rien afficher pour l'instant (la tâche 5 posera le libellé) :

```tsx
            <p className="whitespace-pre-wrap break-words">{message.content ?? ''}</p>
```

- [ ] **Step 11 : lancer les tests front**

Run: `make front-typecheck && make front-test`
Expected: PASS

- [ ] **Step 12 : les portes de qualité**

Run: `make static-code-analysis && make check-cs && make deptrac`
Expected: zéro violation. Si PHP-CS-Fixer signale des écarts, `make apply-cs` puis relancer.

- [ ] **Step 13 : commit**

```bash
git add backend/migrations backend/src backend/tests frontend/src
git commit -m "$(cat <<'EOF'
feat(message): rendre le contenu nullable pour preparer le tombstone

Le contenu d'un message supprime sera reellement efface, pas masque :
la colonne devient nullable et un CHECK lie `content` a `deleted_at`.

Aucun comportement nouveau — ce commit porte a lui seul le changement
cassant de MessageView, ce qui rend la suite additive.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 : supprimer pour tous

**Files:**
- Create: `backend/src/Shared/Domain/Exception/ProblemExceptionInterface.php`, `ForbiddenExceptionInterface.php`
- Create: `backend/src/Message/Domain/NotTheAuthorException.php`
- Create: `backend/src/Shared/Domain/Event/MessageWasDeleted.php`
- Create: `backend/src/Message/Application/Command/DeleteMessageCommand.php`, `DeleteMessageCommandHandler.php`
- Create: `backend/src/Message/Infrastructure/Http/DeleteMessageController.php`
- Create: `backend/src/Realtime/Application/EventListener/PublishMessageWasDeletedListener.php`
- Modify: `backend/src/Message/Domain/Message.php`, `MessageRepositoryInterface.php`, `MessageNotFoundException.php`
- Modify: `backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php`
- Modify: `backend/src/Shared/Infrastructure/Http/ProblemDetailsListener.php`
- Test: `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php`, `backend/tests/Functional/Message/DeleteMessageTest.php` (créé), `backend/tests/Functional/Message/MessagePaginationTest.php`

**Interfaces:**
- Consumes: `Message::isDeleted()`, `Message::content(): ?MessageContent`, `Message::deletedAt()` (tâche 1).
- Produces:
  - `Message::deleteForEveryone(UserId $actor, \DateTimeImmutable $now): void`
  - `MessageRepositoryInterface::ofId(ConversationId $conversationId, MessageId $messageId): Message` (lève `MessageNotFoundException`)
  - `MessageRepositoryInterface::save(Message $message): void`
  - `MessageNotFoundException::inConversation(ConversationId $conversationId, MessageId $messageId): self`
  - `MessageWasDeleted(MessageId $messageId, ConversationId $conversationId, UserId $senderId, \DateTimeImmutable $deletedAt)`
  - `DeleteMessageCommand(ConversationId $conversationId, MessageId $messageId, UserId $actorId)`
  - `ProblemExceptionInterface::problemSlug(): string`, `::problemTitle(): string`
  - `NotTheAuthorException::forMessage(MessageId $messageId): self`
  - Événement Mercure `message.deleted`, charge utile `{ id, conversation_id, sender_id, deleted_at }`, **sans `id` d'événement SSE**

- [ ] **Step 1 : écrire les tests de domaine**

Ajoute à `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php` (et l'import `use App\Message\Domain\NotTheAuthorException;` ainsi que la constante `private const string OTHER_USER_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA4';`) :

```php
    public function testDeletingForEveryoneErasesTheContentAndRecordsTheFact(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $deletedAt = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');

        $message->deleteForEveryone(UserId::fromString(self::AUTHOR_ID), $deletedAt);

        self::assertTrue($message->isDeleted());
        self::assertNull($message->content(), 'La charge utile doit etre reellement effacee.');
        self::assertEquals($deletedAt, $message->deletedAt());

        $events = $message->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessageWasDeleted::class, $events[0]);
        self::assertEquals($deletedAt, $events[0]->deletedAt);
    }

    public function testOnlyTheAuthorCanDelete(): void
    {
        $message = self::send();

        $this->expectException(NotTheAuthorException::class);

        $message->deleteForEveryone(UserId::fromString(self::OTHER_USER_ID), new \DateTimeImmutable());
    }

    /**
     * Le rejeu n'enregistre AUCUN evenement : c'est ce qui fait que DELETE reste
     * l'operation idempotente que HTTP promet, sans un seul `if` dans le handler.
     */
    public function testDeletingTwiceRecordsNothingAndKeepsTheFirstInstant(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $first = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');

        $message->deleteForEveryone(UserId::fromString(self::AUTHOR_ID), $first);
        $message->releaseEvents();

        $message->deleteForEveryone(
            UserId::fromString(self::AUTHOR_ID),
            new \DateTimeImmutable('2026-07-26T12:00:00+00:00'),
        );

        self::assertSame([], $message->releaseEvents());
        self::assertEquals($first, $message->deletedAt(), 'Le premier instant est conserve.');
    }
```

Ajoute l'import `use App\Shared\Domain\Event\MessageWasDeleted;`.

- [ ] **Step 2 : lancer les tests, vérifier qu'ils échouent**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: FAIL — `MessageWasDeleted` et `Message::deleteForEveryone()` n'existent pas.

- [ ] **Step 3 : créer l'événement partagé**

`backend/src/Shared/Domain/Event/MessageWasDeleted.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Message, ecoute par Realtime ET par Conversation — c'est ce qui le
 * fait vivre dans Shared plutot que dans son contexte d'origine.
 *
 * Il ne transporte AUCUN contenu, et ce n'est pas un oubli : un evenement de
 * retractation qui embarquerait la charge utile qu'il retracte la diffuserait
 * a tout le monde par le hub.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageWasDeleted implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public \DateTimeImmutable $deletedAt,
    ) {
    }
}
```

- [ ] **Step 4 : créer l'exception d'auteur et ses interfaces de support**

`backend/src/Shared/Domain/Exception/ProblemExceptionInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Une exception qui sait nommer SA classe de probleme, sans rien savoir de HTTP.
 *
 * Le domaine fournit un slug stable et un libelle ; c'est le listener de
 * Shared/Infrastructure, et lui seul, qui en fait une URI `/problems/...` et
 * choisit un statut. La regle « les exceptions de Domain ignorent le protocole »
 * tient donc toujours.
 */
interface ProblemExceptionInterface extends \Throwable
{
    /** Slug stable de la CLASSE de probleme, en kebab-case. Jamais de valeur variable. */
    public function problemSlug(): string;

    /** Libelle court, constant pour un slug donne. Le variable va dans le message. */
    public function problemTitle(): string;
}
```

`backend/src/Shared/Domain/Exception/ForbiddenExceptionInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marqueur : l'appartenance est etablie, seule l'autorisation manque. Traduit
 * en 403 — ce qui ne revele rien que l'appelant ne sache deja. Un non-membre,
 * lui, doit continuer de recevoir un 404 (NotFoundExceptionInterface).
 */
interface ForbiddenExceptionInterface extends ProblemExceptionInterface
{
}
```

`backend/src/Message/Domain/NotTheAuthorException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;
use App\Shared\Domain\Identifier\MessageId;

/**
 * Seul l'auteur edite ou supprime son message. Ce n'est pas un role, donc pas
 * l'affaire d'un voter : l'invariant vit dans l'agregat, la ou l'etat est connu.
 */
final class NotTheAuthorException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function forMessage(MessageId $messageId): self
    {
        return new self(sprintf('Le message %s n\'est pas le votre.', $messageId->toString()));
    }

    public function problemSlug(): string
    {
        return 'not-the-author';
    }

    public function problemTitle(): string
    {
        return 'Vous n\'etes pas l\'auteur de ce message';
    }
}
```

- [ ] **Step 5 : implémenter `deleteForEveryone()`**

Dans `backend/src/Message/Domain/Message.php` :

```php
    /**
     * « Supprimer pour tous » : record soft, payload hard. L'enregistrement
     * reste — id, expediteur, instant, donc l'ordre et les watermarks qui le
     * designent — mais la charge utile est reellement effacee.
     *
     * Rejouer la suppression n'enregistre AUCUN evenement et conserve le premier
     * instant. C'est ce qui fait de DELETE une operation idempotente par
     * construction, sans condition dans le handler ni dans la couche HTTP.
     */
    public function deleteForEveryone(UserId $actor, \DateTimeImmutable $now): void
    {
        if (!$this->senderId->equals($actor)) {
            throw NotTheAuthorException::forMessage($this->id);
        }

        if ($this->isDeleted()) {
            return;
        }

        $this->content = null;
        $this->deletedAt = $now;

        $this->recordEvent(new MessageWasDeleted($this->id, $this->conversationId, $this->senderId, $now));
    }
```

Ajoute les imports `App\Shared\Domain\Event\MessageWasDeleted`.

> Vérifie que `UserId::equals()` existe (il est hérité d'`AbstractUlidIdentifier`, comme `MessageId::equals()` utilisé par `SendMessageController`). Si le nom diffère, utiliser celui de la classe.

- [ ] **Step 6 : lancer les tests, vérifier qu'ils passent**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: PASS

- [ ] **Step 7 : commit du domaine**

```bash
git add backend/src/Shared/Domain backend/src/Message/Domain backend/tests
git commit -m "$(cat <<'EOF'
feat(message): supprimer un message pour tous, dans le domaine

L'agregat efface reellement sa charge utile et enregistre le fait. Rejouer
la suppression n'enregistre rien : DELETE reste idempotent sans condition
ailleurs dans la chaine.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 8 : élargir le port du repository**

`backend/src/Message/Domain/MessageRepositoryInterface.php` :

```php
    /**
     * Charge un message DANS SA CONVERSATION.
     *
     * Les deux identifiants sont exiges : un message demande dans le mauvais fil
     * est introuvable, point. La regle anti-oracle est ainsi portee par la
     * signature du port, pas par la vigilance de l'appelant.
     *
     * @throws MessageNotFoundException
     */
    public function ofId(ConversationId $conversationId, MessageId $messageId): Message;

    /** Persiste les colonnes mutables et collecte les evenements enregistres. */
    public function save(Message $message): void;
```

Ajoute les imports `App\Shared\Domain\Identifier\ConversationId` et `App\Shared\Domain\Identifier\MessageId`.

`backend/src/Message/Domain/MessageNotFoundException.php` — nouvelle fabrique :

```php
    public static function inConversation(ConversationId $conversationId, MessageId $messageId): self
    {
        return new self(sprintf(
            'Message %s introuvable dans la conversation %s.',
            $messageId->toString(),
            $conversationId->toString(),
        ));
    }
```

- [ ] **Step 9 : implémenter les deux méthodes en DBAL**

Dans `backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php` :

```php
    public function ofId(ConversationId $conversationId, MessageId $messageId): Message
    {
        // Les deux identifiants sont dans le WHERE : un message d'un autre fil
        // est introuvable, ce qui rend le 404 indistinguable d'un identifiant
        // inconnu sans que l'appelant ait a y penser.
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                FROM messages
                WHERE id = :id
                  AND conversation_id = :conversation_id
                SQL,
            [
                'id' => $messageId->toString(),
                'conversation_id' => $conversationId->toString(),
            ],
        );

        if (false === $row) {
            throw MessageNotFoundException::inConversation($conversationId, $messageId);
        }

        return $this->mapper->fromRow($row);
    }

    public function save(Message $message): void
    {
        // Seules les trois colonnes mutables. L'id, l'expediteur, la cle client
        // et l'instant d'envoi ne sont pas remplacables — c'est aussi pourquoi
        // la route est un PATCH et non un PUT.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE messages
                SET content = :content,
                    edited_at = :edited_at,
                    deleted_at = :deleted_at
                WHERE id = :id
                SQL,
            [
                'content' => $message->content()?->toString(),
                'edited_at' => $message->editedAt()?->format(\DateTimeInterface::ATOM),
                'deleted_at' => $message->deletedAt()?->format(\DateTimeInterface::ATOM),
                'id' => $message->id()->toString(),
            ],
        );

        $this->collector->collect(...$message->releaseEvents());
    }
```

Ajoute les imports `App\Shared\Domain\Identifier\ConversationId` et `App\Shared\Domain\Identifier\MessageId`.

- [ ] **Step 10 : écrire la commande et son handler**

`backend/src/Message/Application/Command/DeleteMessageCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final readonly class DeleteMessageCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        public UserId $actorId,
    ) {
    }
}
```

`backend/src/Message/Application/Command/DeleteMessageCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ConversationMembershipInterface $membership,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteMessageCommand $command): void
    {
        // Le controle vit ici, DANS la transaction, comme pour l'envoi. Message
        // passe par le contrat publie de Conversation, jamais par sa table.
        if (!$this->membership->isMember($command->conversationId, $command->actorId)) {
            throw ConversationNotAccessibleException::withId($command->conversationId);
        }

        $message = $this->messages->ofId($command->conversationId, $command->messageId);

        $message->deleteForEveryone($command->actorId, $this->clock->now());

        $this->messages->save($message);

        $this->logger->notice('Message {message_id} supprime pour tous', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
            'actor_id' => $command->actorId->toString(),
        ]);
    }
}
```

- [ ] **Step 11 : publier sur Mercure**

`backend/src/Realtime/Application/EventListener/PublishMessageWasDeletedListener.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasDeleted;

/**
 * Aucun `id` d'evenement SSE, et c'est une decision.
 *
 * L'id d'un evenement Mercure est l'ULID du message. Supprimer un message
 * ancien emettrait donc un id ANTERIEUR a ceux deja recus : le Last-Event-ID du
 * client reculerait, et le hub lui rejouerait tout l'historique depuis ce point
 * a la reconnexion suivante. Un identifiant de reprise qui recule est pire que
 * pas de reprise du tout.
 *
 * Consequence assumee : un client deconnecte pendant une suppression la
 * decouvre en rechargeant l'historique, qui porte deja l'etat a jour.
 */
final readonly class PublishMessageWasDeletedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasDeleted $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.deleted',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'deleted_at' => $event->deletedAt->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
```

- [ ] **Step 12 : traduire les nouveaux problèmes en HTTP**

Dans `backend/src/Shared/Infrastructure/Http/ProblemDetailsListener.php`, ajoute un bras dans `describe()`, **avant** le bras `AccessDeniedException` :

```php
            // Un probleme nomme par le domaine : le slug et le libelle viennent
            // de l'exception, le statut et la forme d'URI restent decides ici.
            $throwable instanceof ForbiddenExceptionInterface => [
                Response::HTTP_FORBIDDEN,
                sprintf('/problems/%s', $throwable->problemSlug()),
                $throwable->problemTitle(),
                $throwable->getMessage(),
            ],
```

Ajoute l'import `use App\Shared\Domain\Exception\ForbiddenExceptionInterface;`.

- [ ] **Step 13 : écrire le contrôleur**

`backend/src/Message/Infrastructure/Http/DeleteMessageController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Command\DeleteMessageCommand;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class DeleteMessageController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route(
        '/api/conversations/{conversationId}/messages/{messageId}',
        name: 'messages_delete',
        methods: ['DELETE'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        MessageId $messageId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // L'appartenance et la qualite d'auteur sont verifiees par le handler,
        // DANS la transaction. Les controler ici aussi laisserait croire que
        // c'est cette verification-la qui protege.
        $this->commands->dispatch(new DeleteMessageCommand(
            $conversationId,
            $messageId,
            $securityUser->userId(),
        ));

        // 204 y compris au rejeu : l'agregat n'enregistre rien la seconde fois,
        // donc rien n'est republie non plus.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

> `UlidIdentifierValueResolver` résout déjà les deux identifiants typés depuis la route (il est enregistré avec `priority: 150` dans `services.yaml`). Rien à ajouter à la configuration.

- [ ] **Step 14 : écrire le test fonctionnel**

`backend/tests/Functional/Message/DeleteMessageTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class DeleteMessageTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TC1';

    /** LE test de la tranche : la charge utile est reellement effacee. */
    public function testDeletingErasesTheContentInTheDatabase(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertResponseStatusCodeSame(204);

        $content = $this->connection->fetchOne(
            'SELECT content FROM messages WHERE id = :id',
            ['id' => $messageId],
        );

        self::assertNull($content, 'Le contenu doit etre efface, pas masque.');
    }

    public function testTheTombstoneStaysInTheHistoryWithoutContent(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));
        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));

        /** @var array{items: list<array{id: string, content: string|null, deleted_at: string|null}>} $page */
        $page = $this->json();

        $found = null;
        foreach ($page['items'] as $item) {
            if ($item['id'] === $messageId) {
                $found = $item;
            }
        }

        self::assertNotNull($found, 'Le tombstone doit rester dans l\'historique : les watermarks le designent.');
        self::assertNull($found['content']);
        self::assertNotNull($found['deleted_at']);
    }

    public function testDeletingTwiceAnswers204AndPublishesOnlyOnce(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $path = sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId);

        $this->client->request('DELETE', $path);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', $path);
        self::assertResponseStatusCodeSame(204);

        $deleted = array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.deleted' === $entry['type'],
        );

        self::assertCount(1, $deleted, 'Le rejeu ne doit rien republier.');
    }

    /** L'evenement de retractation ne transporte pas ce qu'il retracte. */
    public function testThePublishedEventCarriesNoContentAndNoEventId(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'secret');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        $deleted = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.deleted' === $entry['type'],
        ));

        self::assertCount(1, $deleted);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $deleted[0]['topic']);
        self::assertArrayNotHasKey('content', $deleted[0]['payload']);
        self::assertNull($deleted[0]['id'], 'Un id SSE qui recule casserait Last-Event-ID.');
    }

    public function testAnotherMemberCannotDeleteMyMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a moi');

        $this->login('bob');
        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string, title: string, status: int, correlation_id: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/not-the-author', $problem['type']);
    }

    /** Pas d'oracle : un identifiant inconnu et un message d'un autre fil sont indistinguables. */
    public function testAMessageFromAnotherConversationIsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'ailleurs');

        $otherConversationId = $this->secondConversationId();

        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/messages/%s', $otherConversationId, $messageId),
        );
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $wrongConversation */
        $wrongConversation = $this->json();

        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/messages/%s', $otherConversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TZZ'),
        );
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $unknown */
        $unknown = $this->json();

        self::assertSame($unknown['type'], $wrongConversation['type']);
        self::assertSame($unknown['title'], $wrongConversation['title']);
    }

    public function testItRequiresASession(): void
    {
        $this->client->request(
            'DELETE',
            '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZ1/messages/01J9ZQ7X8K3M4N5P6Q7R8S9TZ2',
        );

        self::assertResponseStatusCodeSame(401);
    }
}
```

> `firstConversationId()`, `secondConversationId()` et `send()` : reprends les helpers de `SendMessageTest`. S'ils y sont `private`, remonte-les dans `DatabaseTestCase` en `protected` plutôt que de les dupliquer, et adapte `SendMessageTest` en conséquence. `secondConversationId()` doit rendre une conversation des fixtures dont **alice est membre** et qui n'est pas la première ; si les fixtures n'en offrent pas, crée-la dans le test avec `POST /api/conversations`.

- [ ] **Step 15 : lancer le test fonctionnel**

Run: `make functional-test ARGS="--filter=DeleteMessageTest"`
Expected: PASS. Si un test échoue en 500, lis la réponse : le `correlation_id` du corps se retrouve dans les logs du conteneur (`make logs SERVICE=backend`).

- [ ] **Step 16 : vérifier que la pagination traverse un tombstone**

Le tombstone existe précisément pour ne pas creuser de trou dans la remontée keyset. Ouvre `backend/tests/Functional/Message/MessagePaginationTest.php`, repère la méthode qui sème les messages (elle insère en masse dans une conversation) et ajoute un test qui la réutilise :

```php
    /**
     * Le tombstone garde sa place dans l'ordre : c'est ce qui protege a la fois
     * la pagination keyset et les watermarks de la tranche 2, qui designent des
     * identifiants.
     */
    public function testDeletingAMessageLeavesNoHoleInThePagination(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        // Reutilise ici le helper de semis existant de ce fichier, avec 120 messages.
        $ids = $this->seedMessages($conversationId, 120);

        $middleId = $ids[60];
        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $middleId));
        self::assertResponseStatusCodeSame(204);

        $seen = [];
        $before = null;

        do {
            $query = null === $before ? 'limit=50' : sprintf('limit=50&before=%s', $before);
            $this->client->request('GET', sprintf('/api/conversations/%s/messages?%s', $conversationId, $query));

            /** @var array{items: list<array{id: string}>, next_before: string|null} $page */
            $page = $this->json();

            foreach ($page['items'] as $item) {
                $seen[] = $item['id'];
            }

            $before = $page['next_before'];
        } while (null !== $before);

        self::assertCount(120, $seen, 'Ni trou ni doublon : le tombstone compte toujours.');
        self::assertCount(120, array_unique($seen));
        self::assertContains($middleId, $seen);
    }
```

Adapte le nom et la signature de `seedMessages()` à ce que le fichier expose réellement ; s'il sème sans rendre les identifiants, lis-les avec un `SELECT id FROM messages WHERE conversation_id = :id ORDER BY id` sur `$this->connection`.

Run: `make functional-test ARGS="--filter=MessagePaginationTest"`
Expected: PASS

- [ ] **Step 17 : portes de qualité et commit**

Run: `make unit-test && make functional-test && make static-code-analysis && make check-cs && make deptrac`
Expected: tout vert.

> Aucun alias à ajouter dans `config/services.yaml` : `MessageRepositoryInterface` et `MessageReaderInterface` y sont déjà, et les nouvelles interfaces de `Shared/Domain/Exception/` sont des marqueurs, pas des services — `Domain/` est exclu du `resource`. Si la compilation du conteneur échoue sur un port sans adaptateur, c'est le signal qu'une ligne manque.

```bash
git add backend/src backend/tests
git commit -m "$(cat <<'EOF'
feat(message): exposer la suppression pour tous par l'API

DELETE sur un message imbrique dans sa conversation. Le contenu est efface
en base, le tombstone reste, et `message.deleted` est diffuse sans id SSE :
un Last-Event-ID qui recule serait pire que pas de reprise.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 : l'aperçu de conversation suit la suppression

Sans cette tâche, « payload hard » est faux : le contenu supprimé continue de s'afficher dans `conversations.last_message_preview`, à l'endroit le plus visible de l'application.

**Files:**
- Create: `backend/src/Conversation/Application/LastMessagePreview.php`
- Create: `backend/src/Conversation/Application/Command/RefreshLastMessagePreviewCommand.php`, `RefreshLastMessagePreviewCommandHandler.php`
- Create: `backend/src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasDeletedListener.php`
- Modify: `backend/src/Conversation/Application/LastMessagePointerWriterInterface.php`
- Modify: `backend/src/Conversation/Infrastructure/Persistence/DbalLastMessagePointerWriter.php`
- Modify: `backend/src/Conversation/Application/EventListener/RecordLastMessageOnMessageWasSentListener.php`
- Test: `backend/tests/Functional/Message/DeleteMessageTest.php`

**Interfaces:**
- Consumes: `MessageWasDeleted` (tâche 2).
- Produces:
  - `LastMessagePreview::MAX_LENGTH` (int, 80) et `LastMessagePreview::fromContent(string $content): string`
  - `LastMessagePointerWriterInterface::refreshPreview(ConversationId $conversationId, MessageId $messageId, ?string $preview): bool` — rend `true` si une ligne a été touchée
  - `RefreshLastMessagePreviewCommand(ConversationId $conversationId, MessageId $messageId, ?string $preview)`

- [ ] **Step 1 : écrire le test fonctionnel qui décrit l'attendu**

Ajoute à `backend/tests/Functional/Message/DeleteMessageTest.php` :

```php
    public function testDeletingTheLastMessageClearsThePreview(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'dernier');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertNull(
            $this->previewOf($conversationId),
            'Laisser l\'apercu rendrait « payload hard » faux.',
        );
    }

    /** La garde du WHERE : seul le message qui EST le pointeur touche l'apercu. */
    public function testDeletingAnOlderMessageLeavesThePreviewAlone(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $olderId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC2', 'ancien');
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC3', 'recent');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $olderId));

        self::assertSame('recent', $this->previewOf($conversationId));
    }

    private function previewOf(string $conversationId): ?string
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, last_message_preview: string|null}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                return $conversation['last_message_preview'];
            }
        }

        self::fail('Conversation absente de la liste.');
    }
```

- [ ] **Step 2 : lancer, vérifier l'échec**

Run: `make functional-test ARGS="--filter=DeleteMessageTest"`
Expected: `testDeletingTheLastMessageClearsThePreview` FAIL — l'aperçu vaut encore `dernier`. Les autres passent.

- [ ] **Step 3 : extraire la troncature de l'aperçu**

`backend/src/Conversation/Application/LastMessagePreview.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application;

/**
 * L'apercu est une COPIE tronquee du contenu, stockee par Conversation pour
 * eviter une jointure vers `messages` sur l'ecran d'accueil. Un seul endroit
 * decide de sa longueur, sinon deux listeners divergeraient en silence.
 */
final class LastMessagePreview
{
    public const int MAX_LENGTH = 80;

    public static function fromContent(string $content): string
    {
        return mb_substr($content, 0, self::MAX_LENGTH);
    }
}
```

Dans `RecordLastMessageOnMessageWasSentListener`, supprime la constante privée `PREVIEW_LENGTH` et remplace l'argument par `LastMessagePreview::fromContent($event->content)`.

- [ ] **Step 4 : élargir le port du pointeur**

`backend/src/Conversation/Application/LastMessagePointerWriterInterface.php` :

```php
    /**
     * Reecrit l'apercu SI le message designe est toujours le dernier.
     *
     * `null` efface l'apercu : c'est le cas d'une suppression pour tous, ou la
     * copie doit disparaitre en meme temps que l'original.
     *
     * @return bool true si une ligne a ete touchee
     */
    public function refreshPreview(
        ConversationId $conversationId,
        MessageId $messageId,
        ?string $preview,
    ): bool;
```

- [ ] **Step 5 : implémenter l'`UPDATE` gardé**

Dans `backend/src/Conversation/Infrastructure/Persistence/DbalLastMessagePointerWriter.php` :

```php
    public function refreshPreview(
        ConversationId $conversationId,
        MessageId $messageId,
        ?string $preview,
    ): bool {
        // `AND last_message_id = :message_id` fait tout le travail : si le
        // message n'est plus le dernier, zero ligne touchee. La condition et
        // l'ecriture sont la MEME instruction, donc aucune course possible entre
        // un SELECT et un UPDATE.
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversations
                SET last_message_preview = :preview
                WHERE id = :conversation_id
                  AND last_message_id = :message_id
                SQL,
            [
                'preview' => $preview,
                'conversation_id' => $conversationId->toString(),
                'message_id' => $messageId->toString(),
            ],
        );

        return $affected > 0;
    }
```

- [ ] **Step 6 : écrire la commande, son handler et le listener**

`backend/src/Conversation/Application/Command/RefreshLastMessagePreviewCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

final readonly class RefreshLastMessagePreviewCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        /** `null` efface l'apercu : le message a ete supprime pour tous. */
        public ?string $preview,
    ) {
    }
}
```

`backend/src/Conversation/Application/Command/RefreshLastMessagePreviewCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Application\LastMessagePointerWriterInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RefreshLastMessagePreviewCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private LastMessagePointerWriterInterface $pointer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshLastMessagePreviewCommand $command): void
    {
        $refreshed = $this->pointer->refreshPreview(
            $command->conversationId,
            $command->messageId,
            $command->preview,
        );

        if ($refreshed) {
            return;
        }

        // Cas nominal et frequent : le message n'est plus le dernier du fil,
        // l'apercu ne le concerne donc pas. Rien a corriger.
        $this->logger->debug('Message {message_id} n\'est plus le pointeur : apercu inchange', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
```

`backend/src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasDeletedListener.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RefreshLastMessagePreviewCommand;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasDeleted;

/**
 * Sans ce listener, « record soft, payload hard » serait faux : le contenu
 * efface de `messages` survivrait dans la copie que Conversation garde pour son
 * ecran d'accueil.
 *
 * Message ne fait PAS cet UPDATE : il publie un fait, Conversation reagit avec
 * SA propre commande.
 */
final readonly class RefreshPreviewOnMessageWasDeletedListener implements DomainEventListenerInterface
{
    public function __construct(private CommandDispatcherInterface $commands)
    {
    }

    public function __invoke(MessageWasDeleted $event): void
    {
        $this->commands->dispatch(new RefreshLastMessagePreviewCommand(
            $event->conversationId,
            $event->messageId,
            null,
        ));
    }
}
```

- [ ] **Step 7 : lancer les tests fonctionnels**

Run: `make functional-test ARGS="--filter=DeleteMessageTest"`
Expected: PASS, les deux nouveaux compris.

- [ ] **Step 8 : portes de qualité et commit**

Run: `make unit-test && make functional-test && make static-code-analysis && make check-cs && make deptrac`
Expected: tout vert. `deptrac` doit rester à zéro violation : le listener vit dans `Conversation/Application` et ne référence que `Shared`.

```bash
git add backend/src backend/tests
git commit -m "$(cat <<'EOF'
feat(conversation): effacer l'apercu quand le dernier message est supprime

Choregraphie : Message publie, Conversation reecrit SA table. La garde
`AND last_message_id = :message_id` suffit a ne rien toucher quand le
message n'est plus le pointeur.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 : éditer un message

**Files:**
- Create: `backend/src/Shared/Domain/Exception/ConflictExceptionInterface.php`
- Create: `backend/src/Message/Domain/EditWindowExpiredException.php`, `MessageAlreadyDeletedException.php`
- Create: `backend/src/Shared/Domain/Event/MessageWasEdited.php`
- Create: `backend/src/Message/Application/Command/EditMessageCommand.php`, `EditMessageCommandHandler.php`
- Create: `backend/src/Message/Application/Query/GetMessageQuery.php`, `GetMessageQueryHandler.php`
- Create: `backend/src/Message/Infrastructure/Http/EditMessageController.php`, `Payload/EditMessagePayload.php`
- Create: `backend/src/Realtime/Application/EventListener/PublishMessageWasEditedListener.php`
- Create: `backend/src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasEditedListener.php`
- Modify: `backend/src/Message/Domain/Message.php`
- Modify: `backend/src/Message/Application/Query/MessageReaderInterface.php`, `backend/src/Message/Infrastructure/Persistence/DbalMessageReader.php`
- Modify: `backend/src/Shared/Infrastructure/Http/ProblemDetailsListener.php`
- Test: `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php`, `backend/tests/Functional/Message/EditMessageTest.php` (créé)

**Interfaces:**
- Consumes: tout ce que produisent les tâches 1 à 3.
- Produces:
  - `Message::EDIT_WINDOW_SECONDS` (int, 900)
  - `Message::edit(UserId $editor, MessageContent $content, \DateTimeImmutable $now): void`
  - `MessageWasEdited(MessageId $messageId, ConversationId $conversationId, UserId $senderId, string $content, \DateTimeImmutable $editedAt)`
  - `EditMessageCommand(ConversationId $conversationId, MessageId $messageId, UserId $editorId, MessageContent $content)`
  - `GetMessageQuery(ConversationId $conversationId, MessageId $messageId, UserId $requestedBy)` — `QueryInterface<MessageView>`
  - `MessageReaderInterface::view(ConversationId $conversationId, MessageId $messageId): ?MessageView`
  - Événement Mercure `message.edited`, charge utile `{ id, conversation_id, sender_id, content, edited_at }`, sans `id` d'événement SSE

- [ ] **Step 1 : écrire les tests de domaine**

Ajoute à `backend/tests/Unit/Message/Domain/MessageLifecycleTest.php` :

```php
    public function testEditingReplacesTheContentAndStampsTheInstant(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $editedAt = new \DateTimeImmutable('2026-07-26T09:05:00+00:00');

        $message->edit(UserId::fromString(self::AUTHOR_ID), MessageContent::fromString('bonsoir'), $editedAt);

        self::assertSame('bonsoir', $message->content()?->toString());
        self::assertEquals($editedAt, $message->editedAt());

        $events = $message->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessageWasEdited::class, $events[0]);
        self::assertSame('bonsoir', $events[0]->content);
    }

    public function testEditingIsAllowedUntilTheWindowCloses(): void
    {
        $message = self::send();

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('juste a temps'),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00')
                ->modify(sprintf('+%d seconds', Message::EDIT_WINDOW_SECONDS)),
        );

        self::assertSame('juste a temps', $message->content()?->toString());
    }

    public function testEditingAfterTheWindowIsRefused(): void
    {
        $message = self::send();

        $this->expectException(EditWindowExpiredException::class);

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('trop tard'),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00')
                ->modify(sprintf('+%d seconds', Message::EDIT_WINDOW_SECONDS + 1)),
        );
    }

    public function testOnlyTheAuthorCanEdit(): void
    {
        $message = self::send();

        $this->expectException(NotTheAuthorException::class);

        $message->edit(
            UserId::fromString(self::OTHER_USER_ID),
            MessageContent::fromString('pas a moi'),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );
    }

    public function testATombstoneCannotBeEdited(): void
    {
        $message = self::send();
        $message->deleteForEveryone(
            UserId::fromString(self::AUTHOR_ID),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );

        $this->expectException(MessageAlreadyDeletedException::class);

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('ressusciter'),
            new \DateTimeImmutable('2026-07-26T09:02:00+00:00'),
        );
    }

    /** Meme mecanique que le rejeu d'envoi : rien d'enregistre, donc rien de republie. */
    public function testEditingWithTheSameContentRecordsNothing(): void
    {
        $message = self::send();
        $message->releaseEvents();

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('bonjour'),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );

        self::assertSame([], $message->releaseEvents());
        self::assertNull($message->editedAt(), 'Un no-op ne marque pas le message comme modifie.');
    }
```

Ajoute les imports `App\Message\Domain\EditWindowExpiredException`, `App\Message\Domain\MessageAlreadyDeletedException`, `App\Shared\Domain\Event\MessageWasEdited`.

- [ ] **Step 2 : lancer, vérifier l'échec**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: FAIL — `Message::edit()` n'existe pas.

- [ ] **Step 3 : créer l'interface de conflit et les deux exceptions**

`backend/src/Shared/Domain/Exception/ConflictExceptionInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marqueur : l'appelant a le droit d'agir, mais l'ETAT de la ressource rend
 * l'operation impossible. Traduit en 409 — le client peut en deduire une action
 * utile : rafraichir, sa vue est perimee.
 */
interface ConflictExceptionInterface extends ProblemExceptionInterface
{
}
```

`backend/src/Message/Domain/EditWindowExpiredException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;

/**
 * Supprimer tardivement reste legitime : le regret n'a pas de date de
 * peremption, et le resultat est un tombstone visible de tous, donc honnete.
 * Editer tardivement REECRIT l'histoire d'une conversation deja lue, sans que
 * rien ne dise aux destinataires ce que le message disait. D'ou l'asymetrie.
 */
final class EditWindowExpiredException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function create(): self
    {
        return new self(sprintf(
            'Un message n\'est modifiable que dans les %d minutes suivant son envoi.',
            intdiv(Message::EDIT_WINDOW_SECONDS, 60),
        ));
    }

    public function problemSlug(): string
    {
        return 'edit-window-expired';
    }

    public function problemTitle(): string
    {
        return 'Ce message n\'est plus modifiable';
    }
}
```

`backend/src/Message/Domain/MessageAlreadyDeletedException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\MessageId;

final class MessageAlreadyDeletedException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function forMessage(MessageId $messageId): self
    {
        return new self(sprintf('Le message %s a ete supprime.', $messageId->toString()));
    }

    public function problemSlug(): string
    {
        return 'message-already-deleted';
    }

    public function problemTitle(): string
    {
        return 'Ce message a ete supprime';
    }
}
```

- [ ] **Step 4 : créer l'événement partagé**

`backend/src/Shared/Domain/Event/MessageWasEdited.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Message, ecoute par Realtime ET par Conversation.
 *
 * Le contenu voyage en `string`, PAS en MessageContent : un evenement partage
 * ne transporte que des types de Shared et des scalaires.
 *
 * Il porte un ETAT complet, pas un delta. Un « ajouter 3 caracteres en position
 * 12 » exigerait un ordre de livraison garanti, que SSE ne promet pas. Un etat
 * complet est idempotent et commutatif : c'est ce qui permet de se passer
 * d'accuse, de sequence et de rejeu.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageWasEdited implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public string $content,
        public \DateTimeImmutable $editedAt,
    ) {
    }
}
```

- [ ] **Step 5 : implémenter `edit()`**

Dans `backend/src/Message/Domain/Message.php`, ajoute la constante en tête de classe et la méthode :

```php
    /**
     * Quinze minutes. C'est une regle metier, pas un reglage d'exploitation :
     * elle vit donc dans l'agregat et non dans la configuration.
     */
    public const int EDIT_WINDOW_SECONDS = 900;
```

```php
    /**
     * Editer avec le contenu actuel n'enregistre AUCUN evenement — meme
     * mecanique que le rejeu d'envoi : rien d'enregistre, donc rien de republie,
     * sans un seul `if` dans le handler.
     */
    public function edit(UserId $editor, MessageContent $content, \DateTimeImmutable $now): void
    {
        if (!$this->senderId->equals($editor)) {
            throw NotTheAuthorException::forMessage($this->id);
        }

        if ($this->isDeleted()) {
            throw MessageAlreadyDeletedException::forMessage($this->id);
        }

        if ($now->getTimestamp() - $this->createdAt->getTimestamp() > self::EDIT_WINDOW_SECONDS) {
            throw EditWindowExpiredException::create();
        }

        if ($content->toString() === $this->content?->toString()) {
            return;
        }

        $this->content = $content;
        $this->editedAt = $now;

        $this->recordEvent(new MessageWasEdited(
            $this->id,
            $this->conversationId,
            $this->senderId,
            $content->toString(),
            $now,
        ));
    }
```

Ajoute l'import `App\Shared\Domain\Event\MessageWasEdited`.

> L'ordre des gardes est significatif : auteur d'abord (ne rien révéler à qui n'a rien à faire ici), état ensuite, temps enfin. Ne pas le réarranger.

- [ ] **Step 6 : lancer les tests, vérifier qu'ils passent**

Run: `make unit-test ARGS="--filter=MessageLifecycleTest"`
Expected: PASS

- [ ] **Step 7 : commit du domaine**

```bash
git add backend/src backend/tests
git commit -m "$(cat <<'EOF'
feat(message): editer un message dans les quinze minutes

La fenetre est un invariant de l'agregat, teste avec une horloge gelee.
Editer avec le contenu actuel n'enregistre rien, donc ne republie rien.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 8 : la lecture d'un message unique**

`backend/src/Message/Application/Query/MessageReaderInterface.php` — ajoute :

```php
    public function view(ConversationId $conversationId, MessageId $messageId): ?MessageView;
```

avec l'import `App\Shared\Domain\Identifier\ConversationId`.

`backend/src/Message/Infrastructure/Persistence/DbalMessageReader.php` — ajoute :

```php
    public function view(ConversationId $conversationId, MessageId $messageId): ?MessageView
    {
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                FROM messages
                WHERE id = :id
                  AND conversation_id = :conversation_id
                SQL,
            [
                'id' => $messageId->toString(),
                'conversation_id' => $conversationId->toString(),
            ],
        );

        if (false === $row) {
            return null;
        }

        return new MessageView(
            $row['id'],
            $row['conversation_id'],
            $row['sender_id'],
            $row['content'],
            $row['client_message_id'],
            DatabaseTimestamp::toAtom($row['created_at']),
            DatabaseTimestamp::toAtom($row['edited_at']),
            DatabaseTimestamp::toAtom($row['deleted_at']),
        );
    }
```

avec les imports `App\Message\Application\Query\MessageView`, `App\Shared\Domain\Identifier\ConversationId`, `App\Shared\Infrastructure\Persistence\DatabaseTimestamp`.

`backend/src/Message/Application/Query/GetMessageQuery.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/** @implements QueryInterface<MessageView> */
final readonly class GetMessageQuery implements QueryInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        public UserId $requestedBy,
    ) {
    }
}
```

> Vérifie la forme exacte de `QueryInterface` (paramétrée par son résultat) sur `GetMessagePageQuery` et calque-la.

`backend/src/Message/Application/Query/GetMessageQueryHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageNotFoundException;
use App\Shared\Application\Bus\QueryHandlerInterface;

final readonly class GetMessageQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private MessageReaderInterface $messages,
        private ConversationMembershipInterface $membership,
    ) {
    }

    public function __invoke(GetMessageQuery $query): MessageView
    {
        // 404 et non 403 : un 403 confirmerait l'existence de la conversation.
        if (!$this->membership->isMember($query->conversationId, $query->requestedBy)) {
            throw ConversationNotAccessibleException::withId($query->conversationId);
        }

        $view = $this->messages->view($query->conversationId, $query->messageId);

        if (null === $view) {
            throw MessageNotFoundException::inConversation($query->conversationId, $query->messageId);
        }

        return $view;
    }
}
```

- [ ] **Step 9 : la commande, son handler, le payload et le contrôleur**

`backend/src/Message/Application/Command/EditMessageCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Message\Domain\MessageContent;
use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final readonly class EditMessageCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        public UserId $editorId,
        public MessageContent $content,
    ) {
    }
}
```

`backend/src/Message/Application/Command/EditMessageCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class EditMessageCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private ConversationMembershipInterface $membership,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EditMessageCommand $command): void
    {
        if (!$this->membership->isMember($command->conversationId, $command->editorId)) {
            throw ConversationNotAccessibleException::withId($command->conversationId);
        }

        $message = $this->messages->ofId($command->conversationId, $command->messageId);

        $message->edit($command->editorId, $command->content, $this->clock->now());

        $this->messages->save($message);

        // Jamais le contenu, meme tronque : seulement sa longueur.
        $this->logger->notice('Message {message_id} modifie', [
            'message_id' => $command->messageId->toString(),
            'conversation_id' => $command->conversationId->toString(),
            'editor_id' => $command->editorId->toString(),
            'content_length' => mb_strlen($command->content->toString()),
        ]);
    }
}
```

`backend/src/Message/Infrastructure/Http/Payload/EditMessagePayload.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http\Payload;

use App\Message\Domain\MessageContent;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class EditMessagePayload
{
    public function __construct(
        // Garde de bordure sur l'entree BRUTE, qui rend une violation nommee.
        // MessageContent revalide sur la chaine rognee : c'est l'invariant du
        // domaine, et il est le seul a faire foi.
        #[Assert\NotBlank(message: 'Un message ne peut pas etre vide.')]
        #[Assert\Length(
            max: MessageContent::MAX_LENGTH,
            maxMessage: 'Un message ne peut pas depasser {{ limit }} caracteres.',
        )]
        public string $content = '',
    ) {
    }
}
```

`backend/src/Message/Infrastructure/Http/EditMessageController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Command\EditMessageCommand;
use App\Message\Application\Query\GetMessageQuery;
use App\Message\Domain\MessageContent;
use App\Message\Infrastructure\Http\Payload\EditMessagePayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class EditMessageController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route(
        '/api/conversations/{conversationId}/messages/{messageId}',
        name: 'messages_edit',
        methods: ['PATCH'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        MessageId $messageId,
        #[MapRequestPayload] EditMessagePayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $editorId = $securityUser->userId();

        $this->commands->dispatch(new EditMessageCommand(
            $conversationId,
            $messageId,
            $editorId,
            MessageContent::fromString($payload->content),
        ));

        // Le handler rend `void` : pour connaitre l'effet de l'ecriture, on pose
        // une query. C'est la separation CQS, pas une gene a contourner.
        $view = $this->queries->ask(new GetMessageQuery($conversationId, $messageId, $editorId));

        return new JsonResponse($view->toArray());
    }
}
```

- [ ] **Step 10 : publier et rafraîchir l'aperçu**

`backend/src/Realtime/Application/EventListener/PublishMessageWasEditedListener.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasEdited;

/**
 * Aucun `id` d'evenement SSE : editer un message ancien emettrait un id
 * ANTERIEUR a ceux deja recus, et le Last-Event-ID du client reculerait. Meme
 * raison que pour `message.deleted`.
 *
 * Pas de `client_message_id` non plus, et ce n'est pas un oubli : la cle de
 * reconciliation existe deja, c'est l'`id` serveur. Le message est en base, le
 * front le connait, et l'echo SSE comme la reponse du PATCH portent le meme
 * etat final.
 */
final readonly class PublishMessageWasEditedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasEdited $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.edited',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'content' => $event->content,
                'edited_at' => $event->editedAt->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
```

`backend/src/Conversation/Application/EventListener/RefreshPreviewOnMessageWasEditedListener.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RefreshLastMessagePreviewCommand;
use App\Conversation\Application\LastMessagePreview;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasEdited;

/** Meme choregraphie que pour la suppression : Message publie, Conversation reecrit SA table. */
final readonly class RefreshPreviewOnMessageWasEditedListener implements DomainEventListenerInterface
{
    public function __construct(private CommandDispatcherInterface $commands)
    {
    }

    public function __invoke(MessageWasEdited $event): void
    {
        $this->commands->dispatch(new RefreshLastMessagePreviewCommand(
            $event->conversationId,
            $event->messageId,
            LastMessagePreview::fromContent($event->content),
        ));
    }
}
```

- [ ] **Step 11 : traduire le 409**

Dans `ProblemDetailsListener::describe()`, ajoute juste après le bras `ForbiddenExceptionInterface` :

```php
            $throwable instanceof ConflictExceptionInterface => [
                Response::HTTP_CONFLICT,
                sprintf('/problems/%s', $throwable->problemSlug()),
                $throwable->problemTitle(),
                $throwable->getMessage(),
            ],
```

avec l'import `use App\Shared\Domain\Exception\ConflictExceptionInterface;`.

- [ ] **Step 12 : écrire le test fonctionnel**

`backend/tests/Functional/Message/EditMessageTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class EditMessageTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TE1';

    public function testEditingReturnsTheUpdatedView(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $this->edit($conversationId, $messageId, 'bonjour');

        self::assertResponseStatusCodeSame(200);

        /** @var array{id: string, content: string|null, edited_at: string|null} $view */
        $view = $this->json();

        self::assertSame($messageId, $view['id']);
        self::assertSame('bonjour', $view['content']);
        self::assertNotNull($view['edited_at']);
    }

    public function testEditingTheLastMessageRefreshesThePreview(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $this->edit($conversationId, $messageId, 'bonjour');

        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, last_message_preview: string|null}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                self::assertSame('bonjour', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    public function testEditingAnOlderMessageLeavesThePreviewAlone(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $olderId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TE2', 'ancien');
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TE3', 'recent');

        $this->edit($conversationId, $olderId, 'ancien corrige');

        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, last_message_preview: string|null}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                self::assertSame('recent', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    public function testThePublishedEventCarriesTheNewContentWithoutAnEventId(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->edit($conversationId, $messageId, 'bonjour');

        $edited = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.edited' === $entry['type'],
        ));

        self::assertCount(1, $edited);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $edited[0]['topic']);
        self::assertSame('bonjour', $edited[0]['payload']['content']);
        self::assertNull($edited[0]['id']);
    }

    public function testEditingATombstoneConflicts(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        $this->edit($conversationId, $messageId, 'ressusciter');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/message-already-deleted', $problem['type']);
    }

    public function testAnotherMemberCannotEditMyMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a moi');

        $this->login('bob');
        $this->edit($conversationId, $messageId, 'pas a moi');

        self::assertResponseStatusCodeSame(403);

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/not-the-author', $problem['type']);
    }

    public function testAnEmptyContentIsRejectedWithViolations(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjour');

        $this->edit($conversationId, $messageId, '   ');

        self::assertResponseStatusCodeSame(422);

        /** @var array{type: string, violations?: list<array{field: string, message: string}>} $problem */
        $problem = $this->json();
        self::assertSame('/problems/validation-failed', $problem['type']);
    }

    private function edit(string $conversationId, string $messageId, string $content): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['content' => $content], \JSON_THROW_ON_ERROR),
        );
    }
}
```

> `testAnEmptyContentIsRejectedWithViolations` : `#[MapRequestPayload]` valide `NotBlank` sur la chaîne brute `'   '`, qui n'est pas vide au sens de `NotBlank` — si le test rend 422 par `EmptyMessageContentException` (déjà `InvalidInputExceptionInterface`, donc 422) plutôt que par une violation, c'est correct : l'assertion porte sur le `type`, qui est le même dans les deux chemins.

- [ ] **Step 13 : lancer les tests**

Run: `make functional-test ARGS="--filter=EditMessageTest"`
Expected: PASS

- [ ] **Step 14 : portes de qualité et commit**

Run: `make unit-test && make functional-test && make static-code-analysis && make check-cs && make deptrac`
Expected: tout vert.

```bash
git add backend/src backend/tests
git commit -m "$(cat <<'EOF'
feat(message): exposer l'edition par l'API et la diffuser

PATCH imbrique sous la conversation, reponse 200 obtenue par une query —
le handler rend void. `message.edited` porte un etat complet, donc
idempotent et commutatif : ni accuse ni sequence n'est necessaire.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 : front — supprimer un message

**Files:**
- Modify: `frontend/src/store/messagesReducer.ts`, `frontend/src/store/messagesReducer.test.ts`
- Modify: `frontend/src/api/client.ts`, `frontend/src/hooks/useAppState.ts`
- Modify: `frontend/src/ui/MessageList.tsx`, `frontend/src/ui/ConversationView.tsx`, `frontend/src/ui/labels.ts`
- Create: `frontend/src/ui/MessageActions.tsx`

**Interfaces:**
- Consumes: `StoredMessage.content: string | null`, `deletedAt` (tâche 1) ; événement Mercure `message.deleted` (tâche 2).
- Produces:
  - action `{ type: 'message/deleted'; conversationId: string; id: string; deletedAt: string }`
  - `api.deleteMessage(conversationId: string, messageId: string): Promise<void>`
  - `deletedMessageLabel: string` exporté de `labels.ts`
  - `<MessageActions onEdit={...} onDelete={...} />`
  - `ConversationView` prop `onDeleteMessage: (messageId: string) => Promise<void>`

- [ ] **Step 1 : écrire le test du reducer**

Ajoute à `frontend/src/store/messagesReducer.test.ts` (adapte le nom de la fabrique de message à celle qui existe déjà dans le fichier) :

```ts
describe('message/deleted', () => {
  it('efface le contenu du message vise et le marque supprime', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'a effacer' }),
    });

    const next = messagesReducer(state, {
      type: 'message/deleted',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      deletedAt: '2026-07-26T11:00:00+00:00',
    });

    const item = selectThread(next, 'c1').items[0];
    expect(item.content).toBeNull();
    expect(item.deletedAt).toBe('2026-07-26T11:00:00+00:00');
  });

  // Le message n'a jamais ete charge : la page d'historique qui le contiendra
  // le lira deja a jour. Ne rien faire est le comportement correct.
  it('ignore un identifiant absent du fil', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'intact' }),
    });

    const next = messagesReducer(state, {
      type: 'message/deleted',
      conversationId: 'c1',
      id: '01J0000000000000000000000Z',
      deletedAt: '2026-07-26T11:00:00+00:00',
    });

    expect(selectThread(next, 'c1').items[0].content).toBe('intact');
  });

  // L'echo SSE arrive avant la reponse du DELETE : appliquer deux fois le meme
  // etat complet doit donner le meme resultat.
  it('est idempotent', () => {
    const base = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'a effacer' }),
    });

    const action = {
      type: 'message/deleted' as const,
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      deletedAt: '2026-07-26T11:00:00+00:00',
    };

    expect(messagesReducer(messagesReducer(base, action), action)).toEqual(
      messagesReducer(base, action),
    );
  });
});
```

> `aMessage(overrides)` : si le fichier de test n'a pas déjà une telle fabrique, écris-la en tête du fichier — elle rend un `StoredMessage` complet (`conversationId: 'c1'`, `clientMessageId`, `senderId`, `createdAt`, `editedAt: null`, `deletedAt: null`, `status: 'sent'`) fusionné avec `overrides`.

- [ ] **Step 2 : lancer, vérifier l'échec**

Run: `make front-test`
Expected: FAIL — l'action `message/deleted` n'existe pas dans le type `MessagesAction`.

- [ ] **Step 3 : implémenter l'action**

Dans `frontend/src/store/messagesReducer.ts`, ajoute au type `MessagesAction` :

```ts
  | { type: 'message/deleted'; conversationId: string; id: string; deletedAt: string };
```

et le bras correspondant dans `messagesReducer` :

```ts
    case 'message/deleted':
      // Applique par `id` SERVEUR : contrairement a l'envoi, il n'y a pas de
      // passe `client_message_id` a faire — le message est deja persiste, donc
      // la cle de reconciliation existe.
      //
      // Un `id` absent du fil ne declenche rien : le message n'a jamais ete
      // charge, et la page d'historique qui le contiendra le lira deja a jour.
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.id === action.id
            ? { ...item, content: null, deletedAt: action.deletedAt }
            : item,
        ),
      }));
```

- [ ] **Step 4 : lancer, vérifier le succès**

Run: `make front-test && make front-typecheck`
Expected: PASS

- [ ] **Step 5 : appeler l'API et écouter l'événement**

`frontend/src/api/client.ts` — ajoute dans l'objet `api` :

```ts
  deleteMessage: (conversationId: string, messageId: string) =>
    request<void>(`/api/conversations/${conversationId}/messages/${messageId}`, {
      method: 'DELETE',
    }),
```

`frontend/src/hooks/useAppState.ts` :

1. `NAMED_EVENTS` reçoit `'message.deleted'`. **Sans cette ligne, le front reste muet alors que le hub diffuse correctement** — `EventSource.onmessage` ne se déclenche que pour les événements sans nom.
2. Dans le `onEvent` du `RealtimeClient`, avant le bras `receipt.updated` :

```ts
        if (event.type === 'message.deleted') {
          dispatch({
            type: 'message/deleted',
            conversationId: readString(event.payload, 'conversation_id'),
            id: readString(event.payload, 'id'),
            deletedAt: readString(event.payload, 'deleted_at'),
          });

          // L'apercu de la colonne de gauche a change lui aussi.
          scheduleConversationsRefresh();

          return;
        }
```

3. Expose une action de suppression, à côté de celle d'envoi (calque sa forme sur celle qui existe) :

```ts
  const deleteMessage = useCallback(
    async (conversationId: string, messageId: string) => {
      await api.deleteMessage(conversationId, messageId);
      // Pas de dispatch ici : l'echo SSE pose l'etat, et il est idempotent.
      // Si le hub est injoignable, le rechargement de l'historique corrigera.
    },
    [],
  );
```

et ajoute `deleteMessage` à la valeur rendue par le hook.

- [ ] **Step 6 : le libellé et le rendu du tombstone**

`frontend/src/ui/labels.ts` :

```ts
/**
 * Le serveur ne dit jamais « ce message a ete supprime » : il dit qu'il n'y a
 * plus de charge utile. Le libelle est de la presentation, il vit donc ici — et
 * pourra etre traduit sans toucher a l'API.
 */
export const deletedMessageLabel = 'Ce message a été supprimé';
```

`frontend/src/ui/MessageActions.tsx` :

```tsx
type Props = {
  onDelete: () => void;
};

/**
 * Actions au survol, sur ses propres messages vivants uniquement. Le menu est
 * rendu en permanence dans le DOM et revele par `group-hover` : le monter au
 * survol ferait sauter la hauteur de la bulle.
 */
export function MessageActions({ onDelete }: Props) {
  return (
    <div className="absolute right-1 top-1 hidden gap-1 group-hover:flex">
      <button
        type="button"
        onClick={onDelete}
        className="rounded bg-white/10 px-1 text-xs hover:bg-white/20"
        aria-label="Supprimer le message"
      >
        Supprimer
      </button>
    </div>
  );
}
```

`frontend/src/ui/MessageList.tsx` :

- `Props` reçoit `onDeleteMessage: (messageId: string) => void`.
- le `<li>` gagne `relative group` dans ses classes ;
- le corps du message devient :

```tsx
            {message.deletedAt !== null ? (
              <p className="italic opacity-60">{deletedMessageLabel}</p>
            ) : (
              <p className="whitespace-pre-wrap break-words">{message.content}</p>
            )}

            {/*
              Seulement sur SES propres messages vivants et acquittes : un
              message optimiste n'a pas encore d'`id` serveur a envoyer.
            */}
            {message.senderId === meId && message.id !== null && message.deletedAt === null && (
              <MessageActions
                onDelete={() => {
                  if (window.confirm('Supprimer ce message pour tout le monde ?')) {
                    onDeleteMessage(message.id as string);
                  }
                }}
              />
            )}
```

Ajoute les imports `deletedMessageLabel` depuis `./labels` et `MessageActions`.

Masque aussi les coches de réception sur un tombstone : ajoute `&& message.deletedAt === null` à la condition qui rend `<ReceiptTicks />`.

- [ ] **Step 7 : câbler la vue**

`frontend/src/ui/ConversationView.tsx` : `Props` reçoit `onDeleteMessage: (messageId: string) => void`, déstructuré et passé à `<MessageList onDeleteMessage={onDeleteMessage} />`. Dans `App.tsx` (ou le composant qui monte `ConversationView`), branche-le sur `deleteMessage` du hook, en fermant sur l'identifiant de la conversation ouverte.

- [ ] **Step 8 : vérifier dans l'application**

Run: `make up && make restart SERVICE=frontend`
Ouvre `localhost:8080` dans deux navigateurs, connecte alice et bob (mot de passe `password`). Alice envoie un message, le supprime : bob doit voir « Ce message a été supprimé » **sans rafraîchir**, et l'aperçu de la colonne de gauche doit se vider.

- [ ] **Step 9 : portes de qualité et commit**

Run: `make front-test && make front-typecheck`
Expected: PASS

```bash
git add frontend/src
git commit -m "$(cat <<'EOF'
feat(front): supprimer un message et afficher le tombstone

L'action est appliquee par id serveur et pose un etat complet, donc
rejouable sans effet de bord : l'echo SSE arrive avant la reponse du DELETE.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6 : front — éditer un message

**Files:**
- Modify: `frontend/src/store/messagesReducer.ts`, `frontend/src/store/messagesReducer.test.ts`
- Modify: `frontend/src/api/client.ts`, `frontend/src/hooks/useAppState.ts`
- Modify: `frontend/src/ui/MessageList.tsx`, `frontend/src/ui/MessageActions.tsx`, `frontend/src/ui/ConversationView.tsx`, `frontend/src/ui/labels.ts`
- Create: `frontend/src/ui/MessageEditor.tsx`

**Interfaces:**
- Consumes: tout ce que produit la tâche 5.
- Produces:
  - action `{ type: 'message/edited'; conversationId: string; id: string; content: string; editedAt: string }`
  - `api.editMessage(conversationId: string, messageId: string, content: string): Promise<ApiMessage>`
  - `EDIT_WINDOW_MS: number` (900_000) et `canStillEdit(createdAt: string, now: number): boolean` exportés de `labels.ts`
  - `editedMessageLabel: string`
  - `<MessageEditor initialContent={...} onSubmit={...} onCancel={...} />`

- [ ] **Step 1 : écrire le test du reducer**

Ajoute à `frontend/src/store/messagesReducer.test.ts` :

```ts
describe('message/edited', () => {
  it('remplace le contenu et marque l instant d edition', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjor' }),
    });

    const next = messagesReducer(state, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'bonjour',
      editedAt: '2026-07-26T09:05:00+00:00',
    });

    const item = selectThread(next, 'c1').items[0];
    expect(item.content).toBe('bonjour');
    expect(item.editedAt).toBe('2026-07-26T09:05:00+00:00');
  });

  it('ignore un identifiant absent du fil', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'intact' }),
    });

    const next = messagesReducer(state, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000Z',
      content: 'ailleurs',
      editedAt: '2026-07-26T09:05:00+00:00',
    });

    expect(selectThread(next, 'c1').items[0].content).toBe('intact');
  });

  // L'echo SSE et la reponse du PATCH portent le MEME etat final : les
  // appliquer dans n'importe quel ordre doit donner le meme resultat.
  it('donne le meme resultat quel que soit l ordre d arrivee', () => {
    const base = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjor' }),
    });

    const action = {
      type: 'message/edited' as const,
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'bonjour',
      editedAt: '2026-07-26T09:05:00+00:00',
    };

    expect(messagesReducer(messagesReducer(base, action), action)).toEqual(
      messagesReducer(base, action),
    );
  });
});
```

- [ ] **Step 2 : lancer, vérifier l'échec**

Run: `make front-test`
Expected: FAIL — l'action `message/edited` n'existe pas.

- [ ] **Step 3 : implémenter l'action**

Dans `frontend/src/store/messagesReducer.ts`, ajoute au type `MessagesAction` :

```ts
  | {
      type: 'message/edited';
      conversationId: string;
      id: string;
      content: string;
      editedAt: string;
    };
```

et le bras :

```ts
    case 'message/edited':
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.id === action.id
            ? { ...item, content: action.content, editedAt: action.editedAt }
            : item,
        ),
      }));
```

- [ ] **Step 4 : lancer, vérifier le succès**

Run: `make front-test && make front-typecheck`
Expected: PASS

- [ ] **Step 5 : appeler l'API et écouter l'événement**

`frontend/src/api/client.ts` :

```ts
  editMessage: (conversationId: string, messageId: string, content: string) =>
    request<ApiMessage>(`/api/conversations/${conversationId}/messages/${messageId}`, {
      method: 'PATCH',
      body: JSON.stringify({ content }),
    }),
```

`frontend/src/hooks/useAppState.ts` :

1. `NAMED_EVENTS` reçoit `'message.edited'`.
2. Dans `onEvent`, à côté du bras `message.deleted` :

```ts
        if (event.type === 'message.edited') {
          dispatch({
            type: 'message/edited',
            conversationId: readString(event.payload, 'conversation_id'),
            id: readString(event.payload, 'id'),
            content: readString(event.payload, 'content'),
            editedAt: readString(event.payload, 'edited_at'),
          });

          scheduleConversationsRefresh();

          return;
        }
```

3. Une action exposée :

```ts
  const editMessage = useCallback(
    async (conversationId: string, messageId: string, content: string) => {
      const updated = await api.editMessage(conversationId, messageId, content);

      // La reponse porte le meme etat final que l'echo SSE : l'appliquer ici
      // aussi rend l'edition visible meme si le hub est injoignable, et
      // l'operation est idempotente donc le doublon est sans consequence.
      dispatch({
        type: 'message/edited',
        conversationId,
        id: messageId,
        content: updated.content ?? '',
        editedAt: updated.edited_at ?? '',
      });
    },
    [],
  );
```

et ajoute `editMessage` à la valeur rendue par le hook.

- [ ] **Step 6 : la fenêtre côté client et le libellé**

`frontend/src/ui/labels.ts` :

```ts
/**
 * Doit rester egal a `Message::EDIT_WINDOW_SECONDS` cote backend.
 *
 * C'est du CONFORT, pas de la securite : le serveur reste l'autorite et
 * repondra 403 `/problems/edit-window-expired` a un appel forge. On masque
 * seulement une action qui echouerait.
 */
export const EDIT_WINDOW_MS = 900_000;

export function canStillEdit(createdAt: string, now: number): boolean {
  const sentAt = new Date(createdAt).getTime();

  return !Number.isNaN(sentAt) && now - sentAt <= EDIT_WINDOW_MS;
}

export const editedMessageLabel = 'modifié';
```

- [ ] **Step 7 : l'éditeur en ligne**

`frontend/src/ui/MessageEditor.tsx` :

```tsx
import { useState, type KeyboardEvent } from 'react';

type Props = {
  initialContent: string;
  onSubmit: (content: string) => void;
  onCancel: () => void;
};

/**
 * Editeur en ligne dans la bulle. Le composant ne connait ni l'API ni le store :
 * il rend deux callbacks, ce qui le rend testable sans rien monter d'autre.
 */
export function MessageEditor({ initialContent, onSubmit, onCancel }: Props) {
  const [draft, setDraft] = useState(initialContent);

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Escape') {
      onCancel();

      return;
    }

    // Entree valide, Maj+Entree passe a la ligne : meme convention que le
    // composeur, pour ne pas avoir deux gestes differents dans la meme fenetre.
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();

      const trimmed = draft.trim();
      if (trimmed !== '') onSubmit(trimmed);
    }
  }

  return (
    <textarea
      autoFocus
      value={draft}
      onChange={(event) => setDraft(event.target.value)}
      onKeyDown={handleKeyDown}
      className="w-full rounded bg-white/10 p-1 text-sm"
      aria-label="Modifier le message"
    />
  );
}
```

- [ ] **Step 8 : brancher l'édition dans la liste**

`frontend/src/ui/MessageActions.tsx` : `Props` reçoit `onEdit: (() => void) | null` ; le bouton « Modifier » n'est rendu que si `onEdit !== null` (fenêtre écoulée = pas de bouton).

`frontend/src/ui/MessageList.tsx` :

- `Props` reçoit `onEditMessage: (messageId: string, content: string) => void` ;
- un état local `const [editingId, setEditingId] = useState<string | null>(null);` ;
- le corps du message devient un troisième cas :

```tsx
            {message.deletedAt !== null ? (
              <p className="italic opacity-60">{deletedMessageLabel}</p>
            ) : editingId === message.id ? (
              <MessageEditor
                initialContent={message.content ?? ''}
                onSubmit={(content) => {
                  setEditingId(null);
                  onEditMessage(message.id as string, content);
                }}
                onCancel={() => setEditingId(null)}
              />
            ) : (
              <p className="whitespace-pre-wrap break-words">{message.content}</p>
            )}
```

- la mention « modifié » à côté de l'heure :

```tsx
            <p className="text-xs opacity-60">
              {userName(users, message.senderId)} · {formatTime(message.createdAt)}
              {message.editedAt !== null && ` · ${editedMessageLabel}`}
            </p>
```

- `MessageActions` reçoit son `onEdit` :

```tsx
              <MessageActions
                onEdit={
                  canStillEdit(message.createdAt, Date.now())
                    ? () => setEditingId(message.id)
                    : null
                }
                onDelete={() => {
                  if (window.confirm('Supprimer ce message pour tout le monde ?')) {
                    onDeleteMessage(message.id as string);
                  }
                }}
              />
```

`ConversationView` et son appelant propagent `onEditMessage` de la même façon que `onDeleteMessage` à la tâche 5.

- [ ] **Step 9 : vérifier dans l'application**

Run: `make restart SERVICE=frontend`
Deux navigateurs : alice envoie « bonjor », corrige en « bonjour ». Bob voit le texte corrigé et « modifié » **sans rafraîchir**. Vérifie aussi qu'un message vieux de plus de 15 minutes n'offre plus « Modifier ».

- [ ] **Step 10 : portes de qualité et commit**

Run: `make front-test && make front-typecheck`
Expected: PASS

```bash
git add frontend/src
git commit -m "$(cat <<'EOF'
feat(front): editer un message en ligne et afficher la mention modifie

L'entree « Modifier » disparait passe quinze minutes : c'est du confort,
le serveur reste l'autorite et repond 403 a un appel forge.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7 : front — dates et fuseaux du lecteur

Le backend est déjà conforme (`TIMESTAMPTZ`, `TZ=UTC` et `date.timezone = UTC` dans `backend/Dockerfile`, transport ISO 8601) : **rien à y changer**. Le fuseau est un problème de présentation.

**Files:**
- Create: `frontend/src/ui/dates.ts`, `frontend/src/ui/dates.test.ts`
- Modify: `frontend/src/ui/labels.ts`, `frontend/src/ui/MessageList.tsx`, `frontend/src/ui/ConversationList.tsx`

**Interfaces:**
- Consumes: rien des tâches précédentes.
- Produces:
  - `dayKey(iso: string, timeZone: string): string` — `'2026-07-26'`
  - `formatDaySeparator(iso: string, timeZone: string, locale: string, now: Date): string`
  - `formatRelative(iso: string, now: Date, locale: string): string`
  - `viewerTimeZone(): string`, `viewerLocale(): string`

- [ ] **Step 1 : écrire les tests**

`frontend/src/ui/dates.test.ts` :

```ts
import { describe, expect, it } from 'vitest';
import { dayKey, formatDaySeparator, formatRelative } from './dates';

/**
 * Le fuseau est un PARAMETRE de ces fonctions, jamais une globale lue a
 * l'interieur : c'est ce qui permet de verifier Tokyo et New York dans le meme
 * fichier, sans toucher a `process.env.TZ`.
 */
describe('dayKey', () => {
  // 2026-07-26T22:00:00Z, c'est deja le 27 a Tokyo (UTC+9) et encore le 26 a
  // New York (UTC-4). Correct et attendu.
  const instant = '2026-07-26T22:00:00+00:00';

  it('rend la date du jour dans le fuseau du lecteur', () => {
    expect(dayKey(instant, 'Asia/Tokyo')).toBe('2026-07-27');
    expect(dayKey(instant, 'America/New_York')).toBe('2026-07-26');
  });

  it('rend une chaine vide pour une date illisible', () => {
    expect(dayKey('pas une date', 'Europe/Paris')).toBe('');
  });
});

describe('formatDaySeparator', () => {
  const now = new Date('2026-07-26T12:00:00+00:00');

  it('dit « Aujourd’hui » pour le jour courant du lecteur', () => {
    expect(formatDaySeparator('2026-07-26T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now)).toBe(
      "Aujourd'hui",
    );
  });

  it('dit « Hier » pour la veille du lecteur', () => {
    expect(formatDaySeparator('2026-07-25T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now)).toBe(
      'Hier',
    );
  });

  // Le meme instant, deux jours differents selon le lecteur : c'est le
  // comportement correct, pas un bug a corriger.
  it('classe le meme instant sous deux jours differents selon le fuseau', () => {
    const instant = '2026-07-25T22:00:00+00:00';

    expect(formatDaySeparator(instant, 'Asia/Tokyo', 'fr-FR', now)).toBe("Aujourd'hui");
    expect(formatDaySeparator(instant, 'America/New_York', 'fr-FR', now)).toBe('Hier');
  });

  it('rend une date complete au-dela de la veille', () => {
    const label = formatDaySeparator('2026-07-01T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now);

    expect(label).toContain('juillet');
  });
});

describe('formatRelative', () => {
  const now = new Date('2026-07-26T12:00:00+00:00');

  it('rend un temps relatif en minutes', () => {
    expect(formatRelative('2026-07-26T11:55:00+00:00', now, 'fr-FR')).toContain('5');
  });
});
```

- [ ] **Step 2 : lancer, vérifier l'échec**

Run: `make front-test`
Expected: FAIL — `./dates` n'existe pas.

- [ ] **Step 3 : écrire le module**

`frontend/src/ui/dates.ts` :

```ts
/**
 * Un instant est absolu : on le stocke et on le transporte en UTC, on le
 * convertit vers le fuseau local UNIQUEMENT a l'affichage, ici.
 *
 * Le fuseau et la locale sont des PARAMETRES, jamais des globales lues a
 * l'interieur : c'est ce qui rend ces fonctions testables sans bidouiller
 * `process.env.TZ`, et ce qui permet de verifier deux fuseaux dans un meme test.
 */

/** Clé de jour (`2026-07-26`) dans le fuseau du lecteur, pour comparer deux instants. */
export function dayKey(iso: string, timeZone: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';

  // `en-CA` rend `YYYY-MM-DD`, donc une clé triable et comparable telle quelle.
  // Ce n'est pas la locale de l'utilisateur : c'est un format interne.
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date);
}

/**
 * Sépare deux jours dans le fil. Un même message peut être « Aujourd'hui » pour
 * Tokyo et « Hier » pour New York : c'est correct et attendu.
 */
export function formatDaySeparator(
  iso: string,
  timeZone: string,
  locale: string,
  now: Date,
): string {
  const key = dayKey(iso, timeZone);
  if (key === '') return '';

  if (key === dayKey(now.toISOString(), timeZone)) return "Aujourd'hui";

  const yesterday = new Date(now.getTime() - 86_400_000);
  if (key === dayKey(yesterday.toISOString(), timeZone)) return 'Hier';

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    day: 'numeric',
    month: 'long',
  }).format(new Date(iso));
}

const MINUTE_MS = 60_000;
const HOUR_MS = 3_600_000;
const DAY_MS = 86_400_000;

/** « il y a 5 min » : calculé depuis l'instant absolu, donc juste dans tous les fuseaux. */
export function formatRelative(iso: string, now: Date, locale: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';

  const elapsed = date.getTime() - now.getTime();
  const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

  if (Math.abs(elapsed) < HOUR_MS) {
    return format.format(Math.round(elapsed / MINUTE_MS), 'minute');
  }

  if (Math.abs(elapsed) < DAY_MS) {
    return format.format(Math.round(elapsed / HOUR_MS), 'hour');
  }

  return format.format(Math.round(elapsed / DAY_MS), 'day');
}

/**
 * Nom IANA, toujours a jour et gerant le DST tout seul. Ne JAMAIS persister un
 * offset (`+02:00`) a la place : il change deux fois par an.
 */
export function viewerTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

export function viewerLocale(): string {
  return navigator.language;
}
```

- [ ] **Step 4 : lancer, vérifier le succès**

Run: `make front-test`
Expected: PASS

- [ ] **Step 5 : remplacer le `'fr-FR'` codé en dur**

`frontend/src/ui/labels.ts` — `formatTime` et `formatListDate` prennent la locale et le fuseau du lecteur au lieu de les supposer :

```ts
/** Heure locale « 14:32 » : format court, suffisant dans un fil de messages. */
export function formatTime(isoDate: string, timeZone: string, locale: string): string {
  const date = new Date(isoDate);

  return Number.isNaN(date.getTime())
    ? ''
    : new Intl.DateTimeFormat(locale, {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
      }).format(date);
}

/**
 * Dans la liste des conversations, l'heure seule est ambigue au-dela du jour
 * courant : on bascule alors sur la date. Le « jour courant » est celui du
 * LECTEUR, pas celui du serveur.
 */
export function formatListDate(isoDate: string, timeZone: string, locale: string): string {
  const date = new Date(isoDate);
  if (Number.isNaN(date.getTime())) return '';

  const sameDay = dayKey(isoDate, timeZone) === dayKey(new Date().toISOString(), timeZone);

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    ...(sameDay
      ? { hour: '2-digit', minute: '2-digit' }
      : { day: '2-digit', month: '2-digit' }),
  }).format(date);
}
```

Ajoute `import { dayKey } from './dates';`.

- [ ] **Step 6 : insérer les séparateurs de jour**

Dans `frontend/src/ui/MessageList.tsx`, résous fuseau et locale **une seule fois** dans le composant :

```tsx
  // Resolus une fois par rendu de liste plutot qu'a chaque message : ces appels
  // Intl ne sont pas gratuits, et la valeur ne change pas en cours de session.
  const timeZone = viewerTimeZone();
  const locale = viewerLocale();
  const now = new Date();
```

puis, dans le `map`, calcule la clé du message précédent et insère le séparateur :

```tsx
        {thread.items.map((message, index) => {
          const previous = thread.items[index - 1];
          const separator =
            previous === undefined ||
            dayKey(previous.createdAt, timeZone) !== dayKey(message.createdAt, timeZone)
              ? formatDaySeparator(message.createdAt, timeZone, locale, now)
              : null;

          return (
            <Fragment key={message.clientMessageId}>
              {separator !== null && separator !== '' && (
                <li className="sticky top-0 self-center rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">
                  {separator}
                </li>
              )}

              <li className={/* … classes existantes … */}>
                {/* … contenu existant, avec formatTime(message.createdAt, timeZone, locale) … */}
              </li>
            </Fragment>
          );
        })}
```

Ajoute les imports `Fragment` de `react`, et `dayKey`, `formatDaySeparator`, `viewerLocale`, `viewerTimeZone` de `./dates`.

> La `key` reste sur le `<Fragment>` et vaut toujours `message.clientMessageId` — c'est le seul identifiant qui existe dès le rendu optimiste, avant que le serveur n'attribue un ULID.

`frontend/src/ui/ConversationList.tsx` : `formatListDate(conversation.last_message_at, viewerTimeZone(), viewerLocale())`.

- [ ] **Step 7 : vérifier dans l'application**

Run: `make restart SERVICE=frontend`

Ouvre `localhost:8080`, puis les outils de développement du navigateur → **Sensors** → *Location* pour simuler `Asia/Tokyo`, et compare avec un second navigateur laissé sur le fuseau local. Le même message doit apparaître sous deux séparateurs différents.

- [ ] **Step 8 : portes de qualité et commit**

Run: `make front-test && make front-typecheck`
Expected: PASS

```bash
git add frontend/src
git commit -m "$(cat <<'EOF'
feat(front): rendre les dates dans le fuseau du lecteur

Separateurs de jour, temps relatif, locale et fuseau resolus par Intl au
lieu du fr-FR code en dur. Le meme instant peut etre « Aujourd'hui » a
Tokyo et « Hier » a New York : c'est correct, et c'est teste.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Vérification finale de la tranche

Avant d'ouvrir la pull request, dérouler les critères d'acceptation de la spec :

- [ ] `make unit-test && make functional-test && make static-code-analysis && make check-cs && make deptrac && make front-test && make front-typecheck` — tout vert.
- [ ] Alice supprime un message : bob voit le tombstone sans rafraîchir, et `SELECT content FROM messages WHERE id = …` rend `NULL`.
- [ ] Alice corrige dans les 15 min : bob voit le texte corrigé et « modifié » sans rafraîchir.
- [ ] Passé 15 min, « Modifier » a disparu, et un `PATCH` forgé (via `curl` ou l'onglet réseau) répond 403 `/problems/edit-window-expired`.
- [ ] Bob ne peut ni éditer ni supprimer le message d'alice : 403 `/problems/not-the-author`.
- [ ] Supprimer le dernier message vide l'aperçu de la colonne de gauche ; supprimer l'avant-dernier ne le touche pas.
- [ ] Rejouer le `DELETE` répond 204 sans seconde publication.
- [ ] Deux fuseaux affichent le même message sous deux séparateurs de jour différents.
- [ ] Mettre à jour le `README.md` avec une section « Tranche 3 » décrivant le cycle de vie des messages, sur le modèle de celle de la tranche 2.

Puis `superpowers:finishing-a-development-branch` pour l'intégration.
