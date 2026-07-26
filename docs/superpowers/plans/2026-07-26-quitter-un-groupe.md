# Quitter une conversation de groupe — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** permettre à un membre non-admin de quitter une conversation de groupe, de sorte
qu'elle disparaisse de sa barre latérale, qu'il n'en reçoive plus les messages et qu'il ne
figure plus parmi ses membres.

**Architecture:** une méthode de domaine `Conversation::leave()` porte le seul invariant
nouveau (un admin ne peut pas partir), une commande CQS l'exécute, un contrôleur dédié
`DELETE /api/conversations/{id}/members/me` l'expose. Le temps réel ne change pas : le
`MembershipChanged(Left)` déjà émis par `removeMember()` est publié sur le topic système du
partant, ce que le front sait déjà traiter. Côté front, l'appel remonte dans `useAppState`
parce que partir invalide la sélection courante.

**Tech Stack:** PHP 8.4 / Symfony (DBAL, Messenger, pas d'ORM) · React + TypeScript + Vite +
Tailwind · PHPUnit · Vitest.

**Spec :** `docs/superpowers/specs/2026-07-26-quitter-un-groupe-design.md`

## Global Constraints

- **Branche `feat/quitter-un-groupe`, déjà créée.** Jamais de commit sur `main`.
- **Ni PHP ni Node ne sont installés sur la machine.** Toute commande passe par `make` ou
  `docker compose run --rm <service> <cmd>`.
- **Ne pas installer de paquet Composer ni npm.** Aucun n'est nécessaire ici.
- **`Domain/` ne dépend de rien** — zéro vendor, pas même `symfony/uid`.
- **`Application` ne connaît aucun vendor hors `Psr\*`.**
- **Jamais de SQL dans `Application`.** Aucune requête nouvelle n'est nécessaire dans ce plan.
- **Commandes en `void`.** Un handler de commande ne rend jamais rien.
- **Chaînes PHP en ASCII sans accent**, comme tout le code existant (`'Le message %s a ete
  supprime.'`). Le front, lui, est accentué.
- **`sprintf()` plutôt que la concaténation** dans le code PHP ; placeholders `{accolades}`
  dans les logs, avec les variables en second argument.
- **PHPStan `max`, PHP-CS-Fixer, deptrac à zéro violation.** Aucune baseline, aucun
  `@phpstan-ignore`.
- **Commits conventionnels, en français, à l'impératif.**
- **Portes de qualité avant chaque commit backend** : `make static-code-analysis`,
  `make check-cs`, `make deptrac`, `make test`. Avant chaque commit front :
  `make front-typecheck` et `make front-test`.

---

### Task 1 : l'invariant de domaine — un admin ne quitte pas son groupe

**Files:**
- Create: `backend/src/Conversation/Domain/AdminCannotLeaveException.php`
- Modify: `backend/src/Conversation/Domain/Conversation.php` (ajout de `leave()` après
  `removeMember()`)
- Test: `backend/tests/Unit/Conversation/Domain/ConversationMembershipTest.php`

**Interfaces:**
- Consumes: `Conversation::assertIsGroup()`, `hasMember()`, `isAdmin()`, `removeMember()` —
  tous déjà présents dans l'agrégat.
- Produces:
  - `Conversation::leave(UserId $userId): void`
  - `AdminCannotLeaveException::forUser(UserId $userId): self`, implémentant
    `App\Shared\Domain\Exception\ConflictExceptionInterface`, slug `admin-cannot-leave`.

- [ ] **Step 1 : écrire les tests qui échouent**

Ajouter ces quatre tests dans `ConversationMembershipTest`, juste après
`testRemovingAMemberRecordsAnEvent`. Les constantes `CONVERSATION`, `ALICE`, `BOB`, `CAROL` et
l'assistant privé `group()` existent déjà dans la classe : `group()` rend un groupe dont Alice
est admin et Bob simple membre, les événements de création déjà drainés.

```php
    public function testAPlainMemberCanLeaveTheGroup(): void
    {
        $group = $this->group();
        $group->leave(UserId::fromString(self::BOB));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::BOB, $events[0]->userId->toString());
        self::assertSame(MembershipChange::Left, $events[0]->change);
        self::assertFalse($group->hasMember(UserId::fromString(self::BOB)));
    }

    /**
     * Le role ne MANQUE pas, il est trop eleve : l'admin doit d'abord
     * transferer ses droits. Le transfert n'existe pas encore, c'est assume.
     */
    public function testAnAdminCannotLeaveTheGroup(): void
    {
        $group = $this->group();

        $this->expectException(AdminCannotLeaveException::class);

        try {
            $group->leave(UserId::fromString(self::ALICE));
        } finally {
            self::assertTrue($group->hasMember(UserId::fromString(self::ALICE)));
            self::assertSame([], $group->releaseEvents());
        }
    }

    /** Un second depart ne doit pas republier un evenement au hub. */
    public function testLeavingTwiceIsANoOp(): void
    {
        $group = $this->group();
        $group->leave(UserId::fromString(self::BOB));
        $group->releaseEvents();

        $group->leave(UserId::fromString(self::BOB));

        self::assertSame([], $group->releaseEvents());
    }

    /** Un direct a deux participants pour toujours : on n'en part pas. */
    public function testNobodyCanLeaveADirect(): void
    {
        $direct = Conversation::direct(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::ALICE),
            UserId::fromString(self::BOB),
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $this->expectException(NotAGroupException::class);

        $direct->leave(UserId::fromString(self::BOB));
    }
```

Ajouter l'import manquant en tête du fichier (`NotAGroupException` et `Conversation` y sont
déjà importés) :

```php
use App\Conversation\Domain\AdminCannotLeaveException;
```

- [ ] **Step 2 : lancer les tests et vérifier qu'ils échouent**

```bash
make unit-test ARGS="--filter=ConversationMembershipTest"
```

Attendu : ÉCHEC sur `Class "App\Conversation\Domain\AdminCannotLeaveException" not found`.

- [ ] **Step 3 : écrire l'exception**

`backend/src/Conversation/Domain/AdminCannotLeaveException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\ConflictExceptionInterface;
use App\Shared\Domain\Identifier\UserId;

/**
 * Traduite en 409 et non en 403 : le role ne MANQUE pas, il est trop eleve.
 * L'appelant ne peut rien corriger dans sa requete — il doit changer l'etat de
 * la ressource en transferant l'administration. C'est exactement ce que le
 * marqueur Conflict decrit.
 */
final class AdminCannotLeaveException extends \RuntimeException implements ConflictExceptionInterface
{
    public static function forUser(UserId $userId): self
    {
        return new self(sprintf(
            'L\'administrateur %s doit transferer ses droits avant de quitter le groupe.',
            $userId->toString(),
        ));
    }

    public function problemSlug(): string
    {
        return 'admin-cannot-leave';
    }

    public function problemTitle(): string
    {
        return 'Un administrateur ne peut pas quitter le groupe';
    }
}
```

- [ ] **Step 4 : écrire `leave()`**

Dans `backend/src/Conversation/Domain/Conversation.php`, juste après `removeMember()` :

```php
    /**
     * Partir de son propre chef. Distincte de `removeMember()`, qui est le
     * geste de l'admin : deux regles differentes sur la meme mutation. Un admin
     * ne peut pas partir tant qu'il n'a pas transfere ses droits — sans quoi le
     * groupe se retrouverait sans personne pour en gerer la composition.
     */
    public function leave(UserId $userId): void
    {
        $this->assertIsGroup();

        if (!$this->hasMember($userId)) {
            return;
        }

        if ($this->isAdmin($userId)) {
            throw AdminCannotLeaveException::forUser($userId);
        }

        $this->removeMember($userId);
    }
```

Aucun `use` à ajouter : l'exception est dans le même namespace.

- [ ] **Step 5 : lancer les tests et vérifier qu'ils passent**

```bash
make unit-test ARGS="--filter=ConversationMembershipTest"
```

Attendu : SUCCÈS, tous les tests de la classe.

- [ ] **Step 6 : passer les portes de qualité**

```bash
make static-code-analysis
make check-cs
make deptrac
```

Attendu : les trois vertes. `check-cs` échoue ? Lancer `make apply-cs` et relire le diff.

- [ ] **Step 7 : commiter**

```bash
git add backend/src/Conversation/Domain/AdminCannotLeaveException.php \
        backend/src/Conversation/Domain/Conversation.php \
        backend/tests/Unit/Conversation/Domain/ConversationMembershipTest.php
git commit -m "feat(conversation): laisser un membre non-admin quitter un groupe"
```

---

### Task 2 : la commande et la route `DELETE .../members/me`

**Files:**
- Create: `backend/src/Conversation/Application/Command/LeaveConversationCommand.php`
- Create: `backend/src/Conversation/Application/Command/LeaveConversationCommandHandler.php`
- Create: `backend/src/Conversation/Infrastructure/Http/LeaveConversationController.php`
- Create: `backend/tests/Functional/Conversation/LeaveGroupTest.php`
- Modify: `backend/src/Shared/Domain/Identifier/AbstractUlidIdentifier.php:23`
- Modify: `backend/src/Conversation/Infrastructure/Http/RemoveMemberController.php:31-35`

**Interfaces:**
- Consumes: `Conversation::leave(UserId): void` (Task 1) ·
  `ConversationRepositoryInterface::ofId()` / `save()` · `GetConversationQuery` ·
  `CommandDispatcher::dispatch()` · `QueryDispatcher::ask()`.
- Produces:
  - `LeaveConversationCommand(ConversationId $conversationId, UserId $userId)`
  - route `conversation_members_leave` = `DELETE /api/conversations/{conversationId}/members/me`,
    204 en succès, 404 pour un non-membre, 409 pour un admin
  - `AbstractUlidIdentifier::ROUTE_PATTERN` (`string`, motif nu sans délimiteurs).

Aucun câblage de service à écrire : `services.yaml` charge tout `src/` en autowire, et
`_instanceof` tague le handler vers `command.bus` par son interface.

- [ ] **Step 1 : écrire le test fonctionnel qui échoue**

Créer `backend/tests/Functional/Conversation/LeaveGroupTest.php`. Les fixtures fournissent
`alice`, `bob`, `carol`, mot de passe commun `password`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class LeaveGroupTest extends DatabaseTestCase
{
    public function testAPlainMemberLeavesAndTheGroupForgetsHim(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');
        $this->leave($groupId);

        self::assertResponseStatusCodeSame(204);

        // Sa liste laterale ne la contient plus.
        $this->client->request('GET', '/api/conversations');
        /** @var list<array{id: string}> $conversations */
        $conversations = $this->json();
        self::assertNotContains($groupId, array_column($conversations, 'id'));

        // Le detail lui est desormais inaccessible — 404 et non 403.
        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));
        self::assertResponseStatusCodeSame(404);

        // Et Alice ne le voit plus parmi les membres.
        $this->login('alice');
        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));
        self::assertCount(1, (array) $this->json()['members']);
    }

    /**
     * Le « je ne suis plus notifie » se verifie ici, sans lever de hub : le
     * jeton ne liste que les topics des conversations dont on est membre.
     */
    public function testTheTokenOfTheFormerMemberNoLongerCarriesTheTopic(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');

        $this->client->request('GET', '/api/realtime/token');
        /** @var array{topics: list<string>} $before */
        $before = $this->json();
        self::assertContains(sprintf('/conversations/%s', $groupId), $before['topics']);

        $this->leave($groupId);

        $this->client->request('GET', '/api/realtime/token');
        /** @var array{topics: list<string>} $after */
        $after = $this->json();
        self::assertNotContains(sprintf('/conversations/%s', $groupId), $after['topics']);
    }

    /** C'est par son topic systeme que le partant apprend qu'il doit se reabonner. */
    public function testLeavingPublishesOnThePersonalTopicOfTheLeaver(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        $groupId = $this->createGroup('Equipe projet', [$bobId]);

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->login('bob');
        $this->leave($groupId);

        $membershipEvents = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'membership.changed' === $entry['type'],
        ));

        self::assertNotEmpty($membershipEvents);

        $last = end($membershipEvents);

        self::assertSame(sprintf('/users/%s/system', $bobId), $last['topic']);
        self::assertSame('left', $last['payload']['change']);
    }

    /** Le role ne manque pas, il est trop eleve : 409, et non 403. */
    public function testAnAdminCannotLeave(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->leave($groupId);

        self::assertResponseStatusCodeSame(409);

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/admin-cannot-leave', $problem['type']);
    }

    /** Un non-membre ne doit rien apprendre de l'existence du groupe. */
    public function testANonMemberGetsA404(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->login('carol');
        $this->leave($groupId);

        self::assertResponseStatusCodeSame(404);
    }

    /** Consequence assumee du cadrage par l'appartenance : on ne part qu'une fois. */
    public function testLeavingTwiceGivesA404(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');
        $this->leave($groupId);
        self::assertResponseStatusCodeSame(204);

        $this->leave($groupId);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * La route dediee ne doit jamais etre captee par `/members/{userId}`, qui
     * exigerait des droits d'admin et repondrait 403 a un simple membre.
     */
    public function testTheDedicatedRouteIsNotShadowedByTheRemovalRoute(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');
        $this->leave($groupId);

        self::assertResponseStatusCodeSame(204);
    }

    private function leave(string $conversationId): void
    {
        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/members/me', $conversationId),
        );
    }

    /** @param list<string> $memberIds */
    private function createGroup(string $title, array $memberIds): string
    {
        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'group', 'title' => $title, 'member_ids' => $memberIds],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Step 2 : lancer le test et vérifier qu'il échoue**

```bash
make functional-test ARGS="--filter=LeaveGroupTest"
```

Attendu : ÉCHEC — la route n'existe pas, on obtient un 404 générique là où on attend 204.

- [ ] **Step 3 : écrire la commande**

`backend/src/Conversation/Application/Command/LeaveConversationCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Partir de son propre chef. Distincte de RemoveMemberCommand, qui est le geste
 * d'un admin sur quelqu'un d'autre : les deux n'ont ni les memes regles
 * d'autorisation ni les memes conditions d'echec.
 */
final readonly class LeaveConversationCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
    ) {
    }
}
```

- [ ] **Step 4 : écrire le handler**

`backend/src/Conversation/Application/Command/LeaveConversationCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class LeaveConversationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LeaveConversationCommand $command): void
    {
        $conversation = $this->conversations->ofId($command->conversationId);
        $conversation->leave($command->userId);

        $this->conversations->save($conversation);

        // `notice` : evenement metier normal mais significatif — c'est ce qui
        // explique, plus tard, pourquoi quelqu'un ne recoit plus rien d'un fil.
        $this->logger->notice('Membre {user_id} a quitte {conversation_id}', [
            'user_id' => $command->userId->toString(),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
```

- [ ] **Step 5 : écrire le contrôleur**

`backend/src/Conversation/Infrastructure/Http/LeaveConversationController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\LeaveConversationCommand;
use App\Conversation\Application\Query\GetConversationQuery;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Partir de son propre chef, par opposition a RemoveMemberController, qui est
 * le geste d'un admin sur quelqu'un d'autre.
 *
 * Aucun appel au voter : tout membre a le droit de TENTER de partir, c'est le
 * domaine qui tranche sur le role. La query prealable, elle, cadre la reponse
 * sur l'appartenance — un non-membre recoit un 404, qui ne confirme pas
 * l'existence de la conversation.
 */
final readonly class LeaveConversationController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route(
        '/api/conversations/{conversationId}/members/me',
        name: 'conversation_members_leave',
        methods: ['DELETE'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $this->queries->ask(new GetConversationQuery($conversationId, $securityUser->userId()));

        $this->commands->dispatch(new LeaveConversationCommand($conversationId, $securityUser->userId()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 6 : rendre la route d'exclusion insensible à `me`**

Sans cela, `/members/me` et `/members/{userId}` peuvent matcher la même URL et l'ordre dépend
de l'ordre de chargement des fichiers.

Dans `backend/src/Shared/Domain/Identifier/AbstractUlidIdentifier.php`, remplacer la constante
`PATTERN` par ces deux constantes, en gardant le bloc de commentaire existant au-dessus :

```php
    /** Motif nu, sans delimiteurs : utilisable en `requirements` de route. */
    public const string ROUTE_PATTERN = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

    public const string PATTERN = '/^'.self::ROUTE_PATTERN.'$/';
```

La concaténation dans une expression constante est ici le seul moyen disponible : PHP
n'autorise pas `sprintf()` dans une constante de classe. C'est la seule façon de garder **une
seule** définition du format.

Puis, dans `backend/src/Conversation/Infrastructure/Http/RemoveMemberController.php`, ajouter
la contrainte à l'attribut de route et son `use` :

```php
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
```

```php
    #[Route(
        '/api/conversations/{conversationId}/members/{userId}',
        name: 'conversation_members_remove',
        // Sans cette contrainte, cette route capterait `/members/me` selon
        // l'ordre de chargement des fichiers, et repondrait 403 a un simple
        // membre qui cherche a partir.
        requirements: ['userId' => AbstractUlidIdentifier::ROUTE_PATTERN],
        methods: ['DELETE'],
    )]
```

- [ ] **Step 7 : lancer le test et vérifier qu'il passe**

```bash
make functional-test ARGS="--filter=LeaveGroupTest"
```

Attendu : SUCCÈS, les sept tests.

- [ ] **Step 8 : lancer toute la suite et les portes de qualité**

```bash
make test
make static-code-analysis
make check-cs
make deptrac
```

Attendu : tout vert. `PATTERN` étant partagée, cette étape vérifie surtout qu'aucune
validation de charge utile ni aucun autre test n'a été cassé par le découpage de la constante.

- [ ] **Step 9 : commiter**

```bash
git add backend/src/Conversation/Application/Command/LeaveConversationCommand.php \
        backend/src/Conversation/Application/Command/LeaveConversationCommandHandler.php \
        backend/src/Conversation/Infrastructure/Http/LeaveConversationController.php \
        backend/src/Conversation/Infrastructure/Http/RemoveMemberController.php \
        backend/src/Shared/Domain/Identifier/AbstractUlidIdentifier.php \
        backend/tests/Functional/Conversation/LeaveGroupTest.php
git commit -m "feat(api): exposer le depart volontaire d'un groupe"
```

---

### Task 3 : l'appel côté front et l'effet sur la sélection

**Files:**
- Modify: `frontend/src/api/client.ts` (après `addMembers`)
- Modify: `frontend/src/hooks/useAppState.ts` (type `AppState`, nouveau `useCallback`, valeur
  de retour du hook)
- Test: `frontend/src/hooks/useAppState.test.ts`

**Interfaces:**
- Consumes: route `DELETE /api/conversations/{id}/members/me` (Task 2) ·
  `refreshConversations()` et `setSelectedId()` internes au hook.
- Produces:
  - `api.leaveConversation(conversationId: string): Promise<void>`
  - `AppState.leaveConversation: (conversationId: string) => Promise<void>`

- [ ] **Step 1 : écrire le test qui échoue**

Trois retouches dans `frontend/src/hooks/useAppState.test.ts`, qui monte déjà le hook avec un
client HTTP entièrement mocké et un `EventSource` inerte.

**a.** Ajouter `leaveConversation: vi.fn(),` à l'objet `api` du `vi.mock('../api/client', …)`,
après `editMessage`. Sans cette entrée, le mock ne l'expose pas et l'appel échoue en
`undefined is not a function`.

**b.** Étendre l'import de types en tête de fichier :

```ts
import type { ApiMessage, ConversationSummary, Me } from '../api/types';
```

et ajouter cet assistant à côté de `apiMessage()` :

```ts
function conversationSummary(id: string): ConversationSummary {
  return {
    id,
    type: 'group',
    title: 'Equipe projet',
    last_message_at: null,
    last_message_preview: null,
    last_message_sender_id: null,
    unread_count: 0,
  };
}
```

**c.** Ajouter ce test à l'intérieur du `describe('useAppState', …)` :

```ts
  /**
   * Meme motif que la suppression d'un message : on applique des le 204. Si le
   * hub est injoignable, l'echo `membership.changed` n'arrivera jamais et la
   * conversation quittee resterait affichee, selectionnee, sans erreur visible.
   */
  it('deselectionne la conversation quittee des le 204, sans attendre l echo SSE', async () => {
    // Une conversation au montage, plus aucune au rafraichissement d'apres le
    // depart : c'est exactement ce que le serveur renverra.
    vi.mocked(api.conversations)
      .mockResolvedValueOnce([conversationSummary(CONVERSATION_ID)])
      .mockResolvedValue([]);
    vi.mocked(api.leaveConversation).mockResolvedValue(undefined);

    const { result } = await renderWithLoadedThread();

    expect(result.current.selectedId).toBe(CONVERSATION_ID);

    await act(async () => {
      await result.current.leaveConversation(CONVERSATION_ID);
    });

    expect(api.leaveConversation).toHaveBeenCalledWith(CONVERSATION_ID);
    expect(result.current.selectedId).toBeNull();
    expect(result.current.conversations).toEqual([]);
  });
```

- [ ] **Step 2 : lancer le test et vérifier qu'il échoue**

```bash
make front-test
```

Attendu : ÉCHEC sur `result.current.leaveConversation is not a function` — le hook ne l'expose
pas encore.

- [ ] **Step 3 : ajouter l'appel au client HTTP**

Dans `frontend/src/api/client.ts`, juste après `addMembers` :

```ts
  // `me` et non son propre ULID : partir est un geste sur soi, le serveur sait
  // deja qui appelle. Le front n'a donc pas a construire l'URL avec son id.
  leaveConversation: (conversationId: string) =>
    request<void>(`/api/conversations/${conversationId}/members/me`, {
      method: 'DELETE',
    }),
```

- [ ] **Step 4 : exposer `leaveConversation` depuis le hook**

Dans `frontend/src/hooks/useAppState.ts`, ajouter la ligne au type `AppState`, après
`createGroup` :

```ts
  leaveConversation: (conversationId: string) => Promise<void>;
```

Puis, à côté de `deleteMessage`, le `useCallback` :

```ts
  // L'appel ne peut pas rester dans `MembersPanel` comme l'ajout de membres :
  // partir a des consequences GLOBALES — la conversation ouverte disparait, la
  // selection devient invalide — et ce hook est le seul a pouvoir les porter.
  const leaveConversation = useCallback(
    async (conversationId: string) => {
      await api.leaveConversation(conversationId);

      // On applique des le 204, sans attendre l'echo SSE : meme choix que la
      // suppression d'un message. Si le hub est injoignable, l'echo n'arrivera
      // JAMAIS et la conversation resterait affichee sans la moindre erreur
      // visible. L'echo `membership.changed` qui suit declenche le
      // `resubscribe()` deja en place et reste sans effet supplementaire — la
      // liste rechargee ne contient de toute facon plus la conversation.
      setSelectedId(null);
      await refreshConversations();
    },
    [refreshConversations],
  );
```

Enfin, ajouter `leaveConversation` à l'objet rendu en fin de hook, après `createGroup`.

- [ ] **Step 5 : lancer le test et le typage**

```bash
make front-test
make front-typecheck
```

Attendu : les deux verts.

- [ ] **Step 6 : commiter**

```bash
git add frontend/src/api/client.ts frontend/src/hooks/useAppState.ts \
        frontend/src/hooks/useAppState.test.ts
git commit -m "feat(front): porter le depart d'un groupe dans l'etat applicatif"
```

---

### Task 4 : le bouton « Quitter le groupe »

**Files:**
- Modify: `frontend/src/ui/MembersPanel.tsx`
- Modify: `frontend/src/ui/ConversationView.tsx` (props et passage à `MembersPanel`)
- Modify: `frontend/src/App.tsx` (`Workspace` : récupérer `leaveConversation` du hook et le
  câbler)
- Test: `frontend/src/ui/MembersPanel.test.tsx` (à créer)

**Interfaces:**
- Consumes: `AppState.leaveConversation` (Task 3) · `api.conversation(id)` déjà utilisée par
  le panneau · `ConversationView` reçoit déjà `meId`.
- Produces:
  - `MembersPanel` prend deux props de plus : `meId: string` et `onLeave: () => Promise<void>`
  - `ConversationView` prend une prop de plus : `onLeave: () => Promise<void>`

- [ ] **Step 1 : écrire les tests qui échouent**

Créer `frontend/src/ui/MembersPanel.test.tsx` :

```tsx
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { api } from '../api/client';
import type { ConversationDetail, UserSummary } from '../api/types';
import { MembersPanel } from './MembersPanel';

vi.mock('../api/client', () => ({
  api: { conversation: vi.fn(), addMembers: vi.fn() },
}));

const ALICE: UserSummary = { id: 'user-alice', username: 'alice', display_name: 'Alice' };
const BOB: UserSummary = { id: 'user-bob', username: 'bob', display_name: 'Bob' };

const USERS: Record<string, UserSummary> = { [ALICE.id]: ALICE, [BOB.id]: BOB };

/**
 * Alice administre, Bob est simple membre. Le type de retour est annote : sans
 * lui, `type: 'group'` s'elargirait en `string` et le mock ne satisferait plus
 * `api.conversation`.
 */
function detail(): ConversationDetail {
  return {
    id: 'conv-1',
    type: 'group',
    title: 'Equipe projet',
    members: [
      { user_id: ALICE.id, role: 'admin' },
      { user_id: BOB.id, role: 'member' },
    ],
  };
}

function renderPanel(meId: string, onLeave = vi.fn()) {
  vi.mocked(api.conversation).mockResolvedValue(detail());

  render(
    <MembersPanel
      conversationId="conv-1"
      users={USERS}
      meId={meId}
      onLeave={onLeave}
      onClose={vi.fn()}
    />,
  );

  return onLeave;
}

const LEAVE = { name: 'Quitter le groupe' };

afterEach(() => {
  vi.restoreAllMocks();
});

describe('MembersPanel', () => {
  it('propose de quitter le groupe a un simple membre', async () => {
    renderPanel(BOB.id);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
  });

  /**
   * L'admin ne PEUT pas partir tant qu'il n'a pas transfere ses droits : le 409
   * du serveur reste la vraie garantie, masquer le bouton evite seulement de
   * proposer une action interdite.
   */
  it('ne propose pas de quitter le groupe a un admin', async () => {
    renderPanel(ALICE.id);

    await waitFor(() => expect(screen.getByText('Bob')).toBeDefined());
    expect(screen.queryByRole('button', LEAVE)).toBeNull();
  });

  it('ne quitte rien si la confirmation est refusee', async () => {
    const onLeave = renderPanel(BOB.id);
    vi.spyOn(window, 'confirm').mockReturnValue(false);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
    fireEvent.click(screen.getByRole('button', LEAVE));

    expect(onLeave).not.toHaveBeenCalled();
  });

  it('quitte le groupe une fois la confirmation donnee', async () => {
    const onLeave = renderPanel(BOB.id);
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
    fireEvent.click(screen.getByRole('button', LEAVE));

    await waitFor(() => expect(onLeave).toHaveBeenCalledTimes(1));
  });
});
```

- [ ] **Step 2 : lancer les tests et vérifier qu'ils échouent**

```bash
make front-test
```

Attendu : ÉCHEC — `MembersPanel` n'accepte ni `meId` ni `onLeave`, et aucun bouton
« Quitter le groupe » n'est rendu.

- [ ] **Step 3 : ajouter le bouton au panneau**

Dans `frontend/src/ui/MembersPanel.tsx`, étendre le type `Props` :

```tsx
type Props = {
  conversationId: string;
  users: Record<string, UserSummary>;
  meId: string;
  onLeave: () => Promise<void>;
  onClose: () => void;
};
```

Étendre la signature du composant : `{ conversationId, users, meId, onLeave, onClose }`.

Ajouter, sous le calcul de `candidates` :

```tsx
  // Un admin ne peut pas partir tant qu'il n'a pas transfere ses droits : le
  // serveur repond 409. On ne lui montre donc pas le bouton, plutot que de lui
  // proposer une action qu'on sait refusee. Tant que la liste n'est pas
  // chargee, on ne sait pas quel est notre role : on n'affiche rien non plus.
  const canLeave = (members ?? []).some(
    (member) => member.user_id === meId && member.role === 'member',
  );

  async function leave() {
    if (!window.confirm('Quitter cette conversation ? Vous ne la verrez plus.')) return;

    setBusy(true);
    setError(null);

    try {
      await onLeave();
      // Pas de `setBusy(false)` au succes : la conversation disparait de la
      // liste, `selected` repasse a `null` et tout ce sous-arbre est demonte.
      // Toucher l'etat d'un composant demonte n'aurait aucun effet.
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Depart impossible.');
      setBusy(false);
    }
  }
```

Puis, juste avant le bloc `{error !== null && …}` en fin de JSX :

```tsx
      {canLeave && (
        <button
          type="button"
          onClick={() => void leave()}
          disabled={busy}
          className="mt-auto rounded border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-40"
        >
          Quitter le groupe
        </button>
      )}
```

`mt-auto` pousse le bouton en pied de colonne, séparé de la liste des membres et du bloc
d'ajout. L'erreur s'affiche dans le `<p role="alert">` déjà présent — pas de `window.alert`,
le panneau a son emplacement d'erreur.

- [ ] **Step 4 : faire descendre la prop depuis `App`**

Dans `frontend/src/ui/ConversationView.tsx`, ajouter `onLeave: () => Promise<void>;` au type
`Props`, l'ajouter à la déstructuration, et le passer au `MembersPanel` avec `meId` — qui est
déjà une prop du composant :

```tsx
        <MembersPanel
          conversationId={conversation.id}
          users={users}
          meId={meId}
          onLeave={onLeave}
          onClose={() => setShowMembers(false)}
        />
```

Dans `frontend/src/App.tsx`, récupérer `leaveConversation` dans la déstructuration de
`useAppState(me)`, puis le câbler sur `ConversationView` :

```tsx
          onLeave={() => leaveConversation(selected.id)}
```

Aucune `window.alert` ici, contrairement à `onDeleteMessage` : la promesse est rendue au
panneau, qui affiche l'échec dans son propre `role="alert"`.

- [ ] **Step 5 : lancer les tests et le typage**

```bash
make front-test
make front-typecheck
```

Attendu : les deux verts.

- [ ] **Step 6 : vérifier dans un vrai navigateur**

```bash
make up
make fixtures
make restart SERVICE=frontend
```

Sur <http://localhost:8080>, se connecter en `alice` / `password`, créer un groupe
« Equipe projet » avec bob, y envoyer un message. Puis, dans une fenêtre privée, se connecter
en `bob` / `password` : ouvrir « Membres », cliquer « Quitter le groupe », confirmer.

Attendu : la conversation disparaît de la barre latérale de Bob sans rechargement ; côté
Alice, le panneau des membres rouvert n'affiche plus Bob. Vérifier aussi qu'Alice, admin, ne
voit **pas** le bouton dans son propre panneau.

Le `make restart SERVICE=frontend` n'est pas facultatif : Vite sert du code périmé après une
opération git.

- [ ] **Step 7 : commiter**

```bash
git add frontend/src/ui/MembersPanel.tsx frontend/src/ui/MembersPanel.test.tsx \
        frontend/src/ui/ConversationView.tsx frontend/src/App.tsx
git commit -m "feat(front): offrir de quitter un groupe depuis le panneau des membres"
```

---

## Vérification finale

- [ ] `make static-code-analysis` · `make check-cs` · `make deptrac` · `make test` :
      quatre vertes.
- [ ] `make front-typecheck` · `make front-test` : deux vertes.
- [ ] `git log --oneline main..` montre quatre commits relisibles, plus celui de la spec.

## Hors périmètre — ne pas déborder

Le transfert d'administration, le départ du dernier membre, un message système
« Bob a quitté », la notification temps réel des membres restants. Si l'un de ces besoins
apparaît en cours de route, l'écrire dans la section « Hors périmètre » de la spec et
s'arrêter là.
