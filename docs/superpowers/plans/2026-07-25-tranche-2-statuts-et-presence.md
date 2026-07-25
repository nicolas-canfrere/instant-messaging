# Tranche 2 — Statuts & présence : plan d'implémentation

> **Pour un agent exécutant :** SOUS-SKILL REQUISE — utiliser `superpowers:subagent-driven-development`
> (recommandé) ou `superpowers:executing-plans` pour dérouler ce plan tâche par tâche. Les étapes
> utilisent des cases à cocher (`- [ ]`).

**Objectif :** ajouter les accusés de réception (watermarks distribué/lu), l'indicateur de frappe et
la présence en ligne, sans casser un seul contrat temps réel de la tranche 1.

**Architecture :** les accusés sont un **état durable** — deux colonnes sur `conversation_members`,
avancées par un `UPDATE` gardé qui rend la monotonie structurelle. La présence et le typing sont un
**état éphémère** — clé Redis à TTL pour l'une, simple événement Mercure pour l'autre, et **aucune
ligne en base** ni pour l'une ni pour l'autre. Les deux nouveaux événements passent par le topic
conversation existant, donc aucun topic ni JWT ne change.

**Spec :** [`docs/superpowers/specs/2026-07-25-instant-messaging-tranche-2-design.md`](../specs/2026-07-25-instant-messaging-tranche-2-design.md)

**Stack :** PHP 8.5 / Symfony 7.4 · Doctrine DBAL (sans ORM) · PostgreSQL 17 · Redis 7 (`ext-redis`) ·
Mercure · React 19 + TypeScript + Vite + Vitest.

## Contraintes globales

Elles s'appliquent à **toutes** les tâches, sans être répétées dans chacune.

- **Branche unique : `feat/tranche-2-statuts-et-presence`.** Aucun commit sur `main`. Dérogation
  assumée à la règle « une story = une branche » du CLAUDE.md, décidée pour cette tranche.
- **Ni PHP ni Node sur l'hôte.** Toute commande passe par `make` ou
  `docker compose run --rm <service> <cmd>`. Lire le `Makefile` avant d'écrire une commande.
- **`Domain/` ne dépend de rien** — zéro paquet Composer, aucune exception.
- **`Application` ne connaît que `Psr\*`** — ni `Symfony\`, ni `Doctrine\`.
- **SQL littéral**, jamais de `QueryBuilder`. Toujours des paramètres liés. Chaque requête
  entièrement écrite, copiable telle quelle dans `psql`.
- **Un handler de commande rend `void`.** Pour connaître l'effet d'une écriture, on pose une query.
- **PHPStan niveau `max`**, PHP-CS-Fixer, deptrac zéro violation. Jamais de baseline ni
  d'`@phpstan-ignore`.
- **Logs** : placeholders `{entre_accolades}`, valeurs dans le second argument, message littéral
  constant. **Jamais** de contenu de message, de JWT ni d'e-mail — des identifiants uniquement.
- **Nommage Symfony** : interfaces suffixées `Interface`, `sprintf()` plutôt que concaténation
  (sauf logs), cas d'enum en `UpperCamelCase`.
- **TDD** : le test qui décrit le comportement avant le code.
- **Portes de qualité vertes à chaque commit** : `make static-code-analysis`, `make check-cs`,
  `make deptrac`, `make test`, `make front-test`.

## Prérequis

1. ~~Ajouter `"ext-redis": "*"` aux `require` de `backend/composer.json`.~~ **Fait**, sur
   autorisation explicite de Nicolas, via `make composer-req PACKAGES="ext-redis:*"`. Les
   modifications de `backend/composer.json` et `backend/composer.lock` sont dans l'arbre de travail
   et seront reprises par le commit de la tâche 1.
2. **Valider la modification de `deptrac-contexts.yaml` prévue en tâche 7** — « la config deptrac se
   décide à deux ». C'est une porte, pas un geste : ne pas modifier le fichier sans accord. Le détail
   et sa justification sont dans la tâche 7, étape 1.

## Carte des fichiers

**Créés — backend**

| Fichier | Responsabilité |
|---|---|
| `src/Realtime/Domain/PresenceStoreInterface.php` | port : marquer présent, filtrer les présents |
| `src/Realtime/Infrastructure/Presence/RedisPresenceStore.php` | adaptateur `SETEX` / `MGET` |
| `src/Realtime/Application/Command/RecordHeartbeatCommand.php` + `Handler` | rafraîchit le TTL |
| `src/Realtime/Application/Query/GetOnlinePeersQuery.php` + `Handler` | qui est en ligne parmi mes pairs |
| `src/Realtime/Infrastructure/Http/HeartbeatController.php` | `POST /api/presence/heartbeat` |
| `src/Realtime/Infrastructure/Http/TypingController.php` | `POST /api/conversations/{id}/typing` |
| `src/Conversation/Application/Contract/ConversationPeersFinderInterface.php` | contrat publié : mes interlocuteurs |
| `src/Conversation/Infrastructure/Contract/DbalConversationPeersFinder.php` | son implémentation |
| `src/Conversation/Domain/Membership.php` | entité portant les deux watermarks |
| `src/Conversation/Domain/MembershipRepositoryInterface.php` | port |
| `src/Conversation/Infrastructure/Persistence/DbalMembershipRepository.php` | `UPDATE` gardé + collecte conditionnelle |
| `src/Conversation/Application/Command/AdvanceReceiptsCommand.php` + `Handler` | use case |
| `src/Conversation/Infrastructure/Http/AdvanceReceiptsController.php` | `POST /api/conversations/{id}/receipts` |
| `src/Conversation/Infrastructure/Http/Payload/AdvanceReceiptsPayload.php` | validation 422 |
| `src/Shared/Domain/Event/ReceiptWatermarkAdvanced.php` | événement inter-contextes |
| `src/Realtime/Application/EventListener/PublishReceiptUpdatedListener.php` | publie `receipt.updated` |
| `src/Message/Application/Contract/UnreadCounterInterface.php` | contrat publié |
| `src/Message/Infrastructure/Contract/DbalUnreadCounter.php` | requête `jsonb_to_recordset` |
| `src/Conversation/Domain/Port/UnreadCounterPortInterface.php` | le besoin côté consommateur |
| `src/Conversation/Infrastructure/Contract/UnreadCounterAdapter.php` | délègue au contrat de `Message` |
| `migrations/VersionYYYYMMDDHHMMSS.php` | les deux colonnes |

**Modifiés — backend**

| Fichier | Modification |
|---|---|
| `Dockerfile` | `redis` dans `install-php-extensions` |
| `src/Realtime/Domain/EventPublisherInterface.php` | `$eventId` devient `?string $eventId = null` |
| `src/Realtime/Infrastructure/Mercure/MercureEventPublisher.php` | idem |
| `tests/Support/InMemoryEventPublisher.php` | idem |
| `src/Conversation/Application/Query/ConversationView.php` | champ `unreadCount` |
| `src/Conversation/Application/Query/ConversationDetailView.php` | watermarks par membre |
| `src/Conversation/Infrastructure/Persistence/DbalConversationReader.php` | lit les watermarks, appelle le port |
| `config/services.yaml` | câblage des nouveaux ports |
| `deptrac-contexts.yaml` | couche `MessageContract` (tâche 7) |

**Créés — frontend** : `store/receiptsReducer.ts`, `store/presenceReducer.ts`,
`store/typingReducer.ts`, `hooks/useHeartbeat.ts`, `ui/ReceiptTicks.tsx`,
`ui/TypingIndicator.tsx`, `ui/PresenceDot.tsx` (+ un `.test.ts` par reducer).

**Modifiés — frontend** : `api/client.ts`, `api/types.ts`, `hooks/useAppState.ts`,
`ui/ConversationList.tsx`, `ui/ConversationView.tsx`, `ui/MessageList.tsx`, `ui/Composer.tsx`.

**Modifiés — racine** : `compose.yaml`, `compose.test.yaml`, `README.md`.

---

## Tâche 1 — Le conteneur Redis

**Fichiers :**
- Modifier : `backend/Dockerfile:29-33`
- Modifier : `compose.yaml`
- Modifier : `compose.test.yaml`

**Interfaces :**
- Produit : le service `redis` (dev, hôte `redis:6379`) et `redis-test` (tests, hôte `redis-test:6379`),
  plus la variable d'environnement `REDIS_URL` dans les deux stacks backend.

- [ ] **Étape 1 : vérifier que `ext-redis` est bien déclarée**

Déjà ajoutée (voir *Prérequis*), mais la vérification reste : sans elle, `composer install` échoue.

```bash
grep -n 'ext-redis' backend/composer.json
```

Attendu : une ligne `"ext-redis": "*",`. Si absente, **s'arrêter** et le signaler.

- [ ] **Étape 2 : ajouter l'extension au Dockerfile**

Dans `backend/Dockerfile`, remplacer le bloc `install-php-extensions` (lignes 25-33) par :

```dockerfile
# `install-php-extensions` est fourni par l'image FrankenPHP : il installe les
# dépendances système de chaque extension puis les retire après compilation.
#   pdo_pgsql : doctrine/dbal      intl : Symfony
#   zip       : Composer           opcache : toujours
#   redis     : présence éphémère (T2) — état à TTL, jamais en base
RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        redis \
        zip
```

- [ ] **Étape 3 : ajouter le service à `compose.yaml`**

Insérer après le bloc `mercure:` :

```yaml
  # Presence uniquement : etat ephemere a TTL. AUCUN volume, deliberement — un
  # etat de presence qui survivrait au redemarrage affirmerait que des gens sont
  # en ligne alors que plus personne ne le sait. Redis repart vide, et le premier
  # heartbeat de chacun reconstruit tout en moins de 20 s.
  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      timeout: 3s
      retries: 20
```

Et dans le service `backend`, ajouter à `environment:` :

```yaml
      REDIS_HOST: "redis"
      REDIS_PORT: "6379"
```

puis à `depends_on:` :

```yaml
      redis:
        condition: service_healthy
```

- [ ] **Étape 4 : faire de même dans `compose.test.yaml`**

Ajouter le service :

```yaml
  # Les tests fonctionnels exercent le VRAI adaptateur Redis, comme ils exercent
  # le vrai PostgreSQL. Un double en memoire ne verifierait pas ce qui casse en
  # pratique : le TTL et le format des cles.
  redis-test:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 1s
      timeout: 3s
      retries: 30
```

Dans `backend-test`, ajouter `REDIS_HOST: "redis-test"` et `REDIS_PORT: "6379"` à `environment:`, et

```yaml
      redis-test:
        condition: service_healthy
```

à `depends_on:`.

- [ ] **Étape 5 : ajouter `redis-test` au démarrage de la suite fonctionnelle**

Dans `Makefile`, cible `functional-test`, remplacer

```make
	@$(DOCKER_COMPOSE_TEST) up -d --wait postgres-test
```

par

```make
	@$(DOCKER_COMPOSE_TEST) up -d --wait postgres-test redis-test
```

- [ ] **Étape 6 : reconstruire et vérifier l'extension**

```bash
make build
docker compose run --rm --no-deps backend php -r 'exit(extension_loaded("redis") ? 0 : 1);' && echo OK
```

Attendu : `OK`.

- [ ] **Étape 7 : vérifier que la stack complète monte**

```bash
make up && make ps
```

Attendu : six services, `redis` en `healthy`.

- [ ] **Étape 8 : commit**

```bash
git add backend/Dockerfile backend/composer.json backend/composer.lock compose.yaml compose.test.yaml Makefile
git commit -m "chore(infra): ajouter le conteneur redis pour la presence ephemere"
```

> Le `composer.lock` porte aussi un réencodage cosmétique (`"stability-flags": []` → `{}`,
> `"platform-dev": []` → `{}`) dû à la version de Composer du conteneur. Sans effet fonctionnel,
> mentionné ici pour que la revue ne s'y arrête pas.

---

## Tâche 2 — Présence : port, adaptateur et endpoint

**Fichiers :**
- Créer : `backend/src/Realtime/Domain/PresenceStoreInterface.php`
- Créer : `backend/src/Realtime/Infrastructure/Presence/RedisPresenceStore.php`
- Créer : `backend/src/Conversation/Application/Contract/ConversationPeersFinderInterface.php`
- Créer : `backend/src/Conversation/Infrastructure/Contract/DbalConversationPeersFinder.php`
- Créer : `backend/src/Realtime/Application/Command/RecordHeartbeatCommand.php`
- Créer : `backend/src/Realtime/Application/Command/RecordHeartbeatCommandHandler.php`
- Créer : `backend/src/Realtime/Application/Query/GetOnlinePeersQuery.php`
- Créer : `backend/src/Realtime/Application/Query/GetOnlinePeersQueryHandler.php`
- Créer : `backend/src/Realtime/Infrastructure/Http/HeartbeatController.php`
- Modifier : `backend/config/services.yaml`
- Test : `backend/tests/Functional/Realtime/PresenceHeartbeatTest.php`

**Interfaces :**
- Consomme : `MemberConversationsFinderInterface` (existant), `SecurityUser::userId()`,
  `CommandDispatcher::dispatch()`, `QueryDispatcher::ask()`.
- Produit : `PresenceStoreInterface::touch(UserId): void` et
  `PresenceStoreInterface::onlineAmong(array $candidates): array` ;
  `ConversationPeersFinderInterface::peerIdsFor(UserId): list<UserId>` ;
  `POST /api/presence/heartbeat` → `200 {"online_user_ids": string[]}`.

- [ ] **Étape 1 : écrire le test fonctionnel qui échoue**

Créer `backend/tests/Functional/Realtime/PresenceHeartbeatTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\DatabaseTestCase;

final class PresenceHeartbeatTest extends DatabaseTestCase
{
    /**
     * Redis n'est PAS transactionnel : le rollback de DatabaseTestCase ne
     * l'atteint pas, et une cle de presence vit 30 s. Sans ce nettoyage, un
     * test verrait la presence laissee par le precedent — un echec qui
     * n'apparaitrait qu'en suite complete, jamais en `--filter`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var \Redis $redis */
        $redis = static::getContainer()->get(\Redis::class);
        $redis->flushDb();
    }

    public function testHeartbeatRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/presence/heartbeat');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Le battement doit renvoyer la presence, pas seulement l'enregistrer :
     * c'est ce qui evite un second aller-retour toutes les 20 s.
     */
    public function testHeartbeatReturnsTheOnlinePeers(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        // La conversation est creee par le test et non supposee dans les
        // fixtures : c'est elle qui fait de Bob un « pair » d'Alice.
        $this->createDirectWith('bob');

        $this->client->request('POST', '/api/presence/heartbeat');
        self::assertResponseIsSuccessful();

        // Alice seule a battu : personne d'autre n'est en ligne.
        self::assertSame(['online_user_ids' => []], $this->json());

        // Bob bat a son tour, dans sa propre session.
        $this->login('bob');
        $this->client->request('POST', '/api/presence/heartbeat');
        self::assertResponseIsSuccessful();

        // Alice revoit la presence de Bob, avec qui elle partage un fil.
        $this->login('alice');
        $this->client->request('POST', '/api/presence/heartbeat');

        /** @var array{online_user_ids: list<string>} $body */
        $body = $this->json();
        self::assertContains($bobId, $body['online_user_ids']);
    }

    /** On ne se voit jamais soi-meme dans la liste : la pastille ne s'affiche pas sur soi. */
    public function testTheCallerIsNeverListedAmongThePeers(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $this->createDirectWith('bob');

        $this->client->request('POST', '/api/presence/heartbeat');

        /** @var array{online_user_ids: list<string>} $body */
        $body = $this->json();
        self::assertNotContains($aliceId, $body['online_user_ids']);
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Étape 2 : lancer le test et vérifier qu'il échoue**

```bash
make functional-test ARGS="--filter=PresenceHeartbeatTest"
```

Attendu : ÉCHEC — 404 sur `/api/presence/heartbeat`, la route n'existe pas.

- [ ] **Étape 3 : écrire le port**

`backend/src/Realtime/Domain/PresenceStoreInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Domain;

use App\Shared\Domain\Identifier\UserId;

/**
 * La presence est un etat EPHEMERE : elle ne va jamais en base principale. Un
 * booleen `is_online` persiste devient faux au premier crash et n'est jamais
 * remis a false — c'est l'anti-pattern que ce port existe pour eviter.
 */
interface PresenceStoreInterface
{
    /** Marque l'utilisateur present et repousse son expiration. */
    public function touch(UserId $userId): void;

    /**
     * Filtre les candidats : ne rend que ceux qui sont presents.
     *
     * Prend les candidats en argument plutot que de rendre « tous les gens en
     * ligne » : la presence de personnes avec qui on ne partage aucun fil ne
     * doit pas pouvoir fuiter. La restriction vit dans la signature, pas dans
     * la discipline de l'appelant.
     *
     * @param  list<UserId> $candidates
     * @return list<UserId>
     */
    public function onlineAmong(array $candidates): array;
}
```

- [ ] **Étape 4 : écrire l'adaptateur Redis**

`backend/src/Realtime/Infrastructure/Presence/RedisPresenceStore.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Presence;

use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class RedisPresenceStore implements PresenceStoreInterface
{
    /**
     * 30 s pour un battement client toutes les 20 s. Le rapport de 1 a 1,5
     * absorbe un aller-retour lent ou un battement manque sans faire clignoter
     * la pastille ; plus serre, la presence devient instable ; plus large, une
     * deconnexion met trop longtemps a se voir.
     */
    public const int TTL_SECONDS = 30;

    private const string KEY_PREFIX = 'presence:';

    public function __construct(private \Redis $redis)
    {
    }

    public function touch(UserId $userId): void
    {
        // La valeur ne porte rien : seule l'EXISTENCE de la cle est
        // l'information. Y stocker un horodatage inviterait a s'en servir, donc
        // a reintroduire une duree de vie geree a la main a cote du TTL.
        $this->redis->setex(self::key($userId), self::TTL_SECONDS, '1');
    }

    public function onlineAmong(array $candidates): array
    {
        if ([] === $candidates) {
            // MGET sans cle est une erreur cote Redis, et un aller-retour pour
            // rien : un utilisateur sans aucune conversation passe par ici.
            return [];
        }

        $keys = array_map(static fn(UserId $id): string => self::key($id), $candidates);

        /** @var list<string|false> $values un `false` par cle absente ou expiree */
        $values = $this->redis->mget($keys);

        $online = [];
        foreach ($candidates as $index => $candidate) {
            if (false !== ($values[$index] ?? false)) {
                $online[] = $candidate;
            }
        }

        return $online;
    }

    private static function key(UserId $userId): string
    {
        return sprintf('%s%s', self::KEY_PREFIX, $userId->toString());
    }
}
```

- [ ] **Étape 5 : écrire le contrat publié des pairs**

`backend/src/Conversation/Application/Contract/ConversationPeersFinderInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Contract;

use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Conversation : « avec qui cette personne partage-t-elle
 * un fil ». Realtime en a besoin pour borner la presence qu'il expose.
 *
 * Interface distincte de MemberConversationsFinderInterface, et non une methode
 * ajoutee a celle-ci : elargir un contrat publie deja consomme est un
 * changement cassant, et les deux questions sont differentes — « quels fils
 * puis-je ecouter » n'est pas « qui sont mes interlocuteurs ».
 *
 * Modifier cette signature est un changement cassant.
 */
interface ConversationPeersFinderInterface
{
    /** @return list<UserId> jamais l'utilisateur lui-meme */
    public function peerIdsFor(UserId $userId): array;
}
```

`backend/src/Conversation/Infrastructure/Contract/DbalConversationPeersFinder.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Application\Contract\ConversationPeersFinderInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/** Conversation est le SEUL contexte a lire conversation_members. */
final readonly class DbalConversationPeersFinder implements ConversationPeersFinderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function peerIdsFor(UserId $userId): array
    {
        /** @var list<array{user_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT peer.user_id
                FROM conversation_members mine
                INNER JOIN conversation_members peer
                        ON peer.conversation_id = mine.conversation_id
                       AND peer.user_id <> mine.user_id
                WHERE mine.user_id = :user_id
                SQL,
            ['user_id' => $userId->toString()],
        );

        return array_map(
            static fn(array $row): UserId => UserId::fromString($row['user_id']),
            $rows,
        );
    }
}
```

- [ ] **Étape 6 : écrire la commande et son handler**

`backend/src/Realtime/Application/Command/RecordHeartbeatCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class RecordHeartbeatCommand implements CommandInterface
{
    public function __construct(public UserId $userId)
    {
    }
}
```

`backend/src/Realtime/Application/Command/RecordHeartbeatCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\Command;

use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;

final readonly class RecordHeartbeatCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PresenceStoreInterface $presence)
    {
    }

    public function __invoke(RecordHeartbeatCommand $command): void
    {
        // Aucun log ici : le middleware du bus loggue deja chaque commande, et
        // un battement toutes les 20 s par utilisateur noierait le journal.
        $this->presence->touch($command->userId);
    }
}
```

- [ ] **Étape 7 : écrire la query et son handler**

`backend/src/Realtime/Application/Query/GetOnlinePeersQuery.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\UserId;

/** @implements QueryInterface<list<string>> */
final readonly class GetOnlinePeersQuery implements QueryInterface
{
    public function __construct(public UserId $userId)
    {
    }
}
```

`backend/src/Realtime/Application/Query/GetOnlinePeersQueryHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\Query;

use App\Conversation\Application\Contract\ConversationPeersFinderInterface;
use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Application\Bus\QueryHandlerInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class GetOnlinePeersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ConversationPeersFinderInterface $peers,
        private PresenceStoreInterface $presence,
    ) {
    }

    /** @return list<string> */
    public function __invoke(GetOnlinePeersQuery $query): array
    {
        $online = $this->presence->onlineAmong($this->peers->peerIdsFor($query->userId));

        return array_map(static fn(UserId $id): string => $id->toString(), $online);
    }
}
```

- [ ] **Étape 8 : écrire le contrôleur**

`backend/src/Realtime/Infrastructure/Http/HeartbeatController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Realtime\Application\Command\RecordHeartbeatCommand;
use App\Realtime\Application\Query\GetOnlinePeersQuery;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Un seul aller-retour toutes les 20 s : il rafraichit le TTL ET rend la
 * presence. Deux routes separees doubleraient le trafic pour faire respecter
 * une separation qui l'est deja ici — la commande ecrit et ne rend rien, la
 * query lit. C'est exactement le « pour connaitre l'effet d'une ecriture, on
 * pose ensuite une query » du CQS, applique dans un adaptateur primaire.
 */
final readonly class HeartbeatController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route('/api/presence/heartbeat', name: 'presence_heartbeat', methods: ['POST'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $this->commands->dispatch(new RecordHeartbeatCommand($securityUser->userId()));

        return new JsonResponse([
            'online_user_ids' => $this->queries->ask(new GetOnlinePeersQuery($securityUser->userId())),
        ]);
    }
}
```

- [ ] **Étape 9 : câbler les services**

Dans `backend/config/services.yaml`, ajouter sous le bloc des ports :

```yaml
    App\Realtime\Domain\PresenceStoreInterface: '@App\Realtime\Infrastructure\Presence\RedisPresenceStore'
```

et à côté des deux contrats de `Conversation` déjà déclarés :

```yaml
    App\Conversation\Application\Contract\ConversationPeersFinderInterface: '@App\Conversation\Infrastructure\Contract\DbalConversationPeersFinder'
```

`\Redis` n'est pas autowirable : le déclarer explicitement, à la fin du fichier.

```yaml
    # `\Redis` est une classe d'extension, sans service par defaut : on la
    # construit ici. `connect()` est paresseux du point de vue de Symfony — le
    # service n'est instancie que si quelqu'un l'injecte.
    Redis:
        calls:
            - connect: ['%env(string:REDIS_HOST)%', '%env(int:REDIS_PORT)%']
```

`REDIS_HOST` et `REDIS_PORT` ont été posées en tâche 1, dans les deux fichiers compose.

- [ ] **Étape 10 : lancer le test et vérifier qu'il passe**

```bash
make functional-test ARGS="--filter=PresenceHeartbeatTest"
```

Attendu : 3 tests verts.

- [ ] **Étape 11 : vérifier les portes de qualité**

```bash
make static-code-analysis && make check-cs && make deptrac
```

Attendu : zéro violation. `Realtime` consommant `ConversationContract` est déjà autorisé par
`deptrac-contexts.yaml`.

- [ ] **Étape 12 : commit**

```bash
git add backend/src/Realtime backend/src/Conversation/Application/Contract backend/src/Conversation/Infrastructure/Contract backend/config/services.yaml backend/tests/Functional/Realtime compose.yaml compose.test.yaml
git commit -m "feat(presence): exposer la presence ephemere par un battement de coeur"
```

---

## Tâche 3 — Présence côté front

**Fichiers :**
- Créer : `frontend/src/store/presenceReducer.ts`
- Créer : `frontend/src/store/presenceReducer.test.ts`
- Créer : `frontend/src/hooks/useHeartbeat.ts`
- Créer : `frontend/src/ui/PresenceDot.tsx`
- Modifier : `frontend/src/api/client.ts`, `frontend/src/api/types.ts`
- Modifier : `frontend/src/hooks/useAppState.ts`
- Modifier : `frontend/src/ui/ConversationList.tsx`

**Interfaces :**
- Consomme : `POST /api/presence/heartbeat` → `{online_user_ids: string[]}`.
- Produit : `AppState.onlineUserIds: Set<string>` ; `<PresenceDot online={boolean} />`.

- [ ] **Étape 1 : écrire le test du reducer**

Créer `frontend/src/store/presenceReducer.test.ts` :

```ts
import { describe, expect, it } from 'vitest';
import { emptyPresenceState, presenceReducer } from './presenceReducer';

describe('presenceReducer', () => {
  it('remplace la presence au lieu de la fusionner', () => {
    const first = presenceReducer(emptyPresenceState(), {
      type: 'presence/refreshed',
      onlineUserIds: ['alice', 'bob'],
    });

    const second = presenceReducer(first, {
      type: 'presence/refreshed',
      onlineUserIds: ['alice'],
    });

    // C'est l'invariant du reducer : fusionner ferait qu'un utilisateur passe
    // hors ligne ne disparaitrait jamais de la liste.
    expect([...second.onlineUserIds]).toEqual(['alice']);
  });

  it('rend un ensemble vide quand plus personne n est en ligne', () => {
    const state = presenceReducer(emptyPresenceState(), {
      type: 'presence/refreshed',
      onlineUserIds: [],
    });

    expect(state.onlineUserIds.size).toBe(0);
  });
});
```

- [ ] **Étape 2 : lancer et vérifier l'échec**

```bash
make front-test ARGS="presenceReducer"
```

Attendu : ÉCHEC — module `./presenceReducer` introuvable.

> Si `front-test` n'accepte pas `ARGS`, lire le `Makefile` et lancer la suite complète
> (`make front-test`) — ne pas inventer d'option.

- [ ] **Étape 3 : écrire le reducer**

`frontend/src/store/presenceReducer.ts` :

```ts
/**
 * Presence en ligne, reçue en bloc a chaque battement de coeur.
 *
 * Le reducer REMPLACE l'ensemble, il ne le fusionne jamais : le serveur rend la
 * liste complete des pairs presents, donc fusionner ferait qu'un utilisateur
 * passe hors ligne resterait affiche en ligne pour toujours.
 */
export type PresenceState = { onlineUserIds: Set<string> };

export type PresenceAction = { type: 'presence/refreshed'; onlineUserIds: string[] };

export function emptyPresenceState(): PresenceState {
  return { onlineUserIds: new Set() };
}

export function presenceReducer(_state: PresenceState, action: PresenceAction): PresenceState {
  switch (action.type) {
    case 'presence/refreshed':
      return { onlineUserIds: new Set(action.onlineUserIds) };
  }
}
```

- [ ] **Étape 4 : relancer et vérifier que ça passe**

```bash
make front-test
```

Attendu : les deux tests verts, aucune régression.

- [ ] **Étape 5 : ajouter l'appel API**

Dans `frontend/src/api/types.ts`, ajouter :

```ts
export type HeartbeatResponse = { online_user_ids: string[] };
```

Dans `frontend/src/api/client.ts`, importer `HeartbeatResponse` puis ajouter à l'objet `api` :

```ts
  heartbeat: () => request<HeartbeatResponse>('/api/presence/heartbeat', { method: 'POST' }),
```

- [ ] **Étape 6 : écrire le hook**

`frontend/src/hooks/useHeartbeat.ts` :

```ts
import { useEffect } from 'react';
import { api } from '../api/client';

/** 20 s pour un TTL serveur de 30 s : une marge d'un battement manque. */
const HEARTBEAT_INTERVAL_MS = 20_000;

/**
 * Bat toutes les 20 s et remonte qui est en ligne.
 *
 * Pourquoi un sondage et non un evenement pousse : l'expiration d'une cle Redis
 * n'est PAS un evenement. Personne ne peut publier « untel vient de passer hors
 * ligne » au moment ou sa cle expire. Ne pousser que la transition inverse
 * donnerait un statut qui monte et ne redescend jamais — exactement le booleen
 * `is_online` perime qu'on cherche a eviter.
 *
 * Le battement est suspendu quand l'onglet est cache : un onglet en arriere-plan
 * n'a pas besoin de se declarer en ligne, et le navigateur brimerait de toute
 * facon ses minuteurs.
 */
export function useHeartbeat(onOnlineUserIds: (ids: string[]) => void): void {
  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | null = null;

    const beat = () => {
      void api
        .heartbeat()
        .then((response) => onOnlineUserIds(response.online_user_ids))
        .catch(() => {
          // Un battement manque n'est pas un incident : le suivant corrige. On
          // ne journalise pas, sous peine d'une ligne toutes les 20 s hors ligne.
        });
    };

    const start = () => {
      if (timer !== null) return;

      beat();
      timer = setInterval(beat, HEARTBEAT_INTERVAL_MS);
    };

    const stop = () => {
      if (timer === null) return;

      clearInterval(timer);
      timer = null;
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') start();
      else stop();
    };

    onVisibilityChange();
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange);
      stop();
    };
  }, [onOnlineUserIds]);
}
```

- [ ] **Étape 7 : brancher dans `useAppState`**

Dans `frontend/src/hooks/useAppState.ts` :

1. Ajouter aux imports :

```ts
import { emptyPresenceState, presenceReducer } from '../store/presenceReducer';
import { useHeartbeat } from './useHeartbeat';
```

2. Après la ligne `const [messagesState, dispatch] = useReducer(...)`, ajouter :

```ts
  const [presenceState, dispatchPresence] = useReducer(
    presenceReducer,
    undefined,
    emptyPresenceState,
  );
```

3. Avant le `return`, ajouter :

```ts
  // `useCallback([])` : `dispatchPresence` est stable, le hook ne doit donc pas
  // se remonter a chaque rendu — sinon le battement repartirait de zero.
  const onOnlineUserIds = useCallback((ids: string[]) => {
    dispatchPresence({ type: 'presence/refreshed', onlineUserIds: ids });
  }, []);

  useHeartbeat(onOnlineUserIds);
```

4. Ajouter `onlineUserIds: Set<string>;` au type `AppState`, et
   `onlineUserIds: presenceState.onlineUserIds,` à l'objet retourné.

- [ ] **Étape 8 : écrire la pastille**

`frontend/src/ui/PresenceDot.tsx` :

```tsx
/**
 * Pastille de presence. `aria-hidden` n'est PAS pose : l'information n'existe
 * nulle part ailleurs dans l'interface, un lecteur d'ecran doit donc l'entendre.
 */
export function PresenceDot({ online }: { online: boolean }) {
  return (
    <span
      className={`inline-block h-2 w-2 rounded-full ${online ? 'bg-emerald-500' : 'bg-slate-300'}`}
      title={online ? 'En ligne' : 'Hors ligne'}
      role="img"
      aria-label={online ? 'En ligne' : 'Hors ligne'}
    />
  );
}
```

- [ ] **Étape 9 : afficher la pastille dans la liste**

Dans `frontend/src/ui/ConversationList.tsx` : ajouter `onlineUserIds: Set<string>` et
`peers: Record<string, string>` aux props si absents, importer `PresenceDot`, et pour une
conversation de type `direct`, rendre à côté du nom :

```tsx
<PresenceDot online={onlineUserIds.has(peers[conversation.id] ?? '')} />
```

Le passer depuis `App.tsx` / le composant parent, qui tient déjà `peers`.

- [ ] **Étape 10 : vérifier**

```bash
make front-test && make front-typecheck
```

Attendu : tout vert.

- [ ] **Étape 11 : commit**

```bash
git add frontend/src
git commit -m "feat(front): afficher la presence en ligne par battement de coeur"
```

---

## Tâche 4 — Indicateur de frappe

**Fichiers :**
- Modifier : `backend/src/Realtime/Domain/EventPublisherInterface.php`
- Modifier : `backend/src/Realtime/Infrastructure/Mercure/MercureEventPublisher.php`
- Modifier : `backend/tests/Support/InMemoryEventPublisher.php`
- Créer : `backend/src/Realtime/Infrastructure/Http/TypingController.php`
- Test : `backend/tests/Functional/Realtime/TypingTest.php`
- Créer : `frontend/src/store/typingReducer.ts` + `.test.ts`
- Créer : `frontend/src/ui/TypingIndicator.tsx`
- Modifier : `frontend/src/api/client.ts`, `frontend/src/hooks/useAppState.ts`,
  `frontend/src/ui/Composer.tsx`, `frontend/src/ui/ConversationView.tsx`

**Interfaces :**
- Consomme : `ConversationMembershipInterface::isMember()`, `EventPublisherInterface::publish()`.
- Produit : `POST /api/conversations/{id}/typing` → `204` ; événement Mercure `typing.started`
  de charge utile `{conversation_id, user_id}` sur `/conversations/{id}`.

- [ ] **Étape 1 : écrire le test fonctionnel**

Créer `backend/tests/Functional/Realtime/TypingTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class TypingTest extends DatabaseTestCase
{
    /** Un non-membre recoit 404, jamais 403 : un 403 confirmerait l'existence du fil. */
    public function testANonMemberGetsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('carol');
        $this->client->request('POST', sprintf('/api/conversations/%s/typing', $conversationId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberPublishesTypingOnTheConversationTopic(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->client->request('POST', sprintf('/api/conversations/%s/typing', $conversationId));
        self::assertResponseStatusCodeSame(204);

        $typing = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'typing.started' === $entry['type'],
        ));

        self::assertCount(1, $typing);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $typing[0]['topic']);
        self::assertSame(
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
            $typing[0]['payload'],
        );
        // Aucun identifiant SSE : rejouer une frappe terminee n'a aucun sens.
        self::assertNull($typing[0]['id']);
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Étape 2 : lancer et vérifier l'échec**

```bash
make functional-test ARGS="--filter=TypingTest"
```

Attendu : ÉCHEC — 404 sur la route, qui n'existe pas.

- [ ] **Étape 3 : rendre l'identifiant d'événement optionnel**

Dans `backend/src/Realtime/Domain/EventPublisherInterface.php`, remplacer la signature et son
PHPDoc :

```php
    /**
     * @param non-empty-string      $eventType type logique de l'evenement, ex. "message.created"
     * @param array<string, mixed>  $payload
     * @param non-empty-string|null $eventId   identifiant de l'evenement SSE, quand il en merite un
     *
     * `$eventId` est nul pour les evenements qui n'ont aucune valeur historique :
     * une frappe terminee n'a pas a etre rejouee a la reconnexion, et un accusé
     * est autoreparateur — l'etat complet est recharge au GET du detail. Leur
     * donner un id les inscrirait dans un flux de rejeu ou ils n'ont rien a faire.
     */
    public function publish(Topic $topic, string $eventType, array $payload, ?string $eventId = null): void;
```

Dans `MercureEventPublisher`, adapter la signature à l'identique — `Update` accepte déjà `?string`
pour `id`, aucun autre changement n'est nécessaire.

Dans `tests/Support/InMemoryEventPublisher.php`, adapter la signature et le PHPDoc du tableau :
`array{topic: string, type: string, payload: array<string, mixed>, id: string|null}` (deux
occurrences).

- [ ] **Étape 4 : écrire le contrôleur**

`backend/src/Realtime/Infrastructure/Http/TypingController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * La frappe ne passe PAS par la choregraphie, contrairement aux accuses.
 *
 * Elle n'ecrit rien : ni agregat, ni transaction, ni domain event a enregistrer.
 * La faire transiter par une commande vide, a travers un middleware
 * transactionnel qui n'aurait aucune transaction a ouvrir, serait du ceremonial.
 * La choregraphie sert a ne pas publier ce qui n'est pas commite ; sans
 * ecriture, elle n'a rien a proteger.
 */
final readonly class TypingController
{
    public function __construct(
        private ConversationMembershipInterface $membership,
        private EventPublisherInterface $publisher,
    ) {
    }

    #[Route('/api/conversations/{conversationId}/typing', name: 'conversation_typing', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // 404 et non 403 : un 403 confirmerait que la conversation existe.
        if (!$this->membership->isMember($conversationId, $securityUser->userId())) {
            throw new NotFoundHttpException();
        }

        $this->publisher->publish(
            Topic::conversation($conversationId),
            'typing.started',
            [
                'conversation_id' => $conversationId->toString(),
                'user_id' => $securityUser->userId()->toString(),
            ],
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Étape 5 : lancer les tests backend**

```bash
make functional-test ARGS="--filter=TypingTest"
make test
```

Attendu : `TypingTest` vert, et **aucune régression** — la signature de `publish()` a changé, tous
les appels existants doivent continuer de passer.

- [ ] **Étape 6 : écrire le test du reducer de frappe**

Créer `frontend/src/store/typingReducer.test.ts` :

```ts
import { describe, expect, it } from 'vitest';
import { emptyTypingState, selectTypists, typingReducer } from './typingReducer';

const CONVERSATION = 'conv-1';

describe('typingReducer', () => {
  it('retient un frappeur pendant la duree de vie de l indicateur', () => {
    const state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    expect(selectTypists(state, CONVERSATION, 1_000)).toEqual(['alice']);
  });

  it('oublie un frappeur une fois son delai ecoule', () => {
    const state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    // 5 s plus tard, plus une milliseconde : l'indicateur a expire.
    expect(selectTypists(state, CONVERSATION, 6_001)).toEqual([]);
  });

  it('repousse l expiration a chaque nouvelle frappe', () => {
    let state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    state = typingReducer(state, {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 4_000,
    });

    expect(selectTypists(state, CONVERSATION, 6_001)).toEqual(['alice']);
  });

  it('efface le frappeur des que son message arrive', () => {
    let state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    state = typingReducer(state, {
      type: 'typing/cleared',
      conversationId: CONVERSATION,
      userId: 'alice',
    });

    expect(selectTypists(state, CONVERSATION, 1_100)).toEqual([]);
  });
});
```

- [ ] **Étape 7 : lancer et vérifier l'échec**

```bash
make front-test
```

Attendu : ÉCHEC — module `./typingReducer` introuvable.

- [ ] **Étape 8 : écrire le reducer de frappe**

`frontend/src/store/typingReducer.ts` :

```ts
/**
 * « En train d'ecrire » : etat ephemere, jamais persiste nulle part.
 *
 * Le reducer stocke une DATE D'EXPIRATION par frappeur, et `now` lui est
 * toujours passe en argument — il ne lit jamais l'horloge lui-meme. C'est ce
 * qui le rend testable sans faux minuteurs : une fonction pure de ses entrees.
 *
 * Il n'existe pas d'evenement « a arrete d'ecrire ». Un contre-evenement
 * doublerait le trafic pour une information deductible, et introduirait un mode
 * d'echec propre : un `stopped` perdu laisserait l'indicateur affiche pour
 * toujours. Une expiration est autoreparatrice par construction.
 */
const TYPING_TTL_MS = 5_000;

export type TypingState = {
  /** conversationId -> (userId -> instant d'expiration en ms) */
  byConversation: Record<string, Record<string, number>>;
};

export type TypingAction =
  | { type: 'typing/started'; conversationId: string; userId: string; now: number }
  | { type: 'typing/cleared'; conversationId: string; userId: string };

export function emptyTypingState(): TypingState {
  return { byConversation: {} };
}

export function typingReducer(state: TypingState, action: TypingAction): TypingState {
  switch (action.type) {
    case 'typing/started': {
      const current = state.byConversation[action.conversationId] ?? {};

      return {
        byConversation: {
          ...state.byConversation,
          [action.conversationId]: { ...current, [action.userId]: action.now + TYPING_TTL_MS },
        },
      };
    }

    case 'typing/cleared': {
      const current = state.byConversation[action.conversationId];
      if (current === undefined || !(action.userId in current)) return state;

      const { [action.userId]: _removed, ...rest } = current;

      return { byConversation: { ...state.byConversation, [action.conversationId]: rest } };
    }
  }
}

/** Frappeurs encore valides a l'instant `now`. Le tri rend l'affichage stable. */
export function selectTypists(state: TypingState, conversationId: string, now: number): string[] {
  const entries = state.byConversation[conversationId] ?? {};

  return Object.entries(entries)
    .filter(([, expiresAt]) => expiresAt > now)
    .map(([userId]) => userId)
    .sort();
}

/** Y a-t-il au moins un frappeur actif, toutes conversations confondues ? */
export function hasActiveTypists(state: TypingState, now: number): boolean {
  return Object.values(state.byConversation).some((entries) =>
    Object.values(entries).some((expiresAt) => expiresAt > now),
  );
}
```

- [ ] **Étape 9 : relancer les tests front**

```bash
make front-test
```

Attendu : les 4 tests verts.

- [ ] **Étape 10 : ajouter l'appel API et le throttle**

Dans `frontend/src/api/client.ts`, ajouter à l'objet `api` :

```ts
  typing: (conversationId: string) =>
    request<void>(`/api/conversations/${conversationId}/typing`, { method: 'POST' }),
```

Créer `frontend/src/hooks/useTyping.ts` :

```ts
import { useCallback, useRef } from 'react';
import { api } from '../api/client';

/**
 * Au plus un POST toutes les 3 s pendant la frappe.
 *
 * Sans etranglement, chaque touche produirait une requete : une phrase de
 * quarante caracteres inonderait le hub de quarante evenements identiques. 3 s
 * pour un indicateur qui vit 5 s cote destinataire : l'affichage ne clignote
 * jamais entre deux envois.
 */
const TYPING_THROTTLE_MS = 3_000;

export function useTyping(): (conversationId: string) => void {
  // `ref` et non `state` : modifier cette date ne doit declencher aucun rendu.
  const lastSentAtRef = useRef<Record<string, number>>({});

  return useCallback((conversationId: string) => {
    const now = Date.now();

    if (now - (lastSentAtRef.current[conversationId] ?? 0) < TYPING_THROTTLE_MS) {
      return;
    }

    lastSentAtRef.current[conversationId] = now;

    void api.typing(conversationId).catch(() => {
      // Une frappe non signalee n'a aucune consequence : on n'en fait rien.
    });
  }, []);
}
```

- [ ] **Étape 11 : brancher dans `useAppState`**

Dans `frontend/src/hooks/useAppState.ts` :

1. Imports :

```ts
import { emptyTypingState, typingReducer } from '../store/typingReducer';
import { useTyping } from './useTyping';
```

2. Ajouter `'typing.started'` au tableau `NAMED_EVENTS` :

```ts
const NAMED_EVENTS = ['message.created', 'membership.changed', 'typing.started'];
```

3. Après les autres `useReducer` :

```ts
  const [typingState, dispatchTyping] = useReducer(typingReducer, undefined, emptyTypingState);
  const notifyTyping = useTyping();
```

4. Dans `onEvent`, à l'intérieur de la branche `message.created`, **avant** le `return`, ajouter :

```ts
          // Le message est arrive : son auteur n'ecrit plus. Cela remplace un
          // evenement `typing.stopped` que le backend n'emet volontairement pas.
          dispatchTyping({
            type: 'typing/cleared',
            conversationId: readString(event.payload, 'conversation_id'),
            userId: readString(event.payload, 'sender_id'),
          });
```

5. Ajouter une branche, avant celle de `membership.changed` :

```ts
        if (event.type === 'typing.started') {
          const userId = readString(event.payload, 'user_id');

          // Sa propre frappe revient par le hub : l'afficher ferait apparaitre
          // « vous ecrivez… » dans sa propre fenetre.
          if (userId !== me.id) {
            dispatchTyping({
              type: 'typing/started',
              conversationId: readString(event.payload, 'conversation_id'),
              userId,
              now: Date.now(),
            });
          }

          return;
        }
```

6. Ajouter `me.id` aux dépendances de cet effet — il passe de `[refreshConversations]` à
   `[refreshConversations, me.id]`.

7. Exposer dans `AppState` : `typingState: TypingState;` et `notifyTyping: (conversationId: string) => void;`,
   et les ajouter à l'objet retourné.

- [ ] **Étape 12 : écrire l'indicateur**

`frontend/src/ui/TypingIndicator.tsx` :

```tsx
import { useEffect, useState } from 'react';
import { selectTypists, type TypingState } from '../store/typingReducer';
import type { UserSummary } from '../api/types';

/**
 * Un indicateur qui expire tout seul n'entraine AUCUN rendu.
 *
 * Le store sait que la frappe d'Alice expire dans 5 s, mais React ne le sait
 * pas : sans reveil periodique, la ligne resterait affichee indefiniment. D'ou
 * ce tic d'une seconde — et il ne tourne QUE tant qu'il reste un frappeur, sinon
 * l'application entiere se reveillerait chaque seconde pour rien.
 */
export function TypingIndicator({
  typingState,
  conversationId,
  users,
}: {
  typingState: TypingState;
  conversationId: string;
  users: Record<string, UserSummary>;
}) {
  const [now, setNow] = useState(() => Date.now());

  const typists = selectTypists(typingState, conversationId, now);

  useEffect(() => {
    if (typists.length === 0) return;

    const timer = setInterval(() => setNow(Date.now()), 1_000);

    return () => clearInterval(timer);
  }, [typists.length]);

  if (typists.length === 0) return null;

  const names = typists.map((id) => users[id]?.display_name ?? 'Quelqu un');
  const label =
    names.length === 1 ? `${names[0]} écrit…` : `${names.join(', ')} écrivent…`;

  return (
    <p className="px-4 py-1 text-sm italic text-slate-500" aria-live="polite">
      {label}
    </p>
  );
}
```

- [ ] **Étape 13 : brancher l'indicateur et la notification de frappe**

Dans `frontend/src/ui/ConversationView.tsx`, rendre `<TypingIndicator …/>` juste au-dessus du
`<Composer …/>`, en lui passant `typingState`, `conversationId` et `users`.

Dans `frontend/src/ui/Composer.tsx`, ajouter une prop `onTyping: () => void` et l'appeler dans le
`onChange` du champ de saisie, à côté du `setValue` existant.

Faire descendre `notifyTyping` et `typingState` depuis `useAppState` jusqu'à ces composants.

- [ ] **Étape 14 : vérifier**

```bash
make front-test && make front-typecheck && make test && make static-code-analysis && make check-cs && make deptrac
```

- [ ] **Étape 15 : commit**

```bash
git add backend/src backend/tests frontend/src
git commit -m "feat(realtime): signaler la frappe en cours sur le topic de conversation"
```

---

## Tâche 5 — Watermarks : migration, domaine, endpoint

**Aucun temps réel, aucun front dans cette tâche.** Les watermarks doivent être corrects et testés
avant qu'on les diffuse : mélanger un `WHERE` faux et un topic faux dans une même revue rend le
diagnostic bien plus coûteux.

**Fichiers :**
- Créer : `backend/migrations/VersionYYYYMMDDHHMMSS.php`
- Créer : `backend/src/Conversation/Domain/Membership.php`
- Créer : `backend/src/Conversation/Domain/MembershipRepositoryInterface.php`
- Créer : `backend/src/Conversation/Infrastructure/Persistence/DbalMembershipRepository.php`
- Créer : `backend/src/Conversation/Application/Command/AdvanceReceiptsCommand.php` + `Handler`
- Créer : `backend/src/Conversation/Infrastructure/Http/AdvanceReceiptsController.php`
- Créer : `backend/src/Conversation/Infrastructure/Http/Payload/AdvanceReceiptsPayload.php`
- Créer : `backend/src/Shared/Domain/Event/ReceiptWatermarkAdvanced.php`
- Modifier : `backend/config/services.yaml`
- Tests : `backend/tests/Unit/Conversation/Domain/MembershipTest.php`,
  `backend/tests/Functional/Conversation/AdvanceReceiptsTest.php`

**Interfaces :**
- Produit : `Membership::advanceDeliveredTo(?string): void`, `Membership::advanceReadTo(?string): void`,
  `Membership::lastDeliveredMessageId(): ?string`, `Membership::lastReadMessageId(): ?string` ;
  `MembershipRepositoryInterface::ofMember(ConversationId, UserId): Membership` et `save(Membership): void` ;
  `ReceiptWatermarkAdvanced(ConversationId, UserId, ?string $lastDeliveredMessageId, ?string $lastReadMessageId)` ;
  `POST /api/conversations/{id}/receipts` → `204`.

- [ ] **Étape 1 : écrire le test unitaire de monotonie — LE test de la tranche**

Créer `backend/tests/Unit/Conversation/Domain/MembershipTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Membership;
use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    private const string CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';
    private const string USER = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testAFirstWatermarkAdvancesFromNull(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::OLDER, $membership->lastReadMessageId());
        self::assertCount(1, $membership->releaseEvents());
    }

    public function testAnOlderWatermarkNeverMovesTheCursorBack(): void
    {
        $membership = $this->membership(null, self::NEWER);

        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::NEWER, $membership->lastReadMessageId());
        self::assertSame([], $membership->releaseEvents(), 'Aucun evenement si rien n a bouge.');
    }

    public function testAnIdenticalWatermarkRecordsNothing(): void
    {
        $membership = $this->membership(null, self::NEWER);

        $membership->advanceReadTo(self::NEWER);

        self::assertSame([], $membership->releaseEvents());
    }

    public function testANullWatermarkIsIgnored(): void
    {
        $membership = $this->membership(null, self::NEWER);

        // Le client n'envoie que le curseur qui a bouge : l'autre arrive a null.
        $membership->advanceReadTo(null);

        self::assertSame(self::NEWER, $membership->lastReadMessageId());
        self::assertSame([], $membership->releaseEvents());
    }

    public function testBothCursorsAdvanceIndependently(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceDeliveredTo(self::NEWER);
        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::NEWER, $membership->lastDeliveredMessageId());
        self::assertSame(self::OLDER, $membership->lastReadMessageId());
    }

    /** L'evenement porte TOUJOURS les deux curseurs, meme si un seul a bouge. */
    public function testTheRecordedEventCarriesBothCursors(): void
    {
        $membership = $this->membership(self::OLDER, null);

        $membership->advanceReadTo(self::NEWER);

        $events = $membership->releaseEvents();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(ReceiptWatermarkAdvanced::class, $event);
        self::assertSame(self::OLDER, $event->lastDeliveredMessageId);
        self::assertSame(self::NEWER, $event->lastReadMessageId);
    }

    /** Deux curseurs avances d'un coup ne produisent qu'UN evenement. */
    public function testAdvancingBothCursorsRecordsASingleEvent(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceDeliveredTo(self::NEWER);
        $membership->advanceReadTo(self::NEWER);

        self::assertCount(1, $membership->releaseEvents());
    }

    private function membership(?string $delivered, ?string $read): Membership
    {
        return Membership::reconstitute(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::USER),
            $delivered,
            $read,
        );
    }
}
```

- [ ] **Étape 2 : lancer et vérifier l'échec**

```bash
make unit-test ARGS="--filter=MembershipTest"
```

Attendu : ÉCHEC — classe `Membership` introuvable.

- [ ] **Étape 3 : écrire l'événement partagé**

`backend/src/Shared/Domain/Event/ReceiptWatermarkAdvanced.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Conversation, ecoute par Realtime : evenement inter-contextes, donc
 * dans Shared. Charge utile faite de types Shared et de scalaires uniquement.
 *
 * Les watermarks voyagent en `string` et non en MessageId : ce sont des
 * curseurs, pas des references. Un curseur doit survivre a la suppression du
 * message qu'il designe, que la tranche 3 va introduire.
 *
 * Les DEUX curseurs sont transportes a chaque fois, meme si un seul a bouge :
 * le destinataire remplace l'etat du membre au lieu de le fusionner, ce qui
 * rend le traitement idempotent et supprime toute dependance a l'ordre
 * d'arrivee des evenements.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class ReceiptWatermarkAdvanced implements DomainEventInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public ?string $lastDeliveredMessageId,
        public ?string $lastReadMessageId,
    ) {
    }
}
```

- [ ] **Étape 4 : écrire l'entité `Membership`**

`backend/src/Conversation/Domain/Membership.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * L'etat de lecture d'un membre. Un watermark est une propriete de
 * l'APPARTENANCE — « ce membre a lu jusqu'a X » — au meme titre que son role,
 * pas une entite avec sa propre identite.
 *
 * L'invariant de la tranche : un watermark ne recule JAMAIS. Il est enonce ici,
 * en PHP lisible et testable, et garanti une seconde fois par le `WHERE` du
 * repository — celui qui tranche sous concurrence est celui qui touche la base.
 *
 * Les curseurs sont des `string` et non des MessageId : ils designent une
 * position dans un ordre, pas une reference a une ligne existante. Le tri
 * lexicographique des ULID EST le tri chronologique — c'est la propriete qui a
 * justifie leur choix, et elle sert ici directement.
 */
final class Membership
{
    use RecordsEventsTrait;

    private bool $advanced = false;

    private function __construct(
        private readonly ConversationId $conversationId,
        private readonly UserId $userId,
        private ?string $lastDeliveredMessageId,
        private ?string $lastReadMessageId,
    ) {
    }

    public static function reconstitute(
        ConversationId $conversationId,
        UserId $userId,
        ?string $lastDeliveredMessageId,
        ?string $lastReadMessageId,
    ): self {
        return new self($conversationId, $userId, $lastDeliveredMessageId, $lastReadMessageId);
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function lastDeliveredMessageId(): ?string
    {
        return $this->lastDeliveredMessageId;
    }

    public function lastReadMessageId(): ?string
    {
        return $this->lastReadMessageId;
    }

    public function advanceDeliveredTo(?string $watermark): void
    {
        if (!self::movesForward($this->lastDeliveredMessageId, $watermark)) {
            return;
        }

        $this->lastDeliveredMessageId = $watermark;
        $this->markAdvanced();
    }

    public function advanceReadTo(?string $watermark): void
    {
        if (!self::movesForward($this->lastReadMessageId, $watermark)) {
            return;
        }

        $this->lastReadMessageId = $watermark;
        $this->markAdvanced();
    }

    /**
     * Un seul evenement, meme si les deux curseurs bougent : il porte de toute
     * facon l'etat complet. En emettre deux ferait publier deux fois la meme
     * information sur le hub.
     */
    private function markAdvanced(): void
    {
        if ($this->advanced) {
            $this->replaceRecordedEvent();

            return;
        }

        $this->advanced = true;
        $this->recordEvent($this->toEvent());
    }

    private function replaceRecordedEvent(): void
    {
        $this->releaseEvents();
        $this->recordEvent($this->toEvent());
    }

    private function toEvent(): ReceiptWatermarkAdvanced
    {
        return new ReceiptWatermarkAdvanced(
            $this->conversationId,
            $this->userId,
            $this->lastDeliveredMessageId,
            $this->lastReadMessageId,
        );
    }

    /** `null` n'est pas une demande de recul : c'est « ce curseur ne bouge pas ». */
    private static function movesForward(?string $current, ?string $candidate): bool
    {
        if (null === $candidate) {
            return false;
        }

        return null === $current || strcmp($candidate, $current) > 0;
    }
}
```

- [ ] **Étape 5 : relancer les tests unitaires**

```bash
make unit-test ARGS="--filter=MembershipTest"
```

Attendu : 7 tests verts.

- [ ] **Étape 6 : générer et écrire la migration**

```bash
make generate-migration
```

Ouvrir le fichier créé dans `backend/migrations/` et le remplir :

```php
    public function getDescription(): string
    {
        return 'Watermarks distribue et lu sur conversation_members (tranche 2).';
    }

    public function up(Schema $schema): void
    {
        // Deux colonnes, rien d'autre. Ni presence ni frappe : ce sont des etats
        // ephemeres, et le fait qu'aucune migration ne les mentionne EST la
        // demonstration de la these de la tranche.
        //
        // Aucun index : la cle primaire (conversation_id, user_id) couvre
        // l'UPDATE, et l'agregation « lu par 3/5 » balaie les quelques membres
        // d'un seul fil. Un index sur une colonne reecrite a chaque message lu
        // couterait plus qu'il ne rapporterait.
        //
        // Aucune FOREIGN KEY vers messages : un watermark est un curseur, pas une
        // reference. Il doit survivre a la suppression du message qu'il designe
        // (tranche 3) — un ON DELETE SET NULL ferait RECULER le curseur, ce que
        // toute la tranche s'emploie a rendre impossible.
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_members
                ADD COLUMN last_delivered_message_id CHAR(26) DEFAULT NULL,
                ADD COLUMN last_read_message_id      CHAR(26) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_members
                DROP COLUMN last_delivered_message_id,
                DROP COLUMN last_read_message_id
            SQL);
    }
```

Puis l'appliquer :

```bash
make migrate && make migration-status
```

- [ ] **Étape 7 : écrire le test fonctionnel de l'endpoint**

Créer `backend/tests/Functional/Conversation/AdvanceReceiptsTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;

final class AdvanceReceiptsTest extends DatabaseTestCase
{
    private const string OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testANonMemberGetsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('carol');
        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMalformedWatermarkIsRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, ['read_up_to' => 'pas-un-ulid']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheWatermarkIsStoredAndNeverMovesBack(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);
        self::assertResponseStatusCodeSame(204);

        $this->postReceipts($conversationId, ['read_up_to' => self::OLDER]);
        self::assertResponseStatusCodeSame(204);

        $stored = $this->connection->fetchOne(
            <<<'SQL'
                SELECT last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
        );

        self::assertSame(self::NEWER, $stored, 'Un watermark ne recule jamais.');
    }

    public function testBothCursorsCanAdvanceInOneCall(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, [
            'delivered_up_to' => self::NEWER,
            'read_up_to' => self::OLDER,
        ]);
        self::assertResponseStatusCodeSame(204);

        /** @var array{last_delivered_message_id: string, last_read_message_id: string} $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT last_delivered_message_id, last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
        );

        self::assertSame(self::NEWER, $row['last_delivered_message_id']);
        self::assertSame(self::OLDER, $row['last_read_message_id']);
    }

    /** @param array<string, string> $body */
    private function postReceipts(string $conversationId, array $body): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/receipts', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Étape 8 : lancer et vérifier l'échec**

```bash
make functional-test ARGS="--filter=AdvanceReceiptsTest"
```

Attendu : ÉCHEC — la route n'existe pas.

- [ ] **Étape 9 : écrire le port et le repository**

`backend/src/Conversation/Domain/MembershipRepositoryInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

interface MembershipRepositoryInterface
{
    /** @throws ConversationNotFoundException si la personne n'est pas membre */
    public function ofMember(ConversationId $conversationId, UserId $userId): Membership;

    public function save(Membership $membership): void;
}
```

`backend/src/Conversation/Infrastructure/Persistence/DbalMembershipRepository.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\ConversationNotFoundException;
use App\Conversation\Domain\Membership;
use App\Conversation\Domain\MembershipRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipRepository implements MembershipRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function ofMember(ConversationId $conversationId, UserId $userId): Membership
    {
        /** @var array{last_delivered_message_id: string|null, last_read_message_id: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT last_delivered_message_id, last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            [
                'conversation_id' => $conversationId->toString(),
                'user_id' => $userId->toString(),
            ],
        );

        // Pas de ligne = pas membre, et l'appelant traduira en 404. Un 403
        // confirmerait l'existence de la conversation.
        if (false === $row) {
            throw ConversationNotFoundException::withId($conversationId);
        }

        return Membership::reconstitute(
            $conversationId,
            $userId,
            $row['last_delivered_message_id'],
            $row['last_read_message_id'],
        );
    }

    public function save(Membership $membership): void
    {
        // Le `WHERE` porte l'invariant : le curseur ne peut qu'avancer. Zero
        // ligne affectee signifie « deja a jour », par du controle de flux
        // ordinaire — jamais par une exception rattrapee. Meme mecanique que le
        // ON CONFLICT DO NOTHING de l'envoi de message.
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversation_members
                   SET last_delivered_message_id = NULLIF(GREATEST(
                           COALESCE(last_delivered_message_id, ''),
                           COALESCE(:last_delivered_message_id, '')
                       ), ''),
                       last_read_message_id = NULLIF(GREATEST(
                           COALESCE(last_read_message_id, ''),
                           COALESCE(:last_read_message_id, '')
                       ), '')
                 WHERE conversation_id = :conversation_id
                   AND user_id = :user_id
                   AND (
                        COALESCE(:last_delivered_message_id, '') > COALESCE(last_delivered_message_id, '')
                     OR COALESCE(:last_read_message_id, '')      > COALESCE(last_read_message_id, '')
                   )
                SQL,
            [
                'conversation_id' => $membership->conversationId()->toString(),
                'user_id' => $membership->userId()->toString(),
                'last_delivered_message_id' => $membership->lastDeliveredMessageId(),
                'last_read_message_id' => $membership->lastReadMessageId(),
            ],
        );

        // Le collecteur n'est alimente QUE si l'UPDATE a mordu.
        //
        // C'est la seule inflexion par rapport aux autres repositories, qui
        // versent inconditionnellement. Elle est necessaire : entre le SELECT et
        // l'UPDATE, une requete concurrente du meme utilisateur — deux onglets,
        // c'est le cas courant — a pu pousser le curseur plus loin. L'entite
        // aurait alors enregistre un evenement que l'UPDATE n'applique pas, et
        // on publierait un accuse qui recule.
        if (0 === $affected) {
            return;
        }

        $this->collector->collect(...$membership->releaseEvents());
    }
}
```

> **Décomposition de ce `SET`**, parce qu'il concentre l'invariant de la tranche :
>
> - `COALESCE(…, '')` — la chaîne vide précède tout ULID en tri lexicographique, donc un curseur
>   `NULL` perd toujours, et un paramètre nul ne peut jamais écraser une valeur existante.
> - `GREATEST(…)` — le curseur ne peut que monter, colonne par colonne. C'est ce qui permet d'écrire
>   **un seul** `UPDATE` pour les deux curseurs : le `WHERE` autorise la mise à jour dès qu'un des
>   deux avance, et le `GREATEST` garantit que l'autre ne bouge pas pour autant.
> - `NULLIF(…, '')` — **indispensable**, et facile à oublier. Sans lui, quand un curseur est `NULL`
>   des deux côtés, le `GREATEST` de deux `''` écrirait une **chaîne vide** dans la colonne au lieu
>   de la laisser à `NULL`. La distinction compte : `NULL` signifie « n'a jamais rien reçu », et le
>   `COALESCE(w.watermark, '')` du compteur de non-lus (tâche 7) s'appuie dessus.

- [ ] **Étape 10 : écrire la commande, le handler, le payload et le contrôleur**

`backend/src/Conversation/Application/Command/AdvanceReceiptsCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class AdvanceReceiptsCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public ?string $deliveredUpTo,
        public ?string $readUpTo,
    ) {
    }
}
```

`backend/src/Conversation/Application/Command/AdvanceReceiptsCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\MembershipRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;

final readonly class AdvanceReceiptsCommandHandler implements CommandHandlerInterface
{
    public function __construct(private MembershipRepositoryInterface $memberships)
    {
    }

    public function __invoke(AdvanceReceiptsCommand $command): void
    {
        $membership = $this->memberships->ofMember($command->conversationId, $command->userId);

        $membership->advanceDeliveredTo($command->deliveredUpTo);
        $membership->advanceReadTo($command->readUpTo);

        $this->memberships->save($membership);
    }
}
```

`backend/src/Conversation/Infrastructure/Http/Payload/AdvanceReceiptsPayload.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http\Payload;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les deux curseurs sont optionnels : le client n'envoie que celui qui a bouge.
 * Un `null` n'est donc pas une demande de recul, c'est « ce curseur ne change
 * pas » — le domaine l'interprete ainsi.
 */
final readonly class AdvanceReceiptsPayload
{
    public function __construct(
        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Cet identifiant n\'est pas un ULID valide.',
        )]
        public ?string $deliveredUpTo = null,

        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Cet identifiant n\'est pas un ULID valide.',
        )]
        public ?string $readUpTo = null,
    ) {
    }
}
```

`backend/src/Conversation/Infrastructure/Http/AdvanceReceiptsController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\AdvanceReceiptsCommand;
use App\Conversation\Infrastructure\Http\Payload\AdvanceReceiptsPayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * 204 : l'appelant apprendra l'effet par le flux temps reel, comme tout le
 * monde. Renvoyer le watermark resultant creerait un second chemin
 * d'information a garder coherent avec le premier.
 *
 * L'appartenance est verifiee par le repository, qui leve
 * ConversationNotFoundException — donc 404, jamais 403.
 */
final readonly class AdvanceReceiptsController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route('/api/conversations/{conversationId}/receipts', name: 'conversation_receipts_advance', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        #[MapRequestPayload] AdvanceReceiptsPayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $this->commands->dispatch(new AdvanceReceiptsCommand(
            $conversationId,
            $securityUser->userId(),
            $payload->deliveredUpTo,
            $payload->readUpTo,
        ));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Étape 11 : câbler le port**

Dans `backend/config/services.yaml`, à côté des autres ports de `Conversation` :

```yaml
    App\Conversation\Domain\MembershipRepositoryInterface: '@App\Conversation\Infrastructure\Persistence\DbalMembershipRepository'
```

- [ ] **Étape 12 : lancer les tests**

```bash
make functional-test ARGS="--filter=AdvanceReceiptsTest"
make test
```

Attendu : 4 tests verts, aucune régression.

- [ ] **Étape 13 : portes de qualité**

```bash
make static-code-analysis && make check-cs && make deptrac
```

- [ ] **Étape 14 : commit**

```bash
git add backend/migrations backend/src backend/tests backend/config/services.yaml
git commit -m "feat(conversation): persister les watermarks distribue et lu"
```

---

## Tâche 6 — Diffusion des accusés et coches

**Fichiers :**
- Créer : `backend/src/Realtime/Application/EventListener/PublishReceiptUpdatedListener.php`
- Créer : `frontend/src/store/receiptsReducer.ts` + `.test.ts`
- Créer : `frontend/src/ui/ReceiptTicks.tsx`
- Créer : `frontend/src/hooks/useReadWatermark.ts`
- Modifier : `backend/src/Conversation/Application/Query/ConversationDetailView.php`
- Modifier : `backend/src/Conversation/Infrastructure/Persistence/DbalConversationReader.php`
- Modifier : `frontend/src/api/client.ts`, `api/types.ts`, `hooks/useAppState.ts`,
  `ui/ConversationView.tsx`, `ui/MessageList.tsx`
- Tests : `backend/tests/Functional/Conversation/ReceiptPublicationTest.php`

**Interfaces :**
- Consomme : `ReceiptWatermarkAdvanced` (tâche 5), `EventPublisherInterface::publish()` avec
  `$eventId` optionnel (tâche 4).
- Produit : événement Mercure `receipt.updated`, charge utile
  `{conversation_id, user_id, last_delivered_message_id, last_read_message_id}` ;
  `AppState.receiptsState` ; `api.receipts(conversationId, {deliveredUpTo?, readUpTo?})`.

- [ ] **Étape 1 : écrire le test de publication**

Créer `backend/tests/Functional/Conversation/ReceiptPublicationTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class ReceiptPublicationTest extends DatabaseTestCase
{
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testAdvancingAWatermarkPublishesOnTheConversationTopic(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        $receipts = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'receipt.updated' === $entry['type'],
        ));

        self::assertCount(1, $receipts);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $receipts[0]['topic']);
        self::assertSame(
            [
                'conversation_id' => $conversationId,
                'user_id' => $aliceId,
                'last_delivered_message_id' => null,
                'last_read_message_id' => self::NEWER,
            ],
            $receipts[0]['payload'],
        );
    }

    /** Le pendant exact du test d'idempotence de l'envoi : le rejeu ne republie rien. */
    public function testReplayingTheSameWatermarkPublishesNothing(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);
        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        $receipts = array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'receipt.updated' === $entry['type'],
        );

        self::assertCount(1, $receipts, 'Un watermark deja atteint ne doit rien republier.');
    }

    /** @param array<string, string> $body */
    private function postReceipts(string $conversationId, array $body): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/receipts', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Étape 2 : lancer et vérifier l'échec**

```bash
make functional-test ARGS="--filter=ReceiptPublicationTest"
```

Attendu : ÉCHEC — aucun événement `receipt.updated` publié.

- [ ] **Étape 3 : écrire le listener**

`backend/src/Realtime/Application/EventListener/PublishReceiptUpdatedListener.php` :

```php
<?php

declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;

/**
 * UN SEUL publish, sur le topic de la conversation — pas un par expediteur.
 *
 * Le topic personnel `/users/{id}/receipts` aurait impose de connaitre les
 * expediteurs distincts des messages compris entre l'ancien et le nouveau
 * watermark : une requete dans la table de Message depuis Conversation, ce que
 * l'ADR 0001 interdit, puis un publish par expediteur. Le metier serait repasse
 * en O(N) la ou la tranche 1 avait obtenu O(1).
 *
 * Aucun identifiant SSE : un accuse est autoreparateur — l'etat complet est
 * recharge au GET du detail, et le watermark suivant corrige tout ecart.
 */
final readonly class PublishReceiptUpdatedListener implements DomainEventListenerInterface
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(ReceiptWatermarkAdvanced $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'receipt.updated',
            [
                'conversation_id' => $event->conversationId->toString(),
                'user_id' => $event->userId->toString(),
                // Les DEUX curseurs a chaque fois : le client remplace l'etat du
                // membre au lieu de le fusionner, donc l'ordre d'arrivee des
                // evenements n'a aucune importance.
                'last_delivered_message_id' => $event->lastDeliveredMessageId,
                'last_read_message_id' => $event->lastReadMessageId,
            ],
        );
    }
}
```

- [ ] **Étape 4 : relancer**

```bash
make functional-test ARGS="--filter=ReceiptPublicationTest"
```

Attendu : 2 tests verts.

- [ ] **Étape 5 : exposer les watermarks dans le détail de conversation**

Dans `ConversationDetailView`, le tableau `members` passe de
`list<array{user_id: string, role: string}>` à
`list<array{user_id: string, role: string, last_delivered_message_id: string|null, last_read_message_id: string|null}>`.
Mettre à jour le PHPDoc de la propriété et du constructeur.

Dans `DbalConversationReader::detailFor()`, remplacer la seconde requête et son annotation :

```php
        /** @var list<array{user_id: string, role: string, last_delivered_message_id: string|null, last_read_message_id: string|null}> $members */
        $members = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT user_id, role, last_delivered_message_id, last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id
                ORDER BY joined_at ASC
                SQL,
            ['conversation_id' => $conversationId->toString()],
        );
```

C'est un **ajout** de champs, donc non cassant pour le front existant.

- [ ] **Étape 6 : écrire le test du reducer d'accusés**

Créer `frontend/src/store/receiptsReducer.test.ts` :

```ts
import { describe, expect, it } from 'vitest';
import {
  emptyReceiptsState,
  receiptsReducer,
  selectReadCount,
  selectStatusFor,
} from './receiptsReducer';

const CONVERSATION = 'conv-1';
const OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
const NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

describe('receiptsReducer', () => {
  it('remplace l etat d un membre au lieu de le fusionner', () => {
    let state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    // Un evenement arrive dans le desordre porte l'etat COMPLET : il ecrase.
    state = receiptsReducer(state, {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: OLDER,
      lastReadMessageId: null,
    });

    expect(selectStatusFor(state, CONVERSATION, OLDER, 'alice')).toBe('delivered');
  });

  it('rend « sent » tant que personne n a rien recu', () => {
    expect(selectStatusFor(emptyReceiptsState(), CONVERSATION, NEWER, 'alice')).toBe('sent');
  });

  it('rend « read » des qu un autre membre a lu jusqu au message', () => {
    const state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    expect(selectStatusFor(state, CONVERSATION, NEWER, 'alice')).toBe('read');
  });

  it('ignore son propre watermark dans le calcul du statut', () => {
    const state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'alice',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    // Alice a lu son propre message : cela ne fait pas une coche bleue.
    expect(selectStatusFor(state, CONVERSATION, NEWER, 'alice')).toBe('sent');
  });

  it('compte les lecteurs d un message pour l affichage de groupe', () => {
    let state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    state = receiptsReducer(state, {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'carol',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: OLDER,
    });

    expect(selectReadCount(state, CONVERSATION, NEWER, 'alice')).toBe(1);
    expect(selectReadCount(state, CONVERSATION, OLDER, 'alice')).toBe(2);
  });
});
```

- [ ] **Étape 7 : lancer et vérifier l'échec**

```bash
make front-test
```

Attendu : ÉCHEC — module `./receiptsReducer` introuvable.

- [ ] **Étape 8 : écrire le reducer d'accusés**

`frontend/src/store/receiptsReducer.ts` :

```ts
/**
 * Accusés de réception : un curseur « distribué » et un curseur « lu » par
 * membre et par conversation.
 *
 * L'evenement `receipt.updated` porte TOUJOURS les deux curseurs, donc le
 * reducer REMPLACE l'etat du membre. Fusionner serait non seulement inutile,
 * mais faux : deux evenements arrives dans le desordre laisseraient un curseur
 * en avance sur la realite, et un curseur qui recule cote serveur ne pourrait
 * jamais se refleter ici.
 *
 * Les ULID se comparent lexicographiquement : `watermark >= messageId` signifie
 * « ce membre a atteint ce message ». C'est la propriete qui a justifie le choix
 * de l'ULID, et elle sert ici sans conversion.
 */
export type MemberReceipts = {
  lastDeliveredMessageId: string | null;
  lastReadMessageId: string | null;
};

export type ReceiptsState = {
  /** conversationId -> (userId -> curseurs) */
  byConversation: Record<string, Record<string, MemberReceipts>>;
};

export type ReceiptsAction = {
  type: 'receipt/updated';
  conversationId: string;
  userId: string;
  lastDeliveredMessageId: string | null;
  lastReadMessageId: string | null;
};

/** Statut affiche sur MES messages uniquement. */
export type ReceiptStatus = 'sent' | 'delivered' | 'read';

export function emptyReceiptsState(): ReceiptsState {
  return { byConversation: {} };
}

export function receiptsReducer(state: ReceiptsState, action: ReceiptsAction): ReceiptsState {
  switch (action.type) {
    case 'receipt/updated': {
      const current = state.byConversation[action.conversationId] ?? {};

      return {
        byConversation: {
          ...state.byConversation,
          [action.conversationId]: {
            ...current,
            [action.userId]: {
              lastDeliveredMessageId: action.lastDeliveredMessageId,
              lastReadMessageId: action.lastReadMessageId,
            },
          },
        },
      };
    }
  }
}

/** `null` n'a jamais atteint quoi que ce soit ; sinon comparaison lexicographique. */
function reached(watermark: string | null, messageId: string): boolean {
  return watermark !== null && watermark >= messageId;
}

/** Les curseurs de tous les membres SAUF moi : mon propre watermark ne prouve rien. */
function others(
  state: ReceiptsState,
  conversationId: string,
  meId: string,
): MemberReceipts[] {
  return Object.entries(state.byConversation[conversationId] ?? {})
    .filter(([userId]) => userId !== meId)
    .map(([, receipts]) => receipts);
}

export function selectStatusFor(
  state: ReceiptsState,
  conversationId: string,
  messageId: string,
  meId: string,
): ReceiptStatus {
  const peers = others(state, conversationId, meId);

  // « Lu » des qu'UN destinataire a lu, comme WhatsApp en direct. En groupe, le
  // decompte precis est rendu par selectReadCount.
  if (peers.some((r) => reached(r.lastReadMessageId, messageId))) return 'read';
  if (peers.some((r) => reached(r.lastDeliveredMessageId, messageId))) return 'delivered';

  return 'sent';
}

/** Combien de membres, moi excepte, ont lu jusqu'a ce message. */
export function selectReadCount(
  state: ReceiptsState,
  conversationId: string,
  messageId: string,
  meId: string,
): number {
  return others(state, conversationId, meId).filter((r) =>
    reached(r.lastReadMessageId, messageId),
  ).length;
}
```

- [ ] **Étape 9 : relancer les tests front**

```bash
make front-test
```

Attendu : 5 tests verts.

- [ ] **Étape 10 : ajouter l'appel API**

Dans `frontend/src/api/client.ts` :

```ts
  receipts: (
    conversationId: string,
    watermarks: { deliveredUpTo?: string; readUpTo?: string },
  ) =>
    request<void>(`/api/conversations/${conversationId}/receipts`, {
      method: 'POST',
      body: JSON.stringify({
        delivered_up_to: watermarks.deliveredUpTo,
        read_up_to: watermarks.readUpTo,
      }),
    }),
```

- [ ] **Étape 11 : écrire le hook de watermark « lu »**

`frontend/src/hooks/useReadWatermark.ts` :

```ts
import { useEffect, useRef } from 'react';
import { api } from '../api/client';

/** Rafales de messages regroupees : un seul POST par salve. */
const DEBOUNCE_MS = 500;

/**
 * Avance le curseur « lu » quand la conversation est ouverte ET que l'onglet est
 * visible.
 *
 * La condition de visibilite n'est pas negociable : sans elle, un onglet ouvert
 * et oublie en arriere-plan marquerait tout comme lu pendant des heures.
 * L'accuse deviendrait un mensonge — exactement le defaut que la fonctionnalite
 * est censee eviter.
 *
 * Le dernier curseur envoye est memorise pour ne pas rejouer un watermark deja
 * atteint. Le backend s'en protege deja par son `WHERE`, mais chaque retour de
 * focus produirait sinon une requete HTTP pour rien.
 */
export function useReadWatermark(conversationId: string | null, lastMessageId: string | null): void {
  const sentRef = useRef<Record<string, string>>({});

  useEffect(() => {
    if (conversationId === null || lastMessageId === null) return;

    let timer: ReturnType<typeof setTimeout> | null = null;

    const push = () => {
      if (document.visibilityState !== 'visible') return;
      if ((sentRef.current[conversationId] ?? '') >= lastMessageId) return;

      sentRef.current[conversationId] = lastMessageId;

      void api
        .receipts(conversationId, { deliveredUpTo: lastMessageId, readUpTo: lastMessageId })
        .catch(() => {
          // L'echec est rattrapable : on oublie la marque pour reessayer au
          // prochain declencheur, plutot que de figer le curseur pour la session.
          delete sentRef.current[conversationId];
        });
    };

    const schedule = () => {
      if (timer !== null) clearTimeout(timer);
      timer = setTimeout(push, DEBOUNCE_MS);
    };

    schedule();
    document.addEventListener('visibilitychange', schedule);

    return () => {
      if (timer !== null) clearTimeout(timer);
      document.removeEventListener('visibilitychange', schedule);
    };
  }, [conversationId, lastMessageId]);
}
```

- [ ] **Étape 12 : brancher l'ACK « distribué » au niveau global**

Dans `frontend/src/hooks/useAppState.ts` :

1. Imports :

```ts
import { emptyReceiptsState, receiptsReducer } from '../store/receiptsReducer';
import { useReadWatermark } from './useReadWatermark';
```

2. Ajouter `'receipt.updated'` à `NAMED_EVENTS`.

3. Ajouter le reducer :

```ts
  const [receiptsState, dispatchReceipts] = useReducer(
    receiptsReducer,
    undefined,
    emptyReceiptsState,
  );
```

4. Dans `onEvent`, ajouter une branche :

```ts
        if (event.type === 'receipt.updated') {
          dispatchReceipts({
            type: 'receipt/updated',
            conversationId: readString(event.payload, 'conversation_id'),
            userId: readString(event.payload, 'user_id'),
            lastDeliveredMessageId: readNullableString(event.payload, 'last_delivered_message_id'),
            lastReadMessageId: readNullableString(event.payload, 'last_read_message_id'),
          });

          return;
        }
```

5. Ajouter à côté de `readString` :

```ts
/** Comme `readString`, mais un champ absent ou nul reste `null` — un curseur jamais atteint. */
function readNullableString(payload: Record<string, unknown>, key: string): string | null {
  const value = payload[key];

  return typeof value === 'string' ? value : null;
}
```

6. Dans la branche `message.created`, **après** `dispatch({ type: 'message/received', … })`,
   ajouter l'ACK « distribué » :

```ts
          // L'ACK « distribue » se declenche a la RECEPTION SSE, pour TOUTE
          // conversation — y compris celles qu'on n'a pas ouvertes. C'est
          // pourquoi il vit ici, au niveau du client temps reel global, et non
          // dans ConversationView : la vue ne verrait que le fil affiche, et
          // marquerait donc « distribue » une seule conversation sur N.
          const incomingId = readString(event.payload, 'id');
          const incomingConversationId = readString(event.payload, 'conversation_id');

          if (readString(event.payload, 'sender_id') !== me.id && incomingId !== '') {
            void api
              .receipts(incomingConversationId, { deliveredUpTo: incomingId })
              .catch(() => {
                // Un ACK perdu se rattrape au message suivant : le curseur est
                // monotone, donc un seul ACK reussi rattrape tous les manques.
              });
          }
```

7. Exposer `receiptsState` dans `AppState` et dans l'objet retourné.

8. Appeler le hook de lecture, juste avant le `return` :

```ts
  // Dernier message SERVEUR du fil ouvert : un envoi optimiste n'a pas encore
  // d'id, et un curseur ne peut pas designer un message que le serveur ignore.
  const lastServerMessageId =
    selectedId === null
      ? null
      : (selectThread(messagesState, selectedId)
          .items.filter((item) => item.id !== null)
          .at(-1)?.id ?? null);

  useReadWatermark(selectedId, lastServerMessageId);
```

- [ ] **Étape 13 : afficher les coches**

`frontend/src/ui/ReceiptTicks.tsx` :

```tsx
import type { ReceiptStatus } from '../store/receiptsReducer';

const LABELS: Record<ReceiptStatus, string> = {
  sent: 'Envoyé',
  delivered: 'Distribué',
  read: 'Lu',
};

/**
 * ✓ envoye · ✓✓ distribue · ✓✓ bleu lu. Ne s'affiche que sur SES PROPRES
 * messages : le statut d'un message recu n'a aucun sens pour son destinataire.
 */
export function ReceiptTicks({ status, readCount }: { status: ReceiptStatus; readCount?: number }) {
  const marks = status === 'sent' ? '✓' : '✓✓';
  const color = status === 'read' ? 'text-sky-500' : 'text-slate-400';

  return (
    <span className={`ml-1 text-xs ${color}`} title={LABELS[status]} aria-label={LABELS[status]}>
      {marks}
      {readCount !== undefined && readCount > 0 ? ` ${readCount}` : ''}
    </span>
  );
}
```

Dans `frontend/src/ui/MessageList.tsx` : pour chaque message dont `senderId === me.id` **et**
`id !== null`, rendre

```tsx
<ReceiptTicks
  status={selectStatusFor(receiptsState, conversationId, message.id, me.id)}
  readCount={isGroup ? selectReadCount(receiptsState, conversationId, message.id, me.id) : undefined}
/>
```

Faire descendre `receiptsState`, `conversationId`, `me` et un booléen `isGroup` depuis
`ConversationView`.

- [ ] **Étape 14 : vérifier l'ensemble**

```bash
make front-test && make front-typecheck && make test && make static-code-analysis && make check-cs && make deptrac
```

- [ ] **Étape 15 : commit**

```bash
git add backend/src backend/tests frontend/src
git commit -m "feat(realtime): diffuser les accuses et afficher les coches"
```

---

## Tâche 7 — Compteur de non-lus

**Fichiers :**
- Modifier : `backend/deptrac-contexts.yaml` — **décision à valider avec Nicolas d'abord**
- Créer : `backend/src/Message/Application/Contract/UnreadCounterInterface.php`
- Créer : `backend/src/Message/Infrastructure/Contract/DbalUnreadCounter.php`
- Créer : `backend/src/Conversation/Domain/Port/UnreadCounterPortInterface.php`
- Créer : `backend/src/Conversation/Infrastructure/Contract/UnreadCounterAdapter.php`
- Modifier : `backend/src/Conversation/Application/Query/ConversationView.php`
- Modifier : `backend/src/Conversation/Infrastructure/Persistence/DbalConversationReader.php`
- Modifier : `backend/config/services.yaml`
- Modifier : `frontend/src/api/types.ts`, `frontend/src/ui/ConversationList.tsx`
- Test : `backend/tests/Functional/Conversation/UnreadCountTest.php`

**Interfaces :**
- Produit : `UnreadCounterInterface::countUnread(UserId $reader, array $watermarkByConversation): array<string, int>` ;
  champ `unread_count` (entier) dans chaque entrée de `GET /api/conversations`.

- [ ] **Étape 1 : faire valider la modification deptrac**

**Ne pas modifier `deptrac-contexts.yaml` sans accord** — « la config deptrac se décide à deux ».

Modification demandée : une couche `MessageContract` et son ajout à l'allowlist de `Conversation`.

```yaml
        # Surface publiee de Message, symetrique de celle de Conversation.
        - name: MessageContract
          collectors: [{ type: directory, value: 'src/Message/Application/Contract/.*' }]

        - name: Message
          collectors:
              - type: bool
                must:
                    - { type: directory, value: 'src/Message/.*' }
                must_not:
                    - { type: directory, value: 'src/Message/Application/Contract/.*' }
```

et dans le `ruleset` :

```yaml
        MessageContract: [Shared]
        Conversation: [Shared, ConversationContract, MessageContract, Vendor]
        Message: [Shared, ConversationContract, MessageContract, Vendor]
```

Argument à présenter : les deux contextes se consultent désormais mutuellement — `Message` demande
à `Conversation` si l'expéditeur a accès au fil, `Conversation` demande à `Message` combien de
messages sont non lus. **Ce n'est pas un cycle** : les couches `*Contract` ne dépendent que de
`Shared`, jamais de leur propre contexte. Le graphe reste acyclique, et chaque couplage vise une
surface publiée.

- [ ] **Étape 2 : écrire le test fonctionnel**

Créer `backend/tests/Functional/Conversation/UnreadCountTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;

final class UnreadCountTest extends DatabaseTestCase
{
    public function testMyOwnMessagesAreNeverCountedAsUnread(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC1', 'coucou');

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    public function testAMessageFromSomeoneElseIsUnreadUntilTheWatermarkPasses(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('bob');
        $messageId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC2', 'salut');

        $this->login('alice');
        self::assertSame(1, $this->unreadCountFor($conversationId));

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/receipts', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['read_up_to' => $messageId], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    /** Une conversation sans aucun message doit rendre 0, pas disparaitre de la liste. */
    public function testAnEmptyConversationReportsZero(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    private function unreadCountFor(string $conversationId): int
    {
        $this->client->request('GET', '/api/conversations');
        self::assertResponseIsSuccessful();

        /** @var list<array{id: string, unread_count: int}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                return $conversation['unread_count'];
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    private function send(string $conversationId, string $clientMessageId, string $content): string
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'content' => $content],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
```

- [ ] **Étape 3 : lancer et vérifier l'échec**

```bash
make functional-test ARGS="--filter=UnreadCountTest"
```

Attendu : ÉCHEC — la clé `unread_count` est absente de la réponse.

- [ ] **Étape 4 : écrire le contrat publié**

`backend/src/Message/Application/Contract/UnreadCounterInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Application\Contract;

use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Message : « combien de messages cette personne n'a-t-elle
 * pas lus ». Conversation possede le watermark, Message possede la table des
 * messages ; ni l'un ni l'autre ne lit la table de l'autre (ADR 0001).
 *
 * Batchee par conception. L'ecran d'accueil affiche N conversations ; un
 * contrat qui repondrait pour une seule produirait N requetes. La signature rend
 * la version lente impossible a ecrire.
 *
 * Modifier cette signature est un changement cassant.
 */
interface UnreadCounterInterface
{
    /**
     * @param  array<string, string|null> $watermarkByConversation conversationId => last_read_message_id
     * @return array<string, int>                                  conversationId => nombre de non-lus
     */
    public function countUnread(UserId $reader, array $watermarkByConversation): array;
}
```

- [ ] **Étape 5 : écrire l'implémentation**

`backend/src/Message/Infrastructure/Contract/DbalUnreadCounter.php` :

```php
<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Contract;

use App\Message\Application\Contract\UnreadCounterInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalUnreadCounter implements UnreadCounterInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function countUnread(UserId $reader, array $watermarkByConversation): array
    {
        if ([] === $watermarkByConversation) {
            return [];
        }

        $pairs = [];
        foreach ($watermarkByConversation as $conversationId => $watermark) {
            $pairs[] = ['conversation_id' => $conversationId, 'watermark' => $watermark];
        }

        /** @var list<array{conversation_id: string, unread: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            // `jsonb_to_recordset` transporte les paires (conversation, watermark)
            // en UN SEUL parametre lie. `IN (...)` ne conviendrait pas : chaque
            // conversation a SON propre watermark, donc il faut des paires, pas
            // une liste. Et ArrayParameterType ne produit pas un tableau
            // PostgreSQL — il developpe en (?, ?, ?), ce qui rendrait
            // `:ids::text[]` invalide.
            //
            // COALESCE(w.watermark, '') : la chaine vide precede tout ULID, donc
            // un membre qui n'a jamais rien lu a tous ses messages non lus, sans
            // branche conditionnelle.
            //
            // LEFT JOIN et non JOIN : une conversation sans message non lu doit
            // rendre 0, pas disparaitre du resultat.
            <<<'SQL'
                SELECT w.conversation_id, COUNT(m.id) AS unread
                  FROM jsonb_to_recordset(:pairs::jsonb) AS w(conversation_id text, watermark text)
                  LEFT JOIN messages m
                         ON m.conversation_id = w.conversation_id
                        AND m.id > COALESCE(w.watermark, '')
                        AND m.sender_id <> :reader_id
                 GROUP BY w.conversation_id
                SQL,
            [
                'pairs' => json_encode($pairs, \JSON_THROW_ON_ERROR),
                'reader_id' => $reader->toString(),
            ],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['conversation_id']] = $row['unread'];
        }

        return $counts;
    }
}
```

- [ ] **Étape 6 : écrire le port et l'adaptateur côté consommateur**

`backend/src/Conversation/Domain/Port/UnreadCounterPortInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Domain\Port;

use App\Shared\Domain\Identifier\UserId;

/**
 * Le BESOIN de Conversation, exprime dans son propre langage. L'adaptateur qui
 * le realise delegue au contrat publie de Message.
 *
 * Ce port existe pour que le contexte n'ait pas a nommer directement un autre
 * contexte dans sa couche Application : le seul endroit ou Message apparait est
 * l'adaptateur, en Infrastructure.
 */
interface UnreadCounterPortInterface
{
    /**
     * @param  array<string, string|null> $watermarkByConversation
     * @return array<string, int>
     */
    public function countUnread(UserId $reader, array $watermarkByConversation): array;
}
```

`backend/src/Conversation/Infrastructure/Contract/UnreadCounterAdapter.php` :

```php
<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Domain\Port\UnreadCounterPortInterface;
use App\Message\Application\Contract\UnreadCounterInterface;
use App\Shared\Domain\Identifier\UserId;

/** Le SEUL endroit de Conversation qui nomme le contexte Message. */
final readonly class UnreadCounterAdapter implements UnreadCounterPortInterface
{
    public function __construct(private UnreadCounterInterface $counter)
    {
    }

    public function countUnread(UserId $reader, array $watermarkByConversation): array
    {
        return $this->counter->countUnread($reader, $watermarkByConversation);
    }
}
```

- [ ] **Étape 7 : ajouter le champ au DTO de lecture**

Dans `ConversationView`, ajouter un dernier paramètre au constructeur :

```php
        public int $unreadCount = 0,
```

- [ ] **Étape 8 : brancher dans le reader**

Dans `DbalConversationReader` :

1. Injecter le port :

```php
    public function __construct(
        private Connection $connection,
        private UnreadCounterPortInterface $unread,
    ) {
    }
```

2. Dans `forMember()`, ajouter `cm.last_read_message_id` au `SELECT` et à l'annotation de type,
   puis remplacer le `array_map` final :

```php
        $watermarks = [];
        foreach ($rows as $row) {
            $watermarks[$row['id']] = $row['last_read_message_id'];
        }

        // UNE requete pour toutes les conversations : le contrat est batche
        // precisement pour que l'ecran d'accueil ne produise pas N requetes.
        $unreadCounts = $this->unread->countUnread($userId, $watermarks);

        return array_map(
            static fn(array $row): ConversationView => new ConversationView(
                $row['id'],
                $row['type'],
                $row['title'],
                DatabaseTimestamp::toAtom($row['last_message_at']),
                $row['last_message_preview'],
                $row['last_message_sender_id'],
                $unreadCounts[$row['id']] ?? 0,
            ),
            $rows,
        );
```

- [ ] **Étape 9 : câbler les services**

Dans `backend/config/services.yaml` :

```yaml
    App\Message\Application\Contract\UnreadCounterInterface: '@App\Message\Infrastructure\Contract\DbalUnreadCounter'
    App\Conversation\Domain\Port\UnreadCounterPortInterface: '@App\Conversation\Infrastructure\Contract\UnreadCounterAdapter'
```

- [ ] **Étape 10 : lancer les tests et deptrac**

```bash
make functional-test ARGS="--filter=UnreadCountTest"
make test && make static-code-analysis && make check-cs && make deptrac
```

Attendu : 3 tests verts, zéro violation deptrac.

- [ ] **Étape 11 : afficher le badge**

Dans `frontend/src/api/types.ts`, ajouter à `ConversationSummary` :

```ts
  unread_count: number;
```

Dans `frontend/src/ui/ConversationList.tsx`, à droite du titre de chaque conversation :

```tsx
{conversation.unread_count > 0 && (
  <span
    className="ml-2 rounded-full bg-sky-600 px-2 py-0.5 text-xs font-medium text-white"
    aria-label={`${conversation.unread_count} messages non lus`}
  >
    {conversation.unread_count > 99 ? '99+' : conversation.unread_count}
  </span>
)}
```

- [ ] **Étape 12 : vérifier le front**

```bash
make front-test && make front-typecheck
```

- [ ] **Étape 13 : commit**

```bash
git add backend/src backend/tests backend/config/services.yaml backend/deptrac-contexts.yaml frontend/src
git commit -m "feat(message): publier un compteur de non-lus par contrat batche"
```

---

## Tâche 8 — README

**Fichiers :**
- Modifier : `README.md`

Un seul passage final, et non des retouches disséminées : une documentation modifiée sept fois de
suite se contredit en cours de route.

- [ ] **Étape 1 : lire le README en entier**

```bash
cat README.md
```

- [ ] **Étape 2 : mettre à jour les six sections**

| Section | Modification |
|---|---|
| *What this project demonstrates* | Ajouter l'opposition **état durable / état éphémère** : les accusés sont persistés en watermarks, la présence et la frappe ne touchent jamais la base. C'est la thèse de la tranche et ce qui se défend le mieux en entretien |
| *Architecture* | Le 6e conteneur `redis` dans la topologie, avec la mention qu'il ne porte **aucune** donnée durable et n'a délibérément aucun volume |
| *Requirements* | `ext-redis` |
| *Everyday commands* | Vérifier si une cible a été ajoutée au `Makefile` ; sinon ne rien changer |
| *Roadmap* | T2 passe de « à venir » à « livrée » ; T3 (édition/suppression) devient la suivante |
| *Documentation* | Lien vers `docs/superpowers/specs/2026-07-25-instant-messaging-tranche-2-design.md` |

- [ ] **Étape 3 : vérifier la cohérence des commandes citées**

Toute commande écrite dans le README doit exister dans le `Makefile` :

```bash
grep -oE 'make [a-z-]+' README.md | sort -u
grep -E '^[a-zA-Z0-9_.-]+:' Makefile
```

Attendu : chaque `make <cible>` du README figure dans le `Makefile`.

- [ ] **Étape 4 : commit**

```bash
git add README.md
git commit -m "docs(readme): decrire les statuts et la presence de la tranche 2"
```

---

## Vérification finale de la tranche

- [ ] **Toutes les portes de qualité**

```bash
make static-code-analysis && make check-cs && make deptrac && make test && make front-test && make front-typecheck
```

- [ ] **Critères d'acceptation, manuellement, dans deux navigateurs**

Reprendre la liste de la spec T2, section « Critères d'acceptation » :

1. Un message envoyé par l'un passe ✓ puis ✓✓ chez l'autre sans rechargement.
2. La conversation ouverte dans un onglet **caché** ne marque rien comme lu ; revenir sur l'onglet
   avance le curseur.
3. Dans un groupe de trois, le décompte « lu par N » progresse à mesure que chacun ouvre le fil.
4. Un client qui rejoue le même watermark ne provoque **aucune** publication (vérifiable dans les
   logs `make logs SERVICE=backend` : pas de ligne `Evenement receipt.updated publie`).
5. Fermer un onglet fait disparaître la pastille en moins de 30 s.
6. « Alice écrit… » disparaît seul après 5 s, et immédiatement à l'envoi.
7. Le badge de non-lus ne compte jamais ses propres messages.
8. La migration ne contient **aucune** colonne de présence ni de frappe.

- [ ] **Fusion**

```bash
git checkout main
git merge --no-ff feat/tranche-2-statuts-et-presence
```
