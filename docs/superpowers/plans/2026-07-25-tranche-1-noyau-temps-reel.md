# Tranche 1 — Noyau temps réel + conversations : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Livrer une messagerie où deux utilisateurs authentifiés échangent des messages en temps réel dans des conversations 1-1 et de groupe, avec envoi idempotent et historique paginé.

**Architecture:** Backend Symfony en hexagone par contexte borné (`Identity`, `Conversation`, `Message`, `Realtime`, `Shared`), CQS sur deux bus Messenger, persistance en Doctrine DBAL avec SQL littéral. Le hub Mercure est un service séparé ; le backend publie une fois par message, le hub fait le fan-out. Frontend React dont toute la logique testable vit hors de React.

**Tech Stack:** PHP 8.4 / Symfony 7 (FrankenPHP, mode classique) · PostgreSQL 17 · Mercure · Caddy · React 19 + TypeScript + Vite + Tailwind · PHPUnit · Vitest

**Spec de référence :** `docs/superpowers/specs/2026-07-25-instant-messaging-tranche-1-design.md`. Les décisions ne sont pas re-justifiées ici, seulement appliquées.

---

## Global Constraints

Ces contraintes s'appliquent à **chaque** tâche, sans rappel.

- **Aucun commit sur `main`.** Une story = une branche (`feat/…`, `chore/…`) = 1 à 3 commits. Chaque story laisse le dépôt vert.
- **Ni PHP ni Node sur l'hôte.** Toute commande passe par un conteneur. Les commandes de ce plan sont écrites en `docker compose` : elles fonctionnent quel que soit le contenu du `Makefile`. Si une cible `make` équivalente existe, la préférer — **lire le `Makefile` avant**, ne jamais inventer de cible.
- **`Domain/` a zéro dépendance externe.** Aucun `use Symfony\…`, `Doctrine\…`, `Psr\…`. Aucune exception.
- **Règle de dépendance :** `Infrastructure` → `Application` → `Domain`. Jamais l'inverse.
- **Règle inter-contextes ([ADR 0001](../../adr/0001-cross-context-communication.md))** : un contexte ne dépend que du **contrat publié** d'un autre — jamais de ses internes, ni de son code, **ni de ses tables**. Lectures : `{Ctx}/Application/Contract/` (possédé par le producteur, pas dans `Shared`). Écritures : **chorégraphie** par événements, jamais d'appel aux use cases d'un autre. Dans `Shared` : identifiants, événements inter-contextes, `SecurityUser`. Charge utile d'un événement partagé : types de `Shared` et scalaires uniquement.
- **Nommage Symfony :** interfaces suffixées `Interface`, classes abstraites préfixées `Abstract`, exceptions suffixées `Exception`, cas d'enum en `UpperCamelCase`, constantes en `SCREAMING_SNAKE_CASE`, routes en `snake_case`.
- **SQL littéral**, pas de `QueryBuilder`. Paramètres liés obligatoires. PostgreSQL assumé (`ON CONFLICT`, `RETURNING`).
- **PHPStan niveau `max`.** Génériques annotés (`@return list<T>`), lignes DBAL typées (`array{…}`) dans les mappers. **Jamais de baseline ni de `@phpstan-ignore`.**
- **Logs :** placeholders `{entre_accolades}`, valeurs dans le second argument. Jamais de `sprintf`, d'interpolation ni de concaténation. **Jamais** de `content` de message, de JWT, de mot de passe ni d'e-mail dans un log.
- **Erreurs API :** Problem Details RFC 7807, `Content-Type: application/problem+json`, toujours.
- **TDD :** le test échoue d'abord. Toujours.

### Conventions de commandes

| Besoin | Commande |
|---|---|
| Tests unitaires backend | `docker compose exec backend vendor/bin/phpunit --testsuite=Unit` |
| Tests fonctionnels backend | `docker compose exec backend vendor/bin/phpunit --testsuite=Functional` |
| Un test précis | `docker compose exec backend vendor/bin/phpunit --filter=testNomDuTest` |
| Analyse statique | `docker compose exec backend vendor/bin/phpstan analyse` |
| Architecture (couches) | `docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered` |
| Architecture (contextes) | `docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered` |
| Style | `docker compose exec backend vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Console Symfony | `docker compose exec backend bin/console <cmd>` |
| Tests front | `docker compose exec frontend npx vitest run` |

---

## Prérequis à la charge de Nicolas

Le plan démarre **après** ces étapes. La tâche 1 vérifie qu'elles sont faites.

1. Squelette Symfony dans `backend/`.
2. Paquets runtime : `symfony/runtime` `symfony/dotenv` `symfony/console` `symfony/security-bundle` `symfony/messenger` `symfony/uid` `symfony/clock` `symfony/http-client` `symfony/mercure-bundle` `symfony/monolog-bundle` `doctrine/doctrine-bundle` `doctrine/dbal` `doctrine/doctrine-migrations-bundle`
3. Paquets dev : `phpunit/phpunit` `symfony/phpunit-bridge` `symfony/browser-kit` `symfony/css-selector` `phpstan/phpstan` `phpstan/phpstan-symfony` `friendsofphp/php-cs-fixer` `qossmic/deptrac` `symfony/web-profiler-bundle` `symfony/debug-bundle`
4. **Supprimer la section `orm:`** de `config/packages/doctrine.yaml` — ne garder que `dbal:`. `doctrine/orm` ne doit pas être installé.
5. `phpstan.dist.neon` au niveau `max`, `.php-cs-fixer.dist.php`.
6. La partie du `Makefile` qu'il souhaite poser.

---

## File Structure

### Racine

| Fichier | Responsabilité |
|---|---|
| `docker-compose.yml` | 5 services, réseau, healthchecks |
| `.env.example` | gabarit des variables, commité |
| `infra/caddy/Caddyfile` | routage de l'origine unique |
| `backend/Dockerfile` | image FrankenPHP + extensions |
| `frontend/Dockerfile` | image Node pour Vite |
| `.github/workflows/ci.yml` | CI par chemin |

### `backend/src/Shared/`

| Fichier | Responsabilité |
|---|---|
| `Domain/Identifier/AbstractUlidIdentifier.php` | base des VO d'identifiant : validation, égalité |
| `Domain/Identifier/InvalidIdentifierException.php` | format d'ULID invalide |
| `Domain/IdGeneratorInterface.php` | port de génération d'identifiants |
| `Domain/Event/DomainEventInterface.php` | marqueur d'événement de domaine |
| `Domain/Event/RecordsEventsTrait.php` | enregistrement/libération sur un agrégat |
| `Domain/Event/MessageWasSent.php` | événement partagé (émis par `Message`, écouté par `Realtime`) |
| `Domain/Event/MembershipChanged.php` | événement partagé (émis par `Conversation`, écouté par `Realtime`) |
| `Infrastructure/Security/SecurityUser.php` | adaptateur `UserInterface` Symfony, utilisé par tous les contrôleurs |
| `Domain/Exception/InvalidInputExceptionInterface.php` | marqueur → 422 |
| `Domain/Exception/NotFoundExceptionInterface.php` | marqueur → 404 |
| `Application/Event/DomainEventCollectorInterface.php` | collecte inter-agrégats dans une transaction |
| `Infrastructure/Id/UlidGenerator.php` | implémentation via `symfony/uid` |
| `Infrastructure/Event/InMemoryDomainEventCollector.php` | implémentation du collecteur |
| `Infrastructure/Bus/TransactionalMiddleware.php` | transaction + dispatch après commit |
| `Infrastructure/Bus/LoggingMiddleware.php` | log uniforme de chaque message de bus |
| `Infrastructure/Http/ProblemDetailsListener.php` | exceptions → RFC 7807 |
| `Infrastructure/Http/CorrelationIdListener.php` | génère l'identifiant de corrélation |
| `Infrastructure/Log/CorrelationIdProcessor.php` | l'injecte dans chaque ligne de log |

### `backend/src/Identity/`

| Fichier | Responsabilité |
|---|---|
| `Domain/User.php` | agrégat utilisateur |
| `Domain/UserId.php` | VO d'identifiant |
| `Domain/UserRepositoryInterface.php` | port |
| `Domain/UserNotFoundException.php` | → 404 |
| `Infrastructure/Persistence/DbalUserRepository.php` | SQL |
| `Infrastructure/Persistence/UserMapper.php` | ligne ↔ agrégat |
| `Infrastructure/Security/SecurityUserProvider.php` | chargement par identifiant — reste ici, `Identity` possède la table `users` |
| `Infrastructure/Http/MeController.php` | `GET /api/me` |
| `Infrastructure/Http/ListUsersController.php` | `GET /api/users` |
| `Application/Query/…` | annuaire |

### `backend/src/Conversation/`

| Fichier | Responsabilité |
|---|---|
| `Domain/Conversation.php` | agrégat : membres, type, pointeur |
| `Domain/ConversationId.php` `Domain/ConversationType.php` `Domain/MemberRole.php` `Domain/DirectKey.php` `Domain/Member.php` | VO |
| `Domain/ConversationRepositoryInterface.php` | port |
| `Domain/…Exception.php` | erreurs métier |
| `Application/Command/CreateDirectConversation*.php` `CreateGroupConversation*.php` `AddMembers*.php` `RemoveMember*.php` | use cases d'écriture |
| `Application/Query/ListMyConversations*.php` `GetConversation*.php` | use cases de lecture |
| `Application/MembershipCheckerInterface.php` | source de vérité d'appartenance |
| `Infrastructure/Persistence/…` | SQL, mappers, requêtes de lecture |
| `Infrastructure/Security/ConversationVoter.php` | autorisation HTTP |
| `Infrastructure/Http/…Controller.php` | adaptateurs |

### `backend/src/Message/`

| Fichier | Responsabilité |
|---|---|
| `Domain/Message.php` `MessageContent.php` `ClientMessageId.php` | agrégat et VO locaux |
| `Domain/MessageRepositoryInterface.php` | port |
| `Application/Command/SendMessage*.php` | envoi idempotent |
| `Application/Query/GetMessagePage*.php` `MessageView.php` | historique keyset |
| `Infrastructure/Persistence/…` | `ON CONFLICT`, mapper, requête keyset |
| `Infrastructure/Http/…Controller.php` | adaptateurs |

### `backend/src/Realtime/`

| Fichier | Responsabilité |
|---|---|
| `Domain/Topic.php` | VO de topic, seul constructeur de chaînes de topic |
| `Domain/EventPublisherInterface.php` | port de publication |
| `Application/SubscribeTopicsProviderInterface.php` | topics autorisés d'un utilisateur |
| `Application/EventListener/PublishMessageOnMessageWasSent.php` | domain event → publication |
| `Application/EventListener/PublishMembershipChanged.php` | idem |
| `Infrastructure/Mercure/MercureEventPublisher.php` | implémentation |
| `Infrastructure/Mercure/MercureCookieFactory.php` | JWT + cookie |
| `Infrastructure/Http/RealtimeTokenController.php` | `GET /api/realtime/token` |

### `frontend/src/`

| Fichier | Responsabilité |
|---|---|
| `api/client.ts` `api/types.ts` | client HTTP typé, types partagés |
| `api/problem.ts` | parsing RFC 7807 |
| `store/messagesReducer.ts` | reducer pur : dédup, ordre, pagination |
| `store/conversationsReducer.ts` | liste et tri |
| `store/createStore.ts` | store observable, sans React |
| `realtime/RealtimeClient.ts` | propriétaire unique de l'`EventSource` |
| `hooks/useStore.ts` `hooks/useRealtime.ts` | liaison React |
| `ui/LoginScreen.tsx` `ui/ConversationList.tsx` `ui/ConversationView.tsx` `ui/MessageList.tsx` `ui/Composer.tsx` `ui/NewConversationDialog.tsx` `ui/MembersPanel.tsx` | composants |
| `ui/useScrollAnchor.ts` | restauration de scroll |

---

# Phase A — Infrastructure et backend

## Task 1: Infrastructure Docker et origine unique

**Files:**
- Create: `docker-compose.yml`, `.env.example`, `infra/caddy/Caddyfile`, `backend/Dockerfile`, `backend/src/Shared/Infrastructure/Http/PingController.php`
- Create: `backend/tests/Functional/PingTest.php`, `backend/phpunit.dist.xml`

**Interfaces:**
- Consumes: le squelette Symfony bootstrapé par Nicolas.
- Produces: `http://localhost:8080/api/ping` répond `{"status":"ok"}` ; les services `backend`, `postgres`, `mercure`, `caddy` tournent. Toutes les tâches suivantes s'exécutent dans ces conteneurs.

- [ ] **Step 1: Vérifier les prérequis**

```bash
test -f backend/composer.json && echo "squelette OK"
grep -q '"doctrine/orm"' backend/composer.json && echo "ERREUR: doctrine/orm installé" || echo "pas d'ORM OK"
grep -A2 '^doctrine:' backend/config/packages/doctrine.yaml | grep -q 'orm:' && echo "ERREUR: section orm: présente" || echo "config dbal seule OK"
```

Si un contrôle échoue, s'arrêter et le signaler à Nicolas. Ne rien installer soi-même.

- [ ] **Step 2: Écrire le `Dockerfile` backend**

```dockerfile
# backend/Dockerfile
FROM dunglas/frankenphp:php8.4-alpine

RUN install-php-extensions pdo_pgsql intl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=":80"
WORKDIR /app
```

Pas de `worker` : mode classique, un process par requête (spec 1.4).

- [ ] **Step 3: Écrire le `Caddyfile`**

```caddyfile
# infra/caddy/Caddyfile
{
	auto_https off
	admin off
}

:80 {
	handle /api/* {
		reverse_proxy backend:80
	}

	handle /.well-known/mercure* {
		reverse_proxy mercure:80
	}

	handle {
		reverse_proxy frontend:5173
	}
}
```

L'ordre compte : `handle` est exclusif, le dernier bloc est le fourre-tout vers Vite.

- [ ] **Step 4: Écrire `docker-compose.yml`**

```yaml
services:
  caddy:
    image: caddy:2-alpine
    ports:
      - "8080:80"
    volumes:
      - ./infra/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
    depends_on:
      - backend
      - mercure

  backend:
    build: ./backend
    environment:
      APP_ENV: dev
      DATABASE_URL: "postgresql://app:app@postgres:5432/app?serverVersion=17&charset=utf8"
      MERCURE_PUBLISH_URL: "http://mercure/.well-known/mercure"
      MERCURE_PUBLIC_URL: "http://localhost:8080/.well-known/mercure"
      MERCURE_JWT_SECRET: "${MERCURE_JWT_SECRET}"
    volumes:
      - ./backend:/app
    depends_on:
      postgres:
        condition: service_healthy

  frontend:
    build: ./frontend
    volumes:
      - ./frontend:/app
      - /app/node_modules
    command: npm run dev -- --host 0.0.0.0

  mercure:
    image: dunglas/mercure
    environment:
      SERVER_NAME: ":80"
      MERCURE_PUBLISHER_JWT_KEY: "${MERCURE_JWT_SECRET}"
      MERCURE_SUBSCRIBER_JWT_KEY: "${MERCURE_JWT_SECRET}"
      MERCURE_EXTRA_DIRECTIVES: |
        cors_origins http://localhost:8080

  postgres:
    image: postgres:17-alpine
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
      POSTGRES_DB: app
    ports:
      - "5432:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U app"]
      interval: 2s
      timeout: 3s
      retries: 20
```

Le service `frontend` échouera tant que la tâche 13 n'a pas créé son `Dockerfile` : c'est attendu. Le démarrer avec `docker compose up -d caddy backend mercure postgres` jusque-là.

- [ ] **Step 5: Écrire `.env.example`**

```bash
# .env.example — commité. Copier en .env.local et remplacer les secrets.
MERCURE_JWT_SECRET=changez-moi-32-caracteres-minimum-en-local
```

- [ ] **Step 6: Écrire le test fonctionnel (il doit échouer)**

```php
<?php
// backend/tests/Functional/PingTest.php
declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PingTest extends WebTestCase
{
    public function testPingReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
```

- [ ] **Step 7: Configurer PHPUnit**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- backend/phpunit.dist.xml -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <php>
        <ini name="display_errors" value="1"/>
        <server name="APP_ENV" value="test" force="true"/>
        <server name="SHELL_VERBOSITY" value="-1"/>
    </php>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 8: Lever les services et vérifier que le test échoue**

```bash
docker compose up -d --build caddy backend mercure postgres
docker compose exec backend vendor/bin/phpunit --testsuite=Functional
```

Attendu : ÉCHEC, 404 sur `/api/ping` (la route n'existe pas).

- [ ] **Step 9: Écrire le contrôleur**

```php
<?php
// backend/src/Shared/Infrastructure/Http/PingController.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PingController
{
    #[Route('/api/ping', name: 'ping', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 10: Vérifier que le test passe et que l'origine unique fonctionne**

```bash
docker compose exec backend vendor/bin/phpunit --testsuite=Functional
curl -s http://localhost:8080/api/ping
```

Attendu : PASS, puis `{"status":"ok"}` — ce second appel valide le routage Caddy, que le test fonctionnel ne traverse pas.

- [ ] **Step 11: Commit**

```bash
git checkout -b chore/infra-docker
git add docker-compose.yml .env.example infra/ backend/Dockerfile backend/phpunit.dist.xml \
        backend/src/Shared/Infrastructure/Http/PingController.php backend/tests/
git commit -m "chore(infra): 5 services docker et origine unique via Caddy"
```

---

## Task 2: Noyau partagé — identifiants, ports, deptrac

**Files:**
- Create: `backend/src/Shared/Domain/Identifier/AbstractUlidIdentifier.php`, `InvalidIdentifierException.php`, `UserId.php`, `ConversationId.php`, `MessageId.php`
- Create: `backend/src/Shared/Domain/IdGeneratorInterface.php`, `backend/src/Shared/Infrastructure/Id/UlidGenerator.php`
- Create: `backend/src/Shared/Domain/Exception/InvalidInputExceptionInterface.php`, `NotFoundExceptionInterface.php`
- Create: `backend/tests/Unit/Shared/Domain/Identifier/UlidIdentifierTest.php`, `backend/tests/Support/FixedIdGenerator.php`
- Create: `backend/deptrac.yaml`, `backend/deptrac-contexts.yaml`

**Interfaces:**
- Produces:
  - `AbstractUlidIdentifier::fromString(string): static`, `->toString(): string`, `->equals(self): bool`, `__toString()`. Toutes les classes d'identifiant en héritent.
  - `UserId`, `ConversationId`, `MessageId` — identifiants partagés entre contextes, **non interchangeables** entre eux.
  - `IdGeneratorInterface::generate(): string` — renvoie un ULID brut de 26 caractères.
  - `InvalidInputExceptionInterface` / `NotFoundExceptionInterface` : marqueurs consommés par la tâche 4.
  - `FixedIdGenerator::__construct(string ...$ids)` — générateur déterministe pour les tests.

- [ ] **Step 1: Écrire le test de l'identifiant (il doit échouer)**

```php
<?php
// backend/tests/Unit/Shared/Domain/Identifier/UlidIdentifierTest.php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Identifier;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use App\Shared\Domain\Identifier\InvalidIdentifierException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UlidIdentifierTest extends TestCase
{
    private const string VALID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';

    public function testAcceptsAValidUlid(): void
    {
        self::assertSame(self::VALID, TestIdentifier::fromString(self::VALID)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'trop court' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TA'];
        yield 'trop long' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TABC'];
        yield 'lettre I exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAI'];
        yield 'lettre L exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAL'];
        yield 'lettre O exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAO'];
        yield 'lettre U exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAU'];
        yield 'minuscules' => ['01j9zq7x8k3m4n5p6q7r8s9tab'];
        yield 'premier caractere > 7' => ['81J9ZQ7X8K3M4N5P6Q7R8S9TAB'];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsAnInvalidUlid(string $value): void
    {
        $this->expectException(InvalidIdentifierException::class);
        TestIdentifier::fromString($value);
    }

    public function testTwoIdentifiersWithTheSameValueAreEqual(): void
    {
        self::assertTrue(
            TestIdentifier::fromString(self::VALID)->equals(TestIdentifier::fromString(self::VALID)),
        );
    }

    public function testIdentifiersOfDifferentTypesAreNeverEqual(): void
    {
        self::assertFalse(
            TestIdentifier::fromString(self::VALID)->equals(OtherIdentifier::fromString(self::VALID)),
        );
    }

    public function testUlidsSortChronologicallyAsStrings(): void
    {
        $older = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
        $newer = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';

        self::assertLessThan(0, strcmp($older, $newer));
    }
}

final class TestIdentifier extends AbstractUlidIdentifier
{
}

final class OtherIdentifier extends AbstractUlidIdentifier
{
}
```

Le dernier test documente pourquoi le domaine n'a pas besoin de bibliothèque ULID : l'ordre chronologique **est** l'ordre lexicographique.

- [ ] **Step 2: Lancer le test et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --testsuite=Unit
```

Attendu : ÉCHEC, `Class "App\Shared\Domain\Identifier\AbstractUlidIdentifier" not found`.

- [ ] **Step 3: Écrire l'exception puis la classe de base**

```php
<?php
// backend/src/Shared/Domain/Identifier/InvalidIdentifierException.php
declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class InvalidIdentifierException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function forType(string $type, string $value): self
    {
        return new self(sprintf('"%s" n\'est pas un %s valide.', $value, $type));
    }
}
```

> `sprintf` est interdit dans les **logs**, pas dans les messages d'exception : une exception n'est ni agrégée ni groupée.

```php
<?php
// backend/src/Shared/Domain/Exception/InvalidInputExceptionInterface.php
declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Marqueur : la requête est bien formée mais son contenu est invalide. Traduit en 422. */
interface InvalidInputExceptionInterface extends \Throwable
{
}
```

```php
<?php
// backend/src/Shared/Domain/Exception/NotFoundExceptionInterface.php
declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Marqueur : la ressource n'existe pas, ou n'est pas accessible à l'appelant. Traduit en 404. */
interface NotFoundExceptionInterface extends \Throwable
{
}
```

```php
<?php
// backend/src/Shared/Domain/Identifier/AbstractUlidIdentifier.php
declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

/**
 * Un ULID : 26 caracteres en base32 Crockford, triable chronologiquement
 * par simple comparaison de chaines. Le domaine ne genere jamais d'identifiant
 * (cf. IdGeneratorInterface), il se contente de valider ceux qu'il recoit.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractUlidIdentifier implements \Stringable
{
    /** Base32 Crockford : ni I, ni L, ni O, ni U. Premier caractere <= 7 (timestamp sur 48 bits). */
    private const string PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    protected function __construct(private readonly string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidIdentifierException::forType(static::class, $value);
        }
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }
}
```

- [ ] **Step 4: Vérifier que les tests passent**

```bash
docker compose exec backend vendor/bin/phpunit --testsuite=Unit
```

Attendu : PASS (12 tests).

- [ ] **Step 5: Écrire les trois identifiants partagés**

```php
<?php
// backend/src/Shared/Domain/Identifier/UserId.php
declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

final class UserId extends AbstractUlidIdentifier
{
}
```

```php
<?php
// backend/src/Shared/Domain/Identifier/ConversationId.php
declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

final class ConversationId extends AbstractUlidIdentifier
{
}
```

```php
<?php
// backend/src/Shared/Domain/Identifier/MessageId.php
declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

final class MessageId extends AbstractUlidIdentifier
{
}
```

> **Pourquoi dans `Shared` et non dans leur contexte respectif.** La règle du projet est qu'un contexte
> ne référence jamais le `Domain` d'un autre — or `Conversation` a besoin de `UserId`, et `Message` a
> besoin de `ConversationId`. La spec dit que **les contextes communiquent par identifiants** : les
> identifiants sont donc le langage partagé, pas la propriété d'un contexte. Les mettre dans `Shared`
> est la lecture littérale de cette règle ; les dupliquer par contexte serait pire.
>
> Les VO **spécifiques** à un contexte restent chez lui : `MessageContent`, `ClientMessageId`,
> `DirectKey`, `MemberRole`, `ConversationType`, `Topic`.

- [ ] **Step 6: Écrire le port de génération et son implémentation**

```php
<?php
// backend/src/Shared/Domain/IdGeneratorInterface.php
declare(strict_types=1);

namespace App\Shared\Domain;

interface IdGeneratorInterface
{
    /** @return non-empty-string un ULID de 26 caracteres */
    public function generate(): string;
}
```

```php
<?php
// backend/src/Shared/Infrastructure/Id/UlidGenerator.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Id;

use App\Shared\Domain\IdGeneratorInterface;
use Symfony\Component\Uid\Ulid;

final readonly class UlidGenerator implements IdGeneratorInterface
{
    public function generate(): string
    {
        /** @var non-empty-string */
        return Ulid::generate();
    }
}
```

`symfony/uid` n'apparaît que dans cette classe d'`Infrastructure`.

- [ ] **Step 7: Écrire le double de test**

```php
<?php
// backend/tests/Support/FixedIdGenerator.php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\IdGeneratorInterface;

/** Rend les tests deterministes : les identifiants sont fournis dans l'ordre. */
final class FixedIdGenerator implements IdGeneratorInterface
{
    /** @var list<non-empty-string> */
    private array $remaining;

    /** @param non-empty-string ...$ids */
    public function __construct(string ...$ids)
    {
        $this->remaining = array_values($ids);
    }

    public function generate(): string
    {
        $id = array_shift($this->remaining);

        if (null === $id) {
            throw new \LogicException('FixedIdGenerator epuise : fournir plus d\'identifiants.');
        }

        return $id;
    }
}
```

Ajouter `App\Tests\` → `tests/` dans l'autoload-dev de `composer.json` si ce n'est pas déjà fait, puis `docker compose exec backend composer dump-autoload`.

- [ ] **Step 8: Écrire la configuration deptrac**

**Deux fichiers, une dimension chacun** ([ADR 0001](../../adr/0001-cross-context-communication.md)).
Deptrac n'accepte qu'un `ruleset` par fichier ; mélanger les deux dimensions imposerait le produit
cartésien couche × contexte, soit une quinzaine de couches dès la tranche 1.

```yaml
# backend/deptrac.yaml — dimension technique
deptrac:
  paths:
    - ./src
  layers:
    - name: Domain
      collectors: [{ type: directory, value: 'src/[^/]+/Domain/.*' }]
    - name: Application
      collectors: [{ type: directory, value: 'src/[^/]+/Application/.*' }]
    - name: Infrastructure
      collectors: [{ type: directory, value: 'src/[^/]+/Infrastructure/.*' }]
    - name: Vendor
      collectors:
        - type: classNameRegex
          value: '#^(Symfony|Doctrine|Psr|Monolog|Lcobucci)\\.*#'

  ruleset:
    # LA regle du projet : le domaine ne depend de rien. Pas meme symfony/uid.
    Domain: ~
    Application: [Domain, Vendor]
    Infrastructure: [Domain, Application, Vendor]
```

```yaml
# backend/deptrac-contexts.yaml — dimension contexte
deptrac:
  paths:
    - ./src
  layers:
    - name: Shared
      collectors: [{ type: directory, value: src/Shared/.* }]

    # La surface publiee de Conversation est une couche distincte du contexte
    # lui-meme : c'est la seule chose que les autres ont le droit de voir.
    - name: ConversationContract
      collectors: [{ type: directory, value: src/Conversation/Application/Contract/.* }]

    - name: Conversation
      collectors:
        - type: bool
          must:
            - { type: directory, value: src/Conversation/.* }
          must_not:
            - { type: directory, value: src/Conversation/Application/Contract/.* }

    - name: Identity
      collectors: [{ type: directory, value: src/Identity/.* }]
    - name: Message
      collectors: [{ type: directory, value: src/Message/.* }]
    - name: Realtime
      collectors: [{ type: directory, value: src/Realtime/.* }]

  ruleset:
    Shared: ~
    ConversationContract: ~          # un contrat ne depend de rien

    Identity: [Shared]
    Conversation: [Shared]
    Message: [Shared]

    # Allowlist explicite : ajouter un couplage inter-contextes demande de
    # modifier cette ligne. La friction est voulue.
    Realtime: [Shared, ConversationContract]
```

Les deux lignes qui portent tout : **`Domain: ~`** (le domaine ne dépend de rien, `symfony/uid`
compris) et **`Realtime: [Shared, ConversationContract]`** (la seule dépendance inter-contextes du
projet, et elle vise une surface publiée, pas des internes).

> Note pour l'implémentation : les paires `Shared`/`Vendor` sans dépendances déclarées peuvent faire
> remonter des « uncovered dependencies ». Lancer les deux analyses avec `--fail-on-uncovered` dès
> cette tâche, pour que le fichier soit complet **avant** qu'il y ait du code à corriger.

- [ ] **Step 9: Vérifier deptrac, PHPStan et les tests**

```bash
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
docker compose exec backend vendor/bin/phpunit
```

Attendu : zéro violation, zéro erreur, tous les tests verts. Si PHPStan se plaint de `new static`, vérifier que l'annotation `@phpstan-consistent-constructor` est bien présente sur `AbstractUlidIdentifier`.

- [ ] **Step 10: Commit**

```bash
git checkout -b feat/socle-identifiants
git add backend/src/Shared backend/tests backend/deptrac.yaml backend/deptrac-contexts.yaml backend/composer.json
git commit -m "feat(shared): identifiants ULID, port de generation et regles deptrac"
```

---

## Task 3: Corrélation, journalisation et Problem Details

**Files:**
- Create: `backend/src/Shared/Infrastructure/Http/CorrelationIdListener.php`, `ProblemDetailsListener.php`
- Create: `backend/src/Shared/Infrastructure/Log/CorrelationIdProcessor.php`, `backend/src/Shared/Infrastructure/Log/CorrelationIdHolder.php`
- Create: `backend/tests/Functional/ProblemDetailsTest.php`
- Modify: `backend/config/packages/monolog.yaml`

**Interfaces:**
- Consumes: `InvalidInputExceptionInterface`, `NotFoundExceptionInterface` (tâche 2).
- Produces:
  - `CorrelationIdHolder::get(): string` — identifiant de la requête courante, injecté dans les logs et dans chaque réponse d'erreur.
  - Toute exception levée sous `/api` devient un Problem Details. Les tâches suivantes n'ont **plus jamais** à formater une erreur : elles lèvent une exception de domaine.

- [ ] **Step 1: Écrire le test fonctionnel (il doit échouer)**

```php
<?php
// backend/tests/Functional/ProblemDetailsTest.php
declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemDetailsTest extends WebTestCase
{
    public function testUnknownApiRouteReturnsAProblemDocument(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/route-inexistante');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('/problems/resource-not-found', $problem['type']);
        self::assertSame(404, $problem['status']);
        self::assertSame('/api/route-inexistante', $problem['instance']);
        self::assertIsString($problem['title']);
        self::assertIsString($problem['correlation_id']);
        self::assertNotSame('', $problem['correlation_id']);
    }

    public function testInternalErrorNeverLeaksTheExceptionMessage(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/api/_test/boom');

        self::assertResponseStatusCodeSame(500);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('secret-interne', $body);
        self::assertStringContainsString('/problems/internal-error', $body);
    }
}
```

- [ ] **Step 2: Ajouter la route de test qui lève une exception**

```php
<?php
// backend/src/Shared/Infrastructure/Http/BoomController.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\Routing\Attribute\Route;

/** Route presente uniquement en environnement de test, pour verifier le listener d'exception. */
final class BoomController
{
    #[Route('/api/_test/boom', name: 'test_boom', methods: ['GET'], condition: "env('APP_ENV') === 'test'")]
    public function __invoke(): never
    {
        throw new \RuntimeException('secret-interne : ne doit jamais sortir');
    }
}
```

- [ ] **Step 3: Lancer le test et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=ProblemDetailsTest
```

Attendu : ÉCHEC — le `Content-Type` est `text/html` ou `application/json`, pas `application/problem+json`.

- [ ] **Step 4: Écrire le porteur d'identifiant de corrélation et son listener**

```php
<?php
// backend/src/Shared/Infrastructure/Log/CorrelationIdHolder.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

/** Porte l'identifiant de correlation de la requete courante. Service partage, remis a jour a chaque requete. */
final class CorrelationIdHolder
{
    private string $correlationId = 'no-request';

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function get(): string
    {
        return $this->correlationId;
    }
}
```

```php
<?php
// backend/src/Shared/Infrastructure/Http/CorrelationIdListener.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Log\CorrelationIdHolder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 1000)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final readonly class CorrelationIdListener
{
    public function __construct(
        private CorrelationIdHolder $holder,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->holder->set($this->idGenerator->generate());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('X-Correlation-Id', $this->holder->get());
        }
    }
}
```

- [ ] **Step 5: Écrire le processeur Monolog et le configurer**

```php
<?php
// backend/src/Shared/Infrastructure/Log/CorrelationIdProcessor.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final readonly class CorrelationIdProcessor
{
    public function __construct(private CorrelationIdHolder $holder)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = $this->holder->get();

        return $record;
    }
}
```

```yaml
# backend/config/packages/monolog.yaml
monolog:
    channels: ['identity', 'conversation', 'message', 'realtime']

when@dev:
    monolog:
        handlers:
            main:
                type: stream
                path: "php://stderr"
                level: debug
                channels: ["!event"]

when@test:
    monolog:
        handlers:
            main:
                type: stream
                path: "php://stderr"
                level: error

when@prod:
    monolog:
        handlers:
            main:
                type: fingers_crossed
                action_level: error
                handler: nested
                buffer_size: 200
            nested:
                type: stream
                path: "php://stderr"
                level: debug
                formatter: monolog.formatter.json
```

Le `fingers_crossed` garde les lignes `debug` en mémoire et ne les écrit **que** si une `error` survient — c'est ce qui rend « logguer abondamment » soutenable en production.

- [ ] **Step 6: Écrire le listener Problem Details**

```php
<?php
// backend/src/Shared/Infrastructure/Http/ProblemDetailsListener.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;
use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Infrastructure\Log\CorrelationIdHolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Seul endroit du projet ou une exception rencontre HTTP.
 * Les exceptions de Domain n'ont aucune connaissance du protocole (regle de dependance).
 */
#[AsEventListener(event: 'kernel.exception')]
final readonly class ProblemDetailsListener
{
    public function __construct(
        private CorrelationIdHolder $correlationIdHolder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $this->unwrap($event->getThrowable());
        [$status, $type, $title, $detail] = $this->describe($throwable);

        $this->log($status, $type, $throwable);

        $event->setResponse(new JsonResponse(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getPathInfo(),
                'correlation_id' => $this->correlationIdHolder->get(),
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    /** Messenger encapsule les exceptions des handlers : il faut retrouver la cause reelle. */
    private function unwrap(\Throwable $throwable): \Throwable
    {
        while ($throwable instanceof HandlerFailedException) {
            $previous = $throwable->getPrevious();

            if (null === $previous) {
                return $throwable;
            }

            $throwable = $previous;
        }

        return $throwable;
    }

    /** @return array{int, string, string, string} */
    private function describe(\Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                '/problems/authentication-required',
                'Authentification requise',
                'Cette ressource necessite une session valide.',
            ],
            $throwable instanceof AccessDeniedException => [
                Response::HTTP_FORBIDDEN,
                '/problems/access-denied',
                'Acces refuse',
                'Votre role ne permet pas cette operation.',
            ],
            $throwable instanceof NotFoundExceptionInterface,
            $throwable instanceof NotFoundHttpException => [
                Response::HTTP_NOT_FOUND,
                '/problems/resource-not-found',
                'Ressource introuvable',
                'Cette ressource n\'existe pas ou ne vous est pas accessible.',
            ],
            $throwable instanceof InvalidInputExceptionInterface => [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                '/problems/validation-failed',
                'Requete invalide',
                $throwable->getMessage(),
            ],
            $throwable instanceof \JsonException => [
                Response::HTTP_BAD_REQUEST,
                '/problems/malformed-request',
                'Requete malformee',
                'Le corps de la requete n\'est pas un JSON valide.',
            ],
            $throwable instanceof HttpExceptionInterface => [
                $throwable->getStatusCode(),
                '/problems/http-error',
                'Erreur HTTP',
                'La requete n\'a pas pu etre traitee.',
            ],
            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                '/problems/internal-error',
                'Erreur interne',
                'Une erreur interne est survenue.',
            ],
        };
    }

    private function log(int $status, string $type, \Throwable $throwable): void
    {
        $context = ['problem_type' => $type, 'status' => $status, 'exception' => $throwable];

        if ($status >= 500) {
            $this->logger->error('Requete API en erreur interne ({problem_type})', $context);

            return;
        }

        $this->logger->warning('Requete API rejetee ({problem_type})', $context);
    }
}
```

Le `detail` du 422 reprend le message de l'exception de domaine : ces messages sont écrits pour être lus par un humain et ne contiennent jamais de contenu de message ni de secret. Le 500, lui, a un `detail` constant.

- [ ] **Step 7: Vérifier que les tests passent**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

Attendu : tout vert.

- [ ] **Step 8: Commit**

```bash
git checkout -b feat/problem-details
git add backend/src/Shared backend/tests backend/config/packages/monolog.yaml
git commit -m "feat(shared): correlation id, canaux monolog et erreurs RFC 7807"
```

---

## Task 4: Bus CQS, transaction et domain events après commit

**Files:**
- Create: `backend/src/Shared/Domain/Event/DomainEventInterface.php`, `RecordsEventsTrait.php`
- Create: `backend/src/Shared/Application/Event/DomainEventCollectorInterface.php`
- Create: `backend/src/Shared/Infrastructure/Event/InMemoryDomainEventCollector.php`
- Create: `backend/src/Shared/Infrastructure/Bus/TransactionalMiddleware.php`, `LoggingMiddleware.php`
- Create: `backend/tests/Unit/Shared/Infrastructure/Bus/TransactionalMiddlewareTest.php`
- Modify: `backend/config/packages/messenger.yaml`

**Interfaces:**
- Consumes: rien des tâches précédentes.
- Produces:
  - Buses `command.bus` et `query.bus`, injectables par `MessageBusInterface $commandBus` / `$queryBus`.
  - `DomainEventInterface` : marqueur. `RecordsEventsTrait::recordEvent()` / `releaseEvents(): list<DomainEventInterface>`.
  - `DomainEventCollectorInterface::collect(DomainEventInterface ...$events): void`, `release(): list<DomainEventInterface>`, `clear(): void`.
  - **Garantie** : tout événement collecté pendant une commande est dispatché sur `event.bus` **après** le commit, jamais avant, jamais si la transaction échoue.

- [ ] **Step 1: Écrire le test du middleware (il doit échouer)**

```php
<?php
// backend/tests/Unit/Shared/Infrastructure/Bus/TransactionalMiddlewareTest.php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Bus;

use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Infrastructure\Bus\TransactionalMiddleware;
use App\Shared\Infrastructure\Event\InMemoryDomainEventCollector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TransactionalMiddlewareTest extends TestCase
{
    public function testEventsAreDispatchedAfterTheTransactionCommits(): void
    {
        $order = [];
        $event = new class implements DomainEventInterface {};
        $collector = new InMemoryDomainEventCollector();

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static function (callable $callback) use (&$order): mixed {
                $result = $callback();
                $order[] = 'commit';

                return $result;
            },
        );

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$order): Envelope {
                $order[] = 'dispatch';

                return new Envelope($message);
            },
        );

        $middleware = new TransactionalMiddleware($connection, $collector, $eventBus, new NullLogger());

        $middleware->handle(
            new Envelope(new \stdClass()),
            $this->stackThatRuns(static function () use ($collector, $event, &$order): void {
                $collector->collect($event);
                $order[] = 'handler';
            }),
        );

        self::assertSame(['handler', 'commit', 'dispatch'], $order);
    }

    public function testNoEventIsDispatchedWhenTheTransactionFails(): void
    {
        $collector = new InMemoryDomainEventCollector();
        $collector->collect(new class implements DomainEventInterface {});

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willThrowException(new \RuntimeException('rollback'));

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $middleware = new TransactionalMiddleware($connection, $collector, $eventBus, new NullLogger());

        try {
            $middleware->handle(new Envelope(new \stdClass()), $this->stackThatRuns(static fn () => null));
            self::fail('L\'exception aurait du etre relancee.');
        } catch (\RuntimeException) {
            // attendu
        }

        self::assertSame([], $collector->release(), 'Le collecteur doit etre vide apres un echec.');
    }

    private function stackThatRuns(callable $body): StackInterface
    {
        $middleware = new class($body) implements MiddlewareInterface {
            public function __construct(private $body)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->body)();

                return $envelope;
            }
        };

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($middleware);

        return $stack;
    }
}
```

- [ ] **Step 2: Lancer le test et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=TransactionalMiddlewareTest
```

Attendu : ÉCHEC, classes introuvables.

- [ ] **Step 3: Écrire le contrat d'événement et le trait**

```php
<?php
// backend/src/Shared/Domain/Event/DomainEventInterface.php
declare(strict_types=1);

namespace App\Shared\Domain\Event;

/** Marqueur : un fait metier avere, publie apres le commit de la transaction qui l'a produit. */
interface DomainEventInterface
{
}
```

```php
<?php
// backend/src/Shared/Domain/Event/RecordsEventsTrait.php
declare(strict_types=1);

namespace App\Shared\Domain\Event;

trait RecordsEventsTrait
{
    /** @var list<DomainEventInterface> */
    private array $recordedEvents = [];

    protected function recordEvent(DomainEventInterface $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<DomainEventInterface> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
```

- [ ] **Step 4: Écrire le collecteur**

```php
<?php
// backend/src/Shared/Application/Event/DomainEventCollectorInterface.php
declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\DomainEventInterface;

interface DomainEventCollectorInterface
{
    public function collect(DomainEventInterface ...$events): void;

    /** @return list<DomainEventInterface> vide le collecteur en meme temps */
    public function release(): array;

    public function clear(): void;
}
```

```php
<?php
// backend/src/Shared/Infrastructure/Event/InMemoryDomainEventCollector.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Event\DomainEventInterface;

final class InMemoryDomainEventCollector implements DomainEventCollectorInterface
{
    /** @var list<DomainEventInterface> */
    private array $events = [];

    public function collect(DomainEventInterface ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    public function release(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
```

- [ ] **Step 5: Écrire les deux middlewares**

```php
<?php
// backend/src/Shared/Infrastructure/Bus/TransactionalMiddleware.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Event\DomainEventCollectorInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Enveloppe chaque commande dans une transaction, puis publie les domain events
 * une fois le commit acquis. Publier avant le commit permettrait de pousser aux
 * clients un message qu'un rollback ferait ensuite disparaitre.
 */
final readonly class TransactionalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection,
        private DomainEventCollectorInterface $collector,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            /** @var Envelope $result */
            $result = $this->connection->transactional(
                static fn (): Envelope => $stack->next()->handle($envelope, $stack),
            );
        } catch (\Throwable $throwable) {
            $this->collector->clear();

            $this->logger->error('Transaction annulee, aucun evenement publie ({message_class})', [
                'message_class' => $envelope->getMessage()::class,
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        foreach ($this->collector->release() as $event) {
            $this->logger->debug('Publication d\'un domain event apres commit ({event_class})', [
                'event_class' => $event::class,
            ]);

            $this->eventBus->dispatch($event);
        }

        return $result;
    }
}
```

```php
<?php
// backend/src/Shared/Infrastructure/Bus/LoggingMiddleware.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Couverture de log uniforme de tous les messages de bus : aucun handler n'a
 * a repeter ces trois lignes, et aucun ne peut etre oublie.
 */
final readonly class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = $envelope->getMessage()::class;
        $startedAt = $this->clock->now();

        $this->logger->debug('Traitement de {message_class}', ['message_class' => $messageClass]);

        try {
            $result = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $throwable) {
            $this->logger->error('Echec de {message_class} apres {duration_ms} ms', [
                'message_class' => $messageClass,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        $this->logger->info('{message_class} traite en {duration_ms} ms', [
            'message_class' => $messageClass,
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);

        return $result;
    }

    private function elapsedMs(\DateTimeImmutable $startedAt): int
    {
        return (int) round(
            ((float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u')) * 1000,
        );
    }
}
```

- [ ] **Step 6: Configurer les bus**

```yaml
# backend/config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus:
                middleware:
                    - App\Shared\Infrastructure\Bus\LoggingMiddleware
                    - App\Shared\Infrastructure\Bus\TransactionalMiddleware
            query.bus:
                middleware:
                    - App\Shared\Infrastructure\Bus\LoggingMiddleware
            event.bus:
                default_middleware:
                    allow_no_handlers: true
                middleware:
                    - App\Shared\Infrastructure\Bus\LoggingMiddleware
```

L'ordre importe : `LoggingMiddleware` **avant** `TransactionalMiddleware`, pour que la durée mesurée inclue le commit. `allow_no_handlers` sur `event.bus` évite qu'un domain event sans abonné fasse échouer une commande.

Ajouter l'alias d'injection dans `config/services.yaml` :

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        bind:
            $commandBus: '@command.bus'
            $queryBus: '@query.bus'
            $eventBus: '@event.bus'

    App\:
        resource: '../src/'
```

- [ ] **Step 7: Vérifier que les tests passent**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 8: Commit**

```bash
git checkout -b feat/bus-cqs
git add backend/src/Shared backend/tests backend/config
git commit -m "feat(shared): bus CQS, transaction et publication des events apres commit"
```

---

## Task 5: Schéma de base et fixtures

**Files:**
- Create: `backend/migrations/Version20260725000000.php`
- Create: `backend/src/Shared/Infrastructure/Console/LoadFixturesCommand.php`
- Create: `backend/tests/Functional/DatabaseTestCase.php`
- Modify: `backend/config/packages/doctrine_migrations.yaml`

**Interfaces:**
- Consumes: rien.
- Produces:
  - Les 4 tables du modèle de données, avec leurs index et contraintes.
  - `bin/console app:fixtures:load` crée Alice, Bob et Carol (mot de passe `password`), un direct Alice–Bob et un groupe Alice/Bob/Carol.
  - `DatabaseTestCase` : classe de base des tests fonctionnels touchant la base ; chaque test s'exécute dans une transaction annulée en fin de test.

- [ ] **Step 1: Écrire la migration**

```php
<?php
// backend/migrations/Version20260725000000.php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema initial de la tranche 1. SQL explicite : les index, les contraintes
 * et l'unicite partielle de direct_key sont intentionnels, pas deduits d'un diff.
 */
final class Version20260725000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema initial : users, conversations, conversation_members, messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id CHAR(26) PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(100) NOT NULL,
                email VARCHAR(180) NOT NULL UNIQUE,
                password_hash VARCHAR(255) DEFAULT NULL,
                provider VARCHAR(20) NOT NULL DEFAULT 'local',
                external_id VARCHAR(191) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT users_provider_external_id_key UNIQUE (provider, external_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE conversations (
                id CHAR(26) PRIMARY KEY,
                type VARCHAR(10) NOT NULL,
                title VARCHAR(120) DEFAULT NULL,
                created_by CHAR(26) NOT NULL REFERENCES users (id),
                direct_key CHAR(53) DEFAULT NULL UNIQUE,
                last_message_id CHAR(26) DEFAULT NULL,
                last_message_at TIMESTAMPTZ DEFAULT NULL,
                last_message_preview VARCHAR(80) DEFAULT NULL,
                last_message_sender_id CHAR(26) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT conversations_type_check CHECK (type IN ('direct', 'group')),
                CONSTRAINT conversations_direct_needs_key CHECK (
                    (type = 'direct' AND direct_key IS NOT NULL)
                    OR (type = 'group' AND direct_key IS NULL)
                )
            )
            SQL);

        $this->addSql('CREATE INDEX conversations_last_message_at_idx ON conversations (last_message_at DESC NULLS LAST)');

        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_members (
                conversation_id CHAR(26) NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                user_id CHAR(26) NOT NULL REFERENCES users (id),
                role VARCHAR(10) NOT NULL,
                joined_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (conversation_id, user_id),
                CONSTRAINT conversation_members_role_check CHECK (role IN ('member', 'admin'))
            )
            SQL);

        $this->addSql('CREATE INDEX conversation_members_user_idx ON conversation_members (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE messages (
                id CHAR(26) PRIMARY KEY,
                conversation_id CHAR(26) NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                sender_id CHAR(26) NOT NULL REFERENCES users (id),
                content TEXT NOT NULL,
                client_message_id CHAR(26) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT messages_sender_client_id_key UNIQUE (sender_id, client_message_id)
            )
            SQL);

        // Requete dominante : les 50 derniers messages d'une conversation.
        $this->addSql('CREATE INDEX messages_conversation_id_idx ON messages (conversation_id, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE conversation_members');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE users');
    }
}
```

`conversations.last_message_id` n'a **pas** de clé étrangère vers `messages` : elle créerait un cycle avec `messages.conversation_id`. Le pointeur est maintenu dans la même transaction que l'insert (tâche 10).

- [ ] **Step 2: Appliquer la migration et vérifier le schéma**

```bash
docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction
docker compose exec postgres psql -U app -d app -c '\d messages'
```

Attendu : la table existe, avec `messages_conversation_id_idx` et `messages_sender_client_id_key`.

- [ ] **Step 3: Écrire la base des tests de base de données**

```php
<?php
// backend/tests/Functional/DatabaseTestCase.php
declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Chaque test s'execute dans une transaction annulee : la base repart propre sans re-migrer. */
abstract class DatabaseTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }
}
```

> `TransactionalMiddleware` ouvrira une transaction imbriquée pendant les tests. Vérifier que `config/packages/test/doctrine.yaml` active `use_savepoints: true` sur la connexion, sinon le `rollBack` du test entrera en conflit avec le commit du middleware.

- [ ] **Step 4: Écrire la commande de fixtures**

```php
<?php
// backend/src/Shared/Infrastructure/Console/LoadFixturesCommand.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\IdGeneratorInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsCommand(name: 'app:fixtures:load', description: 'Vide la base et charge un jeu de donnees jouable')]
final class LoadFixturesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IdGeneratorInterface $idGenerator,
        private readonly ClockInterface $clock,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $hash = $this->hasherFactory->getPasswordHasher('common')->hash('password');

        $this->connection->executeStatement('TRUNCATE messages, conversation_members, conversations, users CASCADE');

        $users = [];

        foreach ([['alice', 'Alice'], ['bob', 'Bob'], ['carol', 'Carol']] as [$username, $displayName]) {
            $id = $this->idGenerator->generate();
            $users[$username] = $id;

            $this->connection->executeStatement(
                'INSERT INTO users (id, username, display_name, email, password_hash, provider, created_at)
                 VALUES (:id, :username, :display_name, :email, :password_hash, :provider, :created_at)',
                [
                    'id' => $id,
                    'username' => $username,
                    'display_name' => $displayName,
                    'email' => $username.'@example.test',
                    'password_hash' => $hash,
                    'provider' => 'local',
                    'created_at' => $now->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        $direct = $this->idGenerator->generate();
        $pair = [$users['alice'], $users['bob']];
        sort($pair);

        $this->connection->executeStatement(
            'INSERT INTO conversations (id, type, created_by, direct_key, created_at)
             VALUES (:id, :type, :created_by, :direct_key, :created_at)',
            [
                'id' => $direct,
                'type' => 'direct',
                'created_by' => $users['alice'],
                'direct_key' => implode(':', $pair),
                'created_at' => $now->format(\DateTimeInterface::ATOM),
            ],
        );

        $group = $this->idGenerator->generate();

        $this->connection->executeStatement(
            'INSERT INTO conversations (id, type, title, created_by, created_at)
             VALUES (:id, :type, :title, :created_by, :created_at)',
            [
                'id' => $group,
                'type' => 'group',
                'title' => 'Equipe projet',
                'created_by' => $users['alice'],
                'created_at' => $now->format(\DateTimeInterface::ATOM),
            ],
        );

        $memberships = [
            [$direct, $users['alice'], 'admin'],
            [$direct, $users['bob'], 'member'],
            [$group, $users['alice'], 'admin'],
            [$group, $users['bob'], 'member'],
            [$group, $users['carol'], 'member'],
        ];

        foreach ($memberships as [$conversationId, $userId, $role]) {
            $this->connection->executeStatement(
                'INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                 VALUES (:conversation_id, :user_id, :role, :joined_at)',
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'role' => $role,
                    'joined_at' => $now->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        $io->success('Fixtures chargees : alice, bob, carol (mot de passe : password).');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 5: Exécuter les fixtures et vérifier**

```bash
docker compose exec backend bin/console app:fixtures:load
docker compose exec postgres psql -U app -d app -c 'SELECT username FROM users ORDER BY username'
```

Attendu : `alice`, `bob`, `carol`.

- [ ] **Step 6: Commit**

```bash
git checkout -b feat/schema-et-fixtures
git add backend/migrations backend/src/Shared/Infrastructure/Console backend/tests/Functional/DatabaseTestCase.php backend/config
git commit -m "feat(shared): schema initial et commande de fixtures"
```

---

## Task 6: Identité et authentification

**Files:**
- Create: `backend/src/Identity/Domain/User.php`, `UserRepositoryInterface.php`, `UserNotFoundException.php`
- Create: `backend/src/Identity/Infrastructure/Persistence/DbalUserRepository.php`, `UserMapper.php`
- Create: `backend/src/Shared/Infrastructure/Security/SecurityUser.php` (partagé : tous les contextes en dépendent)
- Create: `backend/src/Identity/Infrastructure/Security/SecurityUserProvider.php`, `LoginSuccessHandler.php`, `LoginFailureHandler.php`
- Create: `backend/src/Identity/Infrastructure/Http/MeController.php`, `ListUsersController.php`
- Create: `backend/tests/Functional/Identity/AuthenticationTest.php`
- Modify: `backend/config/packages/security.yaml`

**Interfaces:**
- Consumes: `UserId`, `NotFoundExceptionInterface` (tâche 2), `DatabaseTestCase` (tâche 5).
- Produces:
  - `User` : `id(): UserId`, `username(): string`, `displayName(): string`.
  - `UserRepositoryInterface::ofId(UserId): User` (lève `UserNotFoundException`), `ofUsername(string): ?User`, `all(): list<User>`.
  - `SecurityUser::userId(): UserId` — pont entre le jeton de sécurité Symfony et le domaine. Les contrôleurs suivants récupèrent l'utilisateur courant par `#[CurrentUser] SecurityUser $securityUser`.
  - `POST /api/login`, `POST /api/logout`, `GET /api/me`, `GET /api/users`.

- [ ] **Step 1: Écrire le test fonctionnel (il doit échouer)**

```php
<?php
// backend/tests/Functional/Identity/AuthenticationTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Functional\DatabaseTestCase;

final class AuthenticationTest extends DatabaseTestCase
{
    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testLoginThenMeReturnsTheCurrentUser(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();

        /** @var array{username: string, display_name: string, id: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('alice', $body['username']);
        self::assertSame('Alice', $body['display_name']);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $body['id']);
    }

    public function testLoginWithWrongPasswordIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'alice', 'password' => 'mauvais'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testUsersDirectoryListsEveryoneButNeverLeaksSecrets(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/users');

        self::assertResponseIsSuccessful();

        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('password', $raw);
        self::assertStringNotContainsString('@example.test', $raw);

        /** @var list<array{username: string}> $body */
        $body = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $body);
    }

    protected function login(string $username): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }
}
```

Le test sur l'annuaire vérifie que ni le hash ni l'e-mail ne sortent : l'annuaire sert à choisir un interlocuteur, pas à exposer les comptes.

- [ ] **Step 2: Charger les fixtures dans la base de test et vérifier l'échec**

```bash
docker compose exec -e APP_ENV=test backend bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -e APP_ENV=test backend bin/console app:fixtures:load
docker compose exec backend vendor/bin/phpunit --filter=AuthenticationTest
```

Attendu : ÉCHEC, 404 sur `/api/login`.

- [ ] **Step 3: Écrire le domaine**

`UserId` vient de `Shared\Domain\Identifier` (tâche 2) : il est le langage partagé entre contextes, pas la propriété d'`Identity`.

```php
<?php
// backend/src/Identity/Domain/User.php
declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Identifier\UserId;

final readonly class User
{
    public function __construct(
        private UserId $id,
        private string $username,
        private string $displayName,
    ) {
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }
}
```

L'agrégat ne porte ni e-mail ni hash : la tranche 1 n'a aucun cas d'usage métier qui les manipule. Ils vivent en base et ne sont lus que par l'adaptateur de sécurité.

```php
<?php
// backend/src/Identity/Domain/UserNotFoundException.php
declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\UserId;

final class UserNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(UserId $id): self
    {
        return new self(sprintf('Utilisateur %s introuvable.', $id->toString()));
    }
}
```

```php
<?php
// backend/src/Identity/Domain/UserRepositoryInterface.php
declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Identifier\UserId;

interface UserRepositoryInterface
{
    /** @throws UserNotFoundException */
    public function ofId(UserId $id): User;

    public function ofUsername(string $username): ?User;

    /** @return list<User> */
    public function all(): array;
}
```

- [ ] **Step 4: Écrire le mapper et le repository**

```php
<?php
// backend/src/Identity/Infrastructure/Persistence/UserMapper.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\User;
use App\Shared\Domain\Identifier\UserId;

/**
 * Frontiere unique entre la ligne SQL brute et le domaine.
 * C'est ici que le type large rendu par DBAL devient un type precis (PHPStan max).
 */
final readonly class UserMapper
{
    /** @param array{id: string, username: string, display_name: string} $row */
    public function fromRow(array $row): User
    {
        return new User(
            UserId::fromString($row['id']),
            $row['username'],
            $row['display_name'],
        );
    }
}
```

```php
<?php
// backend/src/Identity/Infrastructure/Persistence/DbalUserRepository.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\User;
use App\Identity\Domain\UserNotFoundException;
use App\Identity\Domain\UserRepositoryInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalUserRepository implements UserRepositoryInterface
{
    private const string COLUMNS = 'id, username, display_name';

    public function __construct(
        private Connection $connection,
        private UserMapper $mapper,
    ) {
    }

    public function ofId(UserId $id): User
    {
        /** @var array{id: string, username: string, display_name: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM users WHERE id = :id',
            ['id' => $id->toString()],
        );

        if (false === $row) {
            throw UserNotFoundException::withId($id);
        }

        return $this->mapper->fromRow($row);
    }

    public function ofUsername(string $username): ?User
    {
        /** @var array{id: string, username: string, display_name: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM users WHERE username = :username',
            ['username' => $username],
        );

        return false === $row ? null : $this->mapper->fromRow($row);
    }

    public function all(): array
    {
        /** @var list<array{id: string, username: string, display_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM users ORDER BY display_name ASC',
        );

        return array_map($this->mapper->fromRow(...), $rows);
    }
}
```

- [ ] **Step 5: Écrire l'adaptateur de sécurité**

```php
<?php
// backend/src/Shared/Infrastructure/Security/SecurityUser.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Identifier\UserId;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adaptateur entre le jeton de securite Symfony et le domaine.
 *
 * Dans Shared parce que TOUS les contextes en ont besoin : chaque controleur
 * doit connaitre l'utilisateur courant (regle inter-contextes). Identity garde
 * en revanche le SecurityUserProvider, qui interroge la table users dont il est
 * proprietaire.
 *
 * Ajouter OAuth plus tard consistera a peupler cet objet depuis un autre
 * authenticator : ni le domaine ni les use cases ne changeront.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private string $id,
        private string $username,
        private ?string $passwordHash,
    ) {
    }

    public function userId(): UserId
    {
        return UserId::fromString($this->id);
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
```

```php
<?php
// backend/src/Identity/Infrastructure/Security/SecurityUserProvider.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        /** @var array{id: string, username: string, password_hash: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, password_hash FROM users WHERE username = :username',
            ['username' => $identifier],
        );

        if (false === $row) {
            throw new UserNotFoundException();
        }

        return new SecurityUser($row['id'], $row['username'], $row['password_hash']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
```

- [ ] **Step 6: Configurer la sécurité**

```yaml
# backend/config/packages/security.yaml
security:
    password_hashers:
        App\Shared\Infrastructure\Security\SecurityUser: 'auto'
        common: 'auto'

    providers:
        app_users:
            id: App\Identity\Infrastructure\Security\SecurityUserProvider

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false

        api:
            pattern: ^/api
            provider: app_users
            stateless: false
            json_login:
                check_path: /api/login
                username_path: username
                password_path: password
                success_handler: App\Identity\Infrastructure\Security\LoginSuccessHandler
                failure_handler: App\Identity\Infrastructure\Security\LoginFailureHandler
            logout:
                path: /api/logout

    access_control:
        - { path: ^/api/ping$, roles: PUBLIC_ACCESS }
        - { path: ^/api/login$, roles: PUBLIC_ACCESS }
        - { path: ^/api/_test/, roles: PUBLIC_ACCESS }
        - { path: ^/api/, roles: ROLE_USER }
```

```php
<?php
// backend/src/Identity/Infrastructure/Security/LoginSuccessHandler.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var SecurityUser $user */
        $user = $token->getUser();

        $this->logger->notice('Connexion de l\'utilisateur {user_id}', [
            'user_id' => $user->userId()->toString(),
        ]);

        return new JsonResponse(['status' => 'ok']);
    }
}
```

```php
<?php
// backend/src/Identity/Infrastructure/Security/LoginFailureHandler.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // On ne loggue jamais l'identifiant tente : ce serait consigner des identifiants en clair.
        $this->logger->warning('Echec d\'authentification');

        return new JsonResponse(
            [
                'type' => '/problems/authentication-required',
                'title' => 'Authentification requise',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Identifiants invalides.',
                'instance' => $request->getPathInfo(),
            ],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
```

- [ ] **Step 7: Écrire les contrôleurs**

```php
<?php
// backend/src/Identity/Infrastructure/Http/MeController.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Domain\UserRepositoryInterface;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class MeController
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    #[Route('/api/me', name: 'me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $user = $this->users->ofId($securityUser->userId());

        return new JsonResponse([
            'id' => $user->id()->toString(),
            'username' => $user->username(),
            'display_name' => $user->displayName(),
        ]);
    }
}
```

```php
<?php
// backend/src/Identity/Infrastructure/Http/ListUsersController.php
declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListUsersController
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    #[Route('/api/users', name: 'users_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(array_map(
            static fn (User $user): array => [
                'id' => $user->id()->toString(),
                'username' => $user->username(),
                'display_name' => $user->displayName(),
            ],
            $this->users->all(),
        ));
    }
}
```

- [ ] **Step 8: Vérifier que les tests passent**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 9: Commit**

```bash
git checkout -b feat/identite-et-authentification
git add backend/src/Identity backend/tests backend/config/packages/security.yaml
git commit -m "feat(identity): authentification locale, /api/me et annuaire"
```

---

## Task 7: Temps réel — topics, publication et cookie JWT

**Files:**
- Create: `backend/src/Realtime/Domain/Topic.php`, `EventPublisherInterface.php`
- Create: `backend/src/Realtime/Infrastructure/Mercure/MercureEventPublisher.php`, `MercureCookieFactory.php`, `SubscribeTopicsProvider.php`
- Create: `backend/src/Conversation/Application/Contract/MemberConversationsFinderInterface.php`, `backend/src/Conversation/Infrastructure/Contract/DbalMemberConversationsFinder.php` (contrat publié, consommé ici)
- Create: `backend/src/Realtime/Infrastructure/Http/RealtimeTokenController.php`
- Create: `backend/tests/Unit/Realtime/Domain/TopicTest.php`, `backend/tests/Support/InMemoryEventPublisher.php`
- Create: `backend/tests/Functional/Realtime/RealtimeTokenTest.php`
- (aucune modification d'`Identity` : voir la note ci-dessous)

**Interfaces:**
- Consumes: `AbstractUlidIdentifier`, `UserId`, `ConversationId` (`Shared/Domain/Identifier`).
- Produces:
  - `Topic::conversation(ConversationId): self`, `Topic::userSystem(UserId): self`, `->toString(): string`. **Seul** constructeur de chaînes de topic du projet.
  - `EventPublisherInterface::publish(Topic $topic, string $eventType, array $payload, string $eventId): void`.
  - `InMemoryEventPublisher::published(): list<array{topic: string, type: string, payload: array<string, mixed>, id: string}>` — espion utilisé par toutes les tâches suivantes.
  - `GET /api/realtime/token` → **200**, `Set-Cookie: mercureAuthorization`, corps `{"hub_url": "...", "topics": ["..."]}`.

> **Le cookie n'est PAS posé à la connexion.** La version initiale du plan faisait appeler
> `MercureCookieFactory` par le `LoginSuccessHandler` d'`Identity` — une dépendance
> `Identity → Realtime` que la règle inter-contextes interdit.
>
> La bonne réponse n'est pas de faire remonter la fabrique dans `Shared`, c'est de supprimer le
> besoin : le front appelle `/api/realtime/token` juste après la connexion, ce que
> `RealtimeClient.start()` fait **déjà** puisqu'il lui faut la liste des topics. `Identity` ignore
> donc totalement l'existence de Mercure, il y a un chemin de moins à tester, et la contrainte
> d'architecture a produit une simplification plutôt qu'un contournement.

> **Pourquoi le corps contient la liste des topics.** Le cookie *autorise*, mais Mercure exige que
> l'abonné *sélectionne* ses topics dans l'URL (`?topic=…`). Le front doit donc les connaître. Les
> renvoyer ici évite qu'il reconstruise les chaînes de topic de son côté — ce qui recréerait
> exactement la duplication que le VO `Topic` supprime côté serveur.

- [ ] **Step 1: Écrire le test du VO Topic (il doit échouer)**

```php
<?php
// backend/tests/Unit/Realtime/Domain/TopicTest.php
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

        self::assertSame('/conversations/'.self::ULID, $topic->toString());
    }

    public function testUserSystemTopic(): void
    {
        $topic = Topic::userSystem(UserId::fromString(self::ULID));

        self::assertSame('/users/'.self::ULID.'/system', $topic->toString());
    }

    public function testTopicsOfDifferentKindsNeverCollide(): void
    {
        self::assertNotSame(
            Topic::conversation(ConversationId::fromString(self::ULID))->toString(),
            Topic::userSystem(UserId::fromString(self::ULID))->toString(),
        );
    }
}
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=TopicTest
```

Attendu : ÉCHEC, `App\Realtime\Domain\Topic` introuvable.

- [ ] **Step 3: Écrire le VO et le port**

```php
<?php
// backend/src/Realtime/Domain/Topic.php
declare(strict_types=1);

namespace App\Realtime\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Seul constructeur de chaines de topic du projet. Une faute de frappe dans une
 * concatenation manuelle serait un bug de securite silencieux : le message
 * partirait sur un topic auquel personne n'est abonne, ou mal cloisonne.
 */
final readonly class Topic implements \Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function conversation(ConversationId $conversationId): self
    {
        return new self('/conversations/'.$conversationId->toString());
    }

    public static function userSystem(UserId $userId): self
    {
        return new self('/users/'.$userId->toString().'/system');
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

```php
<?php
// backend/src/Realtime/Domain/EventPublisherInterface.php
declare(strict_types=1);

namespace App\Realtime\Domain;

interface EventPublisherInterface
{
    /**
     * @param non-empty-string     $eventType type logique de l'evenement, ex. "message.created"
     * @param array<string, mixed> $payload
     * @param non-empty-string     $eventId   identifiant de l'evenement SSE (ULID du message)
     */
    public function publish(Topic $topic, string $eventType, array $payload, string $eventId): void;
}
```

- [ ] **Step 4: Écrire l'espion de test**

```php
<?php
// backend/tests/Support/InMemoryEventPublisher.php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;

/** Permet d'assertionner topic ET charge utile sans lever de hub Mercure en CI. */
final class InMemoryEventPublisher implements EventPublisherInterface
{
    /** @var list<array{topic: string, type: string, payload: array<string, mixed>, id: string}> */
    private array $published = [];

    public function publish(Topic $topic, string $eventType, array $payload, string $eventId): void
    {
        $this->published[] = [
            'topic' => $topic->toString(),
            'type' => $eventType,
            'payload' => $payload,
            'id' => $eventId,
        ];
    }

    /** @return list<array{topic: string, type: string, payload: array<string, mixed>, id: string}> */
    public function published(): array
    {
        return $this->published;
    }
}
```

L'enregistrer comme service en environnement de test :

```yaml
# backend/config/services_test.yaml
services:
    App\Tests\Support\InMemoryEventPublisher:
        public: true

    App\Realtime\Domain\EventPublisherInterface:
        alias: App\Tests\Support\InMemoryEventPublisher
```

- [ ] **Step 5: Écrire l'implémentation Mercure**

```php
<?php
// backend/src/Realtime/Infrastructure/Mercure/MercureEventPublisher.php
declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercureEventPublisher implements EventPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(Topic $topic, string $eventType, array $payload, string $eventId): void
    {
        $data = json_encode(['type' => $eventType, 'payload' => $payload], \JSON_THROW_ON_ERROR);

        try {
            $this->hub->publish(new Update(
                topics: $topic->toString(),
                data: $data,
                private: true,
                id: $eventId,
                type: $eventType,
            ));
        } catch (\Throwable $throwable) {
            // Le hub est injoignable : plus aucun temps reel, l'application est
            // fonctionnellement cassee -> niveau alert (spec 3.11).
            $this->logger->alert('Publication Mercure impossible sur {topic} ({event_type})', [
                'topic' => $topic->toString(),
                'event_type' => $eventType,
                'exception' => $throwable,
            ]);

            throw $throwable;
        }

        $this->logger->info('Evenement {event_type} publie sur {topic}', [
            'event_type' => $eventType,
            'topic' => $topic->toString(),
            'event_id' => $eventId,
        ]);
    }
}
```

`private: true` : seuls les abonnés dont le JWT autorise ce topic reçoivent la mise à jour.

- [ ] **Step 6: Écrire le fournisseur de topics et la fabrique de cookie**

**Trois classes, parce que c'est le seul couplage inter-contextes du projet** et qu'il passe par un
contrat publié ([ADR 0001](../../adr/0001-cross-context-communication.md)) : `Realtime` ne lit **pas**
la table `conversation_members`, qui appartient à `Conversation`.

```php
<?php
// backend/src/Conversation/Application/Contract/MemberConversationsFinderInterface.php
// Surface PUBLIEE de Conversation. Possedee par le producteur, pas par Shared.
declare(strict_types=1);

namespace App\Conversation\Application\Contract;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

interface MemberConversationsFinderInterface
{
    /** @return list<ConversationId> les conversations dont l'utilisateur est membre */
    public function conversationIdsFor(UserId $userId): array;
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Contract/DbalMemberConversationsFinder.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Application\Contract\MemberConversationsFinderInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/** Conversation est le SEUL a lire conversation_members. */
final readonly class DbalMemberConversationsFinder implements MemberConversationsFinderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function conversationIdsFor(UserId $userId): array
    {
        /** @var list<array{conversation_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT conversation_id FROM conversation_members WHERE user_id = :user_id',
            ['user_id' => $userId->toString()],
        );

        return array_map(
            static fn (array $row): ConversationId => ConversationId::fromString($row['conversation_id']),
            $rows,
        );
    }
}
```

```php
<?php
// backend/src/Realtime/Infrastructure/Mercure/SubscribeTopicsProvider.php
declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Conversation\Application\Contract\MemberConversationsFinderInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Identifier\UserId;

/**
 * Liste des topics qu'un utilisateur a le droit d'ecouter.
 *
 * Consomme le contrat publie par Conversation : si la structure de
 * conversation_members change, c'est le contrat qui casse — de facon typee et
 * visible — au lieu d'un SELECT silencieusement faux.
 */
final readonly class SubscribeTopicsProvider
{
    public function __construct(private MemberConversationsFinderInterface $conversations)
    {
    }

    /** @return list<string> */
    public function forUser(UserId $userId): array
    {
        $topics = array_map(
            static fn ($conversationId): string => Topic::conversation($conversationId)->toString(),
            $this->conversations->conversationIdsFor($userId),
        );

        // Toujours present, ne change jamais : c'est par lui qu'on apprend
        // qu'on a ete ajoute a une conversation (spec 5).
        $topics[] = Topic::userSystem($userId)->toString();

        return $topics;
    }
}
```

```php
<?php
// backend/src/Realtime/Infrastructure/Mercure/MercureCookieFactory.php
declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Shared\Domain\Identifier\UserId;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

final readonly class MercureCookieFactory
{
    public function __construct(
        private Authorization $authorization,
        private SubscribeTopicsProvider $topicsProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function forUser(Request $request, UserId $userId): Cookie
    {
        $topics = $this->topicsProvider->forUser($userId);

        // Les topics eux-memes sont des identifiants, jamais du contenu : loggables.
        $this->logger->debug('Emission d\'un JWT Mercure pour {user_id} sur {topic_count} topics', [
            'user_id' => $userId->toString(),
            'topic_count' => count($topics),
        ]);

        return $this->authorization->createCookie($request, $topics);
    }
}
```

Le cookie produit par `Authorization` s'appelle `mercureAuthorization`, est `HttpOnly` et porte le chemin du hub. Le front ne le lit jamais.

Configurer la durée de vie et le secret :

```yaml
# backend/config/packages/mercure.yaml
mercure:
    hubs:
        default:
            url: '%env(MERCURE_PUBLISH_URL)%'
            public_url: '%env(MERCURE_PUBLIC_URL)%'
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
                publish: ['*']
```

- [ ] **Step 7: Écrire le test fonctionnel du token**

```php
<?php
// backend/tests/Functional/Realtime/RealtimeTokenTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\Identity\AuthenticationTest;

final class RealtimeTokenTest extends AuthenticationTest
{
    public function testTokenEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/realtime/token');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginAloneDoesNotSetTheMercureCookie(): void
    {
        $this->login('alice');

        // Identity ne connait pas Mercure : c'est le front qui appelle
        // /api/realtime/token juste apres, pour recuperer aussi les topics.
        self::assertNull($this->client->getCookieJar()->get('mercureAuthorization'));
    }

    public function testTokenEndpointSetsTheCookieAndReturnsTheTopics(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/realtime/token');

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->client->getCookieJar()->get('mercureAuthorization'));

        /** @var array{hub_url: string, topics: list<string>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('/.well-known/mercure', $body['hub_url']);

        $personalTopics = array_filter(
            $body['topics'],
            static fn (string $topic): bool => str_ends_with($topic, '/system'),
        );

        self::assertCount(1, $personalTopics, 'Le topic personnel doit toujours etre present.');
        self::assertGreaterThan(1, count($body['topics']), 'Alice a aussi des conversations.');
    }
}
```

- [ ] **Step 8: Écrire le contrôleur**

```php
<?php
// backend/src/Realtime/Infrastructure/Http/RealtimeTokenController.php
declare(strict_types=1);

namespace App\Realtime\Infrastructure\Http;

use App\Shared\Infrastructure\Security\SecurityUser;
use App\Realtime\Infrastructure\Mercure\MercureCookieFactory;
use App\Realtime\Infrastructure\Mercure\SubscribeTopicsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class RealtimeTokenController
{
    public function __construct(
        private MercureCookieFactory $cookieFactory,
        private SubscribeTopicsProvider $topicsProvider,
        private string $mercurePublicUrl,
    ) {
    }

    #[Route('/api/realtime/token', name: 'realtime_token', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        $userId = $securityUser->userId();

        // Le cookie autorise ; le corps indique quels topics l'abonne doit
        // selectionner dans l'URL du hub. Les deux viennent de la meme source.
        $response = new JsonResponse([
            'hub_url' => $this->mercurePublicUrl,
            'topics' => $this->topicsProvider->forUser($userId),
        ]);

        $response->headers->setCookie($this->cookieFactory->forUser($request, $userId));

        return $response;
    }
}
```

Lier la variable d'environnement dans `config/services.yaml` :

```yaml
services:
    App\Realtime\Infrastructure\Http\RealtimeTokenController:
        arguments:
            $mercurePublicUrl: '%env(MERCURE_PUBLIC_URL)%'
```

`LoginSuccessHandler` (tâche 6) reste **inchangé** : `Identity` n'a aucune raison de connaître Mercure.

- [ ] **Step 9: Vérifier**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 10: Commit**

```bash
git checkout -b feat/realtime-topics-et-token
git add backend/src/Realtime backend/tests backend/config
git commit -m "feat(realtime): VO Topic, publication Mercure et cookie JWT"
```

---

## Task 8: Conversations directes

**Files:**
- Create: `backend/src/Conversation/Domain/Conversation.php`, `ConversationType.php`, `MemberRole.php`, `Member.php`, `DirectKey.php`, `ConversationRepositoryInterface.php`, `ConversationNotFoundException.php`, `SelfConversationException.php`
- Create: `backend/src/Conversation/Application/Command/CreateDirectConversation.php`, `CreateDirectConversationHandler.php`
- Create: `backend/src/Conversation/Application/Query/ListMyConversations.php`, `ListMyConversationsHandler.php`, `ConversationView.php`
- Create: `backend/src/Conversation/Infrastructure/Persistence/DbalConversationRepository.php`, `ConversationMapper.php`, `DirectKeyHydrator.php`, `SqlMyConversationsQuery.php`
- Create (tâche 7, rappel) : `Conversation/Application/Contract/MemberConversationsFinderInterface.php` et son implémentation — première tranche du contexte, réduite à sa surface publiée
- Create: `backend/src/Conversation/Infrastructure/Http/UnsupportedConversationPayloadException.php`
- Create: `backend/src/Conversation/Infrastructure/Http/CreateConversationController.php`, `ListConversationsController.php`
- Create: `backend/src/Shared/Infrastructure/Bus/CommandDispatcher.php`, `QueryDispatcher.php`
- Create: `backend/tests/Unit/Conversation/Domain/DirectKeyTest.php`, `backend/tests/Functional/Conversation/CreateDirectConversationTest.php`

**Interfaces:**
- Consumes: `UserId`, `ConversationId` (`Shared/Domain/Identifier`), `RecordsEventsTrait`, `DomainEventCollectorInterface`, `IdGeneratorInterface`, `ClockInterface`.
- Produces:
  - `DirectKey::forPair(UserId, UserId): self` — commutatif par construction.
  - `Conversation::direct(ConversationId, UserId $initiator, UserId $peer, \DateTimeImmutable): self`, `->id()`, `->hasMember(UserId): bool`, `->isAdmin(UserId): bool`, `->memberIds(): list<UserId>`.
  - `ConversationRepositoryInterface::save(Conversation): void`, `ofId(ConversationId): Conversation`, `ofDirectKey(DirectKey): ?Conversation`.
  - `CommandDispatcher::dispatch(object $command): mixed`, `QueryDispatcher::ask(object $query): mixed`.
  - `POST /api/conversations` (`type: "direct"`), `GET /api/conversations`.

- [ ] **Step 1: Écrire le test du VO DirectKey (il doit échouer)**

```php
<?php
// backend/tests/Unit/Conversation/Domain/DirectKeyTest.php
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
}
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=DirectKeyTest
```

Attendu : ÉCHEC, classes introuvables.

- [ ] **Step 3: Écrire les VO du domaine**

```php
<?php
// backend/src/Conversation/Domain/ConversationType.php
declare(strict_types=1);

namespace App\Conversation\Domain;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
}
```

```php
<?php
// backend/src/Conversation/Domain/MemberRole.php
declare(strict_types=1);

namespace App\Conversation\Domain;

enum MemberRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
```

```php
<?php
// backend/src/Conversation/Domain/SelfConversationException.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class SelfConversationException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('Impossible d\'ouvrir une conversation directe avec soi-meme.');
    }
}
```

```php
<?php
// backend/src/Conversation/Domain/DirectKey.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\UserId;

/**
 * Cle d'unicite d'une conversation 1-1. Commutative par construction :
 * l'invariant vit dans le type, pas dans la discipline de l'appelant.
 */
final readonly class DirectKey implements \Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function forPair(UserId $one, UserId $other): self
    {
        if ($one->equals($other)) {
            throw SelfConversationException::create();
        }

        $pair = [$one->toString(), $other->toString()];
        sort($pair);

        return new self(implode(':', $pair));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

```php
<?php
// backend/src/Conversation/Domain/Member.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\UserId;

final readonly class Member
{
    public function __construct(
        public UserId $userId,
        public MemberRole $role,
        public \DateTimeImmutable $joinedAt,
    ) {
    }
}
```

- [ ] **Step 4: Écrire l'agrégat et son port**

```php
<?php
// backend/src/Conversation/Domain/Conversation.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Racine d'agregat pour l'appartenance. Les messages ne sont PAS dans cet
 * agregat : la frontiere est choisie selon la taille de la transaction
 * d'ecriture, pas selon la logique de contenance (spec 3.6).
 */
final class Conversation
{
    use RecordsEventsTrait;

    /** @param list<Member> $members */
    private function __construct(
        private readonly ConversationId $id,
        private readonly ConversationType $type,
        private readonly ?string $title,
        private readonly ?DirectKey $directKey,
        private readonly UserId $createdBy,
        private readonly \DateTimeImmutable $createdAt,
        private array $members,
    ) {
    }

    public static function direct(
        ConversationId $id,
        UserId $initiator,
        UserId $peer,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            ConversationType::Direct,
            null,
            DirectKey::forPair($initiator, $peer),
            $initiator,
            $now,
            [
                new Member($initiator, MemberRole::Admin, $now),
                new Member($peer, MemberRole::Admin, $now),
            ],
        );
    }

    /** @param list<Member> $members */
    public static function reconstitute(
        ConversationId $id,
        ConversationType $type,
        ?string $title,
        ?DirectKey $directKey,
        UserId $createdBy,
        \DateTimeImmutable $createdAt,
        array $members,
    ): self {
        return new self($id, $type, $title, $directKey, $createdBy, $createdAt, $members);
    }

    public function id(): ConversationId
    {
        return $this->id;
    }

    public function type(): ConversationType
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function directKey(): ?DirectKey
    {
        return $this->directKey;
    }

    public function createdBy(): UserId
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<Member> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return list<UserId> */
    public function memberIds(): array
    {
        return array_map(static fn (Member $member): UserId => $member->userId, $this->members);
    }

    public function hasMember(UserId $userId): bool
    {
        foreach ($this->members as $member) {
            if ($member->userId->equals($userId)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(UserId $userId): bool
    {
        foreach ($this->members as $member) {
            if ($member->userId->equals($userId)) {
                return MemberRole::Admin === $member->role;
            }
        }

        return false;
    }
}
```

Dans un direct, les deux participants sont admin : il n'y a pas de hiérarchie à deux.

```php
<?php
// backend/src/Conversation/Domain/ConversationNotFoundException.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\ConversationId;

final class ConversationNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(ConversationId $id): self
    {
        return new self(sprintf('Conversation %s introuvable.', $id->toString()));
    }
}
```

```php
<?php
// backend/src/Conversation/Domain/ConversationRepositoryInterface.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\ConversationId;

interface ConversationRepositoryInterface
{
    public function save(Conversation $conversation): void;

    /** @throws ConversationNotFoundException */
    public function ofId(ConversationId $id): Conversation;

    public function ofDirectKey(DirectKey $key): ?Conversation;
}
```

- [ ] **Step 5: Écrire les dispatchers de bus**

```php
<?php
// backend/src/Shared/Infrastructure/Bus/CommandDispatcher.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class CommandDispatcher
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function dispatch(object $command): mixed
    {
        $envelope = $this->commandBus->dispatch($command);
        $stamp = $envelope->last(HandledStamp::class);

        if (!$stamp instanceof HandledStamp) {
            throw new LogicException('Aucun handler n\'a traite '.$command::class.'.');
        }

        return $stamp->getResult();
    }
}
```

```php
<?php
// backend/src/Shared/Infrastructure/Bus/QueryDispatcher.php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class QueryDispatcher
{
    public function __construct(private MessageBusInterface $queryBus)
    {
    }

    public function ask(object $query): mixed
    {
        $envelope = $this->queryBus->dispatch($query);
        $stamp = $envelope->last(HandledStamp::class);

        if (!$stamp instanceof HandledStamp) {
            throw new LogicException('Aucun handler n\'a traite '.$query::class.'.');
        }

        return $stamp->getResult();
    }
}
```

- [ ] **Step 6: Écrire la commande et son handler**

```php
<?php
// backend/src/Conversation/Application/Command/CreateDirectConversation.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Domain\Identifier\UserId;

final readonly class CreateDirectConversation
{
    public function __construct(
        public UserId $initiator,
        public UserId $peer,
    ) {
    }
}
```

```php
<?php
// backend/src/Conversation/Application/Command/CreateDirectConversationHandler.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateDirectConversationHandler
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private IdGeneratorInterface $idGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateDirectConversation $command): ConversationId
    {
        $key = DirectKey::forPair($command->initiator, $command->peer);
        $existing = $this->conversations->ofDirectKey($key);

        if (null !== $existing) {
            $this->logger->info('Conversation directe deja existante entre {initiator} et {peer}', [
                'initiator' => $command->initiator->toString(),
                'peer' => $command->peer->toString(),
                'conversation_id' => $existing->id()->toString(),
            ]);

            return $existing->id();
        }

        $conversation = Conversation::direct(
            ConversationId::fromString($this->idGenerator->generate()),
            $command->initiator,
            $command->peer,
            $this->clock->now(),
        );

        $this->conversations->save($conversation);

        $this->logger->notice('Conversation directe {conversation_id} creee', [
            'conversation_id' => $conversation->id()->toString(),
            'initiator' => $command->initiator->toString(),
        ]);

        return $conversation->id();
    }
}
```

- [ ] **Step 7: Écrire le mapper et le repository**

```php
<?php
// backend/src/Conversation/Infrastructure/Persistence/ConversationMapper.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationType;
use App\Conversation\Domain\DirectKey;
use App\Conversation\Domain\Member;
use App\Conversation\Domain\MemberRole;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class ConversationMapper
{
    /**
     * @param array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string} $row
     * @param list<array{user_id: string, role: string, joined_at: string}>                                                        $memberRows
     */
    public function fromRows(array $row, array $memberRows): Conversation
    {
        $members = array_map(
            static fn (array $memberRow): Member => new Member(
                UserId::fromString($memberRow['user_id']),
                MemberRole::from($memberRow['role']),
                new \DateTimeImmutable($memberRow['joined_at']),
            ),
            $memberRows,
        );

        return Conversation::reconstitute(
            ConversationId::fromString($row['id']),
            ConversationType::from($row['type']),
            $row['title'],
            null === $row['direct_key'] ? null : DirectKeyHydrator::fromString($row['direct_key']),
            UserId::fromString($row['created_by']),
            new \DateTimeImmutable($row['created_at']),
            $members,
        );
    }
}
```

`DirectKey` n'ayant qu'un constructeur nommé métier, la reconstitution passe par un petit hydrateur d'infrastructure — le domaine n'expose pas un constructeur « depuis une chaîne » qui permettrait de fabriquer une clé incohérente :

```php
<?php
// backend/src/Conversation/Infrastructure/Persistence/DirectKeyHydrator.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Identifier\UserId;

final class DirectKeyHydrator
{
    public static function fromString(string $value): DirectKey
    {
        [$one, $other] = explode(':', $value, 2);

        return DirectKey::forPair(UserId::fromString($one), UserId::fromString($other));
    }
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Persistence/DbalConversationRepository.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationNotFoundException;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Conversation\Domain\DirectKey;
use App\Conversation\Domain\Member;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalConversationRepository implements ConversationRepositoryInterface
{
    private const string COLUMNS = 'id, type, title, direct_key, created_by, created_at';

    public function __construct(
        private Connection $connection,
        private ConversationMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function save(Conversation $conversation): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversations (id, type, title, direct_key, created_by, created_at)
             VALUES (:id, :type, :title, :direct_key, :created_by, :created_at)
             ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title',
            [
                'id' => $conversation->id()->toString(),
                'type' => $conversation->type()->value,
                'title' => $conversation->title(),
                'direct_key' => $conversation->directKey()?->toString(),
                'created_by' => $conversation->createdBy()->toString(),
                'created_at' => $conversation->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        $memberIds = array_map(
            static fn (Member $member): string => $member->userId->toString(),
            $conversation->members(),
        );

        // Les membres retires disparaissent, les nouveaux apparaissent : l'etat
        // en base reflete exactement l'agregat, sans change tracking implicite.
        $this->connection->executeStatement(
            'DELETE FROM conversation_members WHERE conversation_id = :id AND user_id NOT IN (:member_ids)',
            ['id' => $conversation->id()->toString(), 'member_ids' => $memberIds],
            ['member_ids' => ArrayParameterType::STRING],
        );

        foreach ($conversation->members() as $member) {
            $this->connection->executeStatement(
                'INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                 VALUES (:conversation_id, :user_id, :role, :joined_at)
                 ON CONFLICT (conversation_id, user_id) DO UPDATE SET role = EXCLUDED.role',
                [
                    'conversation_id' => $conversation->id()->toString(),
                    'user_id' => $member->userId->toString(),
                    'role' => $member->role->value,
                    'joined_at' => $member->joinedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        $this->collector->collect(...$conversation->releaseEvents());
    }

    public function ofId(ConversationId $id): Conversation
    {
        /** @var array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM conversations WHERE id = :id',
            ['id' => $id->toString()],
        );

        if (false === $row) {
            throw ConversationNotFoundException::withId($id);
        }

        return $this->mapper->fromRows($row, $this->memberRows($id->toString()));
    }

    public function ofDirectKey(DirectKey $key): ?Conversation
    {
        /** @var array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM conversations WHERE direct_key = :direct_key',
            ['direct_key' => $key->toString()],
        );

        return false === $row ? null : $this->mapper->fromRows($row, $this->memberRows($row['id']));
    }

    /** @return list<array{user_id: string, role: string, joined_at: string}> */
    private function memberRows(string $conversationId): array
    {
        /** @var list<array{user_id: string, role: string, joined_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT user_id, role, joined_at FROM conversation_members
             WHERE conversation_id = :conversation_id ORDER BY joined_at ASC',
            ['conversation_id' => $conversationId],
        );

        return $rows;
    }
}
```

- [ ] **Step 8: Écrire la query de liste**

```php
<?php
// backend/src/Conversation/Application/Query/ConversationView.php
declare(strict_types=1);

namespace App\Conversation\Application\Query;

/** DTO de lecture : ne traverse jamais le domaine (CQS, spec 3.3). */
final readonly class ConversationView
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $title,
        public ?string $lastMessageAt,
        public ?string $lastMessagePreview,
        public ?string $lastMessageSenderId,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'last_message_at' => $this->lastMessageAt,
            'last_message_preview' => $this->lastMessagePreview,
            'last_message_sender_id' => $this->lastMessageSenderId,
        ];
    }
}
```

```php
<?php
// backend/src/Conversation/Application/Query/ListMyConversations.php
declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Domain\Identifier\UserId;

final readonly class ListMyConversations
{
    public function __construct(public UserId $userId)
    {
    }
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Persistence/SqlMyConversationsQuery.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\Query\ConversationView;
use App\Conversation\Application\Query\ListMyConversations;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cote lecture : SQL direct vers un DTO. Le pointeur last_message_* evite de
 * chercher le dernier message de chaque conversation (spec 2).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class SqlMyConversationsQuery
{
    // Aucune jointure vers messages : l'apercu est denormalise sur la conversation,
    // ecrit par le listener qui reagit a MessageWasSent (ADR 0001). Conversation
    // ne lit donc jamais la table d'un autre contexte.
    private const string SQL = <<<'SQL'
        SELECT c.id,
               c.type,
               c.title,
               c.last_message_at,
               c.last_message_preview,
               c.last_message_sender_id
        FROM conversations c
        INNER JOIN conversation_members cm
                ON cm.conversation_id = c.id AND cm.user_id = :user_id
        ORDER BY c.last_message_at DESC NULLS LAST, c.id DESC
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<ConversationView> */
    public function __invoke(ListMyConversations $query): array
    {
        /** @var list<array{id: string, type: string, title: string|null, last_message_at: string|null, last_message_preview: string|null, last_message_sender_id: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(self::SQL, ['user_id' => $query->userId->toString()]);

        return array_map(
            static fn (array $row): ConversationView => new ConversationView(
                $row['id'],
                $row['type'],
                $row['title'],
                $row['last_message_at'],
                $row['last_message_preview'],
                $row['last_message_sender_id'],
            ),
            $rows,
        );
    }
}
```

- [ ] **Step 9: Écrire les contrôleurs**

```php
<?php
// backend/src/Conversation/Infrastructure/Http/CreateConversationController.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\CreateDirectConversation;
use App\Shared\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class CreateConversationController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route('/api/conversations', name: 'conversations_create', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        /** @var array{type?: string, member_ids?: list<string>} $payload */
        $payload = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $me = $securityUser->userId();
        $memberIds = $payload['member_ids'] ?? [];

        if ('direct' !== ($payload['type'] ?? null) || 1 !== count($memberIds)) {
            throw new UnsupportedConversationPayloadException();
        }

        $existedBefore = null;
        $conversationId = $this->commands->dispatch(
            new CreateDirectConversation($me, UserId::fromString($memberIds[0])),
        );

        \assert($conversationId instanceof ConversationId);

        return new JsonResponse(
            ['id' => $conversationId->toString()],
            $existedBefore ?? Response::HTTP_CREATED,
        );
    }
}
```

> **Attention** : ce contrôleur est volontairement incomplet — il ne distingue pas encore 201 de 200, et ne gère pas les groupes. La tâche 9 le remplace intégralement. Écrire ici la version minimale qui fait passer le test de la tâche 8, puis la faire évoluer.

```php
<?php
// backend/src/Conversation/Infrastructure/Http/UnsupportedConversationPayloadException.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class UnsupportedConversationPayloadException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Charge utile de creation de conversation invalide.');
    }
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Http/ListConversationsController.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Query\ConversationView;
use App\Conversation\Application\Query\ListMyConversations;
use App\Shared\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ListConversationsController
{
    public function __construct(private QueryDispatcher $queries)
    {
    }

    #[Route('/api/conversations', name: 'conversations_list', methods: ['GET'])]
    public function __invoke(#[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        /** @var list<ConversationView> $views */
        $views = $this->queries->ask(new ListMyConversations($securityUser->userId()));

        return new JsonResponse(array_map(
            static fn (ConversationView $view): array => $view->toArray(),
            $views,
        ));
    }
}
```

- [ ] **Step 10: Écrire le test fonctionnel**

```php
<?php
// backend/tests/Functional/Conversation/CreateDirectConversationTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\Identity\AuthenticationTest;

final class CreateDirectConversationTest extends AuthenticationTest
{
    public function testCreatingTheSameDirectTwiceReturnsTheSameConversation(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');

        $first = $this->createDirect($bobId);
        $second = $this->createDirect($bobId);

        self::assertSame($first, $second, 'La creation d\'un direct doit etre idempotente.');
    }

    public function testMyConversationsAreListed(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/conversations');

        self::assertResponseIsSuccessful();

        /** @var list<array{id: string, type: string}> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(2, count($body), 'Alice a au moins le direct et le groupe des fixtures.');
    }

    public function testConversationsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/conversations');

        self::assertResponseStatusCodeSame(401);
    }

    protected function userId(string $username): string
    {
        $this->client->request('GET', '/api/users');

        /** @var list<array{id: string, username: string}> $users */
        $users = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        foreach ($users as $user) {
            if ($username === $user['username']) {
                return $user['id'];
            }
        }

        self::fail('Utilisateur '.$username.' absent de l\'annuaire.');
    }

    private function createDirect(string $peerId): string
    {
        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['type' => 'direct', 'member_ids' => [$peerId]], \JSON_THROW_ON_ERROR),
        );

        /** @var array{id: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['id'];
    }
}
```

- [ ] **Step 11: Vérifier**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 12: Commit**

```bash
git checkout -b feat/conversations-directes
git add backend/src/Conversation backend/src/Shared/Infrastructure/Bus backend/tests
git commit -m "feat(conversation): conversations directes idempotentes et liste"
```

---

## Task 9: Groupes, membres et autorisation

**Files:**
- Create: `backend/src/Conversation/Domain/Event/MembershipChanged.php`, `NotAGroupException.php`, `NotAnAdminException.php`
- Create: `backend/src/Conversation/Application/Command/CreateGroupConversation.php` + handler, `AddMembers.php` + handler, `RemoveMember.php` + handler
- Create: `backend/src/Conversation/Application/MembershipCheckerInterface.php`
- Create: `backend/src/Conversation/Infrastructure/Persistence/DbalMembershipChecker.php`
- Create: `backend/src/Conversation/Infrastructure/Security/ConversationVoter.php`
- Create: `backend/src/Conversation/Infrastructure/Http/AddMembersController.php`, `RemoveMemberController.php`, `GetConversationController.php`
- Create: `backend/src/Realtime/Application/EventListener/PublishMembershipChanged.php`
- Modify: `backend/src/Conversation/Domain/Conversation.php` (méthodes `group()`, `addMember()`, `removeMember()`)
- Modify: `backend/src/Conversation/Infrastructure/Http/CreateConversationController.php` (version complète)
- Create: `backend/tests/Unit/Conversation/Domain/ConversationMembershipTest.php`, `backend/tests/Functional/Conversation/GroupMembersTest.php`

**Interfaces:**
- Consumes: tout ce que produit la tâche 8, plus `Topic`, `EventPublisherInterface` (tâche 7).
- Produces:
  - `Conversation::group(ConversationId, string $title, UserId $creator, list<UserId> $others, \DateTimeImmutable): self`
  - `Conversation::addMember(UserId, \DateTimeImmutable): void` — enregistre `MembershipChanged`
  - `Conversation::removeMember(UserId): void` — enregistre `MembershipChanged`
  - `MembershipCheckerInterface::isMember(ConversationId, UserId): bool`, `isAdmin(ConversationId, UserId): bool`
  - `ConversationVoter` : attributs `VIEW`, `POST_MESSAGE`, `MANAGE_MEMBERS`
  - `POST /api/conversations` gère `type: "group"` et renvoie 201 ou 200 · `GET /api/conversations/{id}` · `POST /api/conversations/{id}/members` · `DELETE /api/conversations/{id}/members/{userId}`

- [ ] **Step 1: Écrire le test unitaire d'appartenance (il doit échouer)**

```php
<?php
// backend/tests/Unit/Conversation/Domain/ConversationMembershipTest.php
declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\Event\MembershipChanged;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class ConversationMembershipTest extends TestCase
{
    private const string CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';
    private const string ALICE = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string BOB = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';
    private const string CAROL = '01J9ZQ7X8K3M4N5P6Q7R8S9TAD';

    public function testTheGroupCreatorIsAdminAndOthersAreMembers(): void
    {
        $group = $this->group();

        self::assertTrue($group->isAdmin(UserId::fromString(self::ALICE)));
        self::assertTrue($group->hasMember(UserId::fromString(self::BOB)));
        self::assertFalse($group->isAdmin(UserId::fromString(self::BOB)));
    }

    public function testAddingAMemberRecordsAnEvent(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::CAROL), new \DateTimeImmutable('2026-07-25 10:00:00'));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::CAROL, $events[0]->userId->toString());
        self::assertSame('joined', $events[0]->change);
        self::assertTrue($group->hasMember(UserId::fromString(self::CAROL)));
    }

    public function testAddingAnExistingMemberIsANoOp(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::BOB), new \DateTimeImmutable('2026-07-25 10:00:00'));

        self::assertSame([], $group->releaseEvents(), 'Reajouter un membre ne doit rien produire.');
    }

    public function testRemovingAMemberRecordsAnEvent(): void
    {
        $group = $this->group();
        $group->removeMember(UserId::fromString(self::BOB));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame('left', $events[0]->change);
        self::assertFalse($group->hasMember(UserId::fromString(self::BOB)));
    }

    public function testEventsAreReleasedOnlyOnce(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::CAROL), new \DateTimeImmutable('2026-07-25 10:00:00'));

        $group->releaseEvents();

        self::assertSame([], $group->releaseEvents());
    }

    private function group(): Conversation
    {
        return Conversation::group(
            ConversationId::fromString(self::CONVERSATION),
            'Equipe projet',
            UserId::fromString(self::ALICE),
            [UserId::fromString(self::BOB)],
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );
    }
}
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=ConversationMembershipTest
```

Attendu : ÉCHEC, `Conversation::group()` n'existe pas.

- [ ] **Step 3: Écrire l'événement et les exceptions**

```php
<?php
// backend/src/Shared/Domain/Event/MembershipChanged.php
// Ecoute par Realtime : evenement partage, donc dans Shared (regle inter-contextes).
declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class MembershipChanged implements DomainEventInterface
{
    // Charge utile en types Shared et scalaires uniquement : c'est un contrat.
    /** @param 'joined'|'left' $change */
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public string $change,
    ) {
    }
}
```

```php
<?php
// backend/src/Conversation/Domain/NotAGroupException.php
declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class NotAGroupException extends \LogicException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('La composition d\'une conversation directe ne peut pas etre modifiee.');
    }
}
```

- [ ] **Step 4: Compléter l'agrégat**

Ajouter à `Conversation` :

```php
    /** @param list<UserId> $others */
    public static function group(
        ConversationId $id,
        string $title,
        UserId $creator,
        array $others,
        \DateTimeImmutable $now,
    ): self {
        $members = [new Member($creator, MemberRole::Admin, $now)];

        foreach ($others as $userId) {
            if ($userId->equals($creator)) {
                continue;
            }

            $members[] = new Member($userId, MemberRole::Member, $now);
        }

        return new self($id, ConversationType::Group, $title, null, $creator, $now, $members);
    }

    public function addMember(UserId $userId, \DateTimeImmutable $now): void
    {
        $this->assertIsGroup();

        if ($this->hasMember($userId)) {
            return;
        }

        $this->members[] = new Member($userId, MemberRole::Member, $now);
        $this->recordEvent(new MembershipChanged($this->id, $userId, 'joined'));
    }

    public function removeMember(UserId $userId): void
    {
        $this->assertIsGroup();

        if (!$this->hasMember($userId)) {
            return;
        }

        $this->members = array_values(array_filter(
            $this->members,
            static fn (Member $member): bool => !$member->userId->equals($userId),
        ));

        $this->recordEvent(new MembershipChanged($this->id, $userId, 'left'));
    }

    private function assertIsGroup(): void
    {
        if (ConversationType::Group !== $this->type) {
            throw NotAGroupException::create();
        }
    }
```

Ajouter les `use` correspondants (`MembershipChanged`, `NotAGroupException`).

- [ ] **Step 5: Vérifier que le test unitaire passe**

```bash
docker compose exec backend vendor/bin/phpunit --filter=ConversationMembershipTest
```

Attendu : PASS (5 tests).

- [ ] **Step 6: Écrire le vérificateur d'appartenance et le voter**

```php
<?php
// backend/src/Conversation/Application/MembershipCheckerInterface.php
declare(strict_types=1);

namespace App\Conversation\Application;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

interface MembershipCheckerInterface
{
    public function isMember(ConversationId $conversationId, UserId $userId): bool;

    public function isAdmin(ConversationId $conversationId, UserId $userId): bool;
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Persistence/DbalMembershipChecker.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\MembershipCheckerInterface;
use App\Conversation\Domain\MemberRole;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipChecker implements MembershipCheckerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function isMember(ConversationId $conversationId, UserId $userId): bool
    {
        return null !== $this->roleOf($conversationId, $userId);
    }

    public function isAdmin(ConversationId $conversationId, UserId $userId): bool
    {
        return MemberRole::Admin->value === $this->roleOf($conversationId, $userId);
    }

    private function roleOf(ConversationId $conversationId, UserId $userId): ?string
    {
        /** @var string|false $role */
        $role = $this->connection->fetchOne(
            'SELECT role FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id',
            ['conversation_id' => $conversationId->toString(), 'user_id' => $userId->toString()],
        );

        return false === $role ? null : $role;
    }
}
```

```php
<?php
// backend/src/Conversation/Infrastructure/Security/ConversationVoter.php
declare(strict_types=1);

namespace App\Conversation\Infrastructure\Security;

use App\Conversation\Application\MembershipCheckerInterface;
use App\Shared\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Identifier\ConversationId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ConversationId>
 */
final class ConversationVoter extends Voter
{
    public const string VIEW = 'CONVERSATION_VIEW';
    public const string POST_MESSAGE = 'CONVERSATION_POST_MESSAGE';
    public const string MANAGE_MEMBERS = 'CONVERSATION_MANAGE_MEMBERS';

    public function __construct(
        private readonly MembershipCheckerInterface $membership,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::POST_MESSAGE, self::MANAGE_MEMBERS], true)
            && $subject instanceof ConversationId;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof SecurityUser) {
            return false;
        }

        $granted = match ($attribute) {
            self::MANAGE_MEMBERS => $this->membership->isAdmin($subject, $user->userId()),
            default => $this->membership->isMember($subject, $user->userId()),
        };

        if (!$granted) {
            $this->logger->warning('Acces refuse a {conversation_id} pour {user_id} ({attribute})', [
                'conversation_id' => $subject->toString(),
                'user_id' => $user->userId()->toString(),
                'attribute' => $attribute,
            ]);
        }

        return $granted;
    }
}
```

- [ ] **Step 7: Écrire les commandes de groupe**

```php
<?php
// backend/src/Conversation/Application/Command/CreateGroupConversation.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Domain\Identifier\UserId;

final readonly class CreateGroupConversation
{
    /** @param list<UserId> $members */
    public function __construct(
        public UserId $creator,
        public string $title,
        public array $members,
    ) {
    }
}
```

```php
<?php
// backend/src/Conversation/Application/Command/CreateGroupConversationHandler.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationRepositoryInterface;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateGroupConversationHandler
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private IdGeneratorInterface $idGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateGroupConversation $command): ConversationId
    {
        $conversation = Conversation::group(
            ConversationId::fromString($this->idGenerator->generate()),
            $command->title,
            $command->creator,
            $command->members,
            $this->clock->now(),
        );

        $this->conversations->save($conversation);

        $this->logger->notice('Groupe {conversation_id} cree avec {member_count} membres', [
            'conversation_id' => $conversation->id()->toString(),
            'member_count' => count($conversation->memberIds()),
            'creator' => $command->creator->toString(),
        ]);

        return $conversation->id();
    }
}
```

```php
<?php
// backend/src/Conversation/Application/Command/AddMembers.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class AddMembers
{
    /** @param list<UserId> $userIds */
    public function __construct(
        public ConversationId $conversationId,
        public array $userIds,
    ) {
    }
}
```

```php
<?php
// backend/src/Conversation/Application/Command/AddMembersHandler.php
declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\ConversationRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddMembersHandler
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddMembers $command): void
    {
        $conversation = $this->conversations->ofId($command->conversationId);
        $now = $this->clock->now();

        foreach ($command->userIds as $userId) {
            $conversation->addMember($userId, $now);
        }

        $this->conversations->save($conversation);

        $this->logger->notice('{added_count} membres ajoutes a {conversation_id}', [
            'added_count' => count($command->userIds),
            'conversation_id' => $command->conversationId->toString(),
        ]);
    }
}
```

`RemoveMember` / `RemoveMemberHandler` suivent exactement la même forme, avec un seul `UserId` et un appel à `removeMember()`.

- [ ] **Step 8: Écrire l'abonné qui publie `membership.changed`**

```php
<?php
// backend/src/Realtime/Application/EventListener/PublishMembershipChanged.php
declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Event\MembershipChanged;
use App\Shared\Domain\IdGeneratorInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publie sur le topic personnel du membre concerne. Ce topic est present dans
 * TOUS ses JWT et ne change jamais : c'est le seul canal par lequel il peut
 * apprendre qu'on vient de l'ajouter a une conversation (spec 5).
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class PublishMembershipChanged
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
                'change' => $event->change,
            ],
            $this->idGenerator->generate(),
        );
    }
}
```

> `MembershipChanged` vit dans `Shared/Domain/Event/` précisément parce que `Realtime` l'écoute : un abonné doit connaître l'événement auquel il s'abonne, donc l'événement est un **contrat partagé**. **Aucune règle deptrac à ajouter** — `RealtimeApplication` dépend déjà de `SharedDomain`.

- [ ] **Step 9: Écrire les contrôleurs restants et remplacer `CreateConversationController`**

`CreateConversationController` complet : lit `type`, distingue `direct` (une seule `member_ids`, réponse **200** si la conversation existait déjà, **201** sinon) et `group` (titre requis, réponse 201). Pour distinguer 200 de 201 sur un direct, interroger `ofDirectKey` **avant** de dispatcher, via une query dédiée `FindDirectConversation` traitée sur le `query.bus`.

`AddMembersController` et `RemoveMemberController` : `#[IsGranted(ConversationVoter::MANAGE_MEMBERS, subject: 'conversationId')]`, avec `ConversationId` résolu depuis la route.

`GetConversationController` : `#[IsGranted(ConversationVoter::VIEW, subject: 'conversationId')]`, renvoie l'identifiant, le type, le titre et la liste des membres.

- [ ] **Step 10: Écrire le test fonctionnel des groupes**

```php
<?php
// backend/tests/Functional/Conversation/GroupMembersTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Support\InMemoryEventPublisher;

final class GroupMembersTest extends CreateDirectConversationTest
{
    public function testAddingAMemberPublishesOnTheirPersonalTopic(): void
    {
        $this->login('alice');
        $carolId = $this->userId('carol');
        $groupId = $this->createGroup('Nouveau groupe', [$this->userId('bob')]);

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);
        \assert($publisher instanceof InMemoryEventPublisher);

        $this->client->request(
            'POST',
            '/api/conversations/'.$groupId.'/members',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['user_ids' => [$carolId]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $membershipEvents = array_values(array_filter(
            $publisher->published(),
            static fn (array $entry): bool => 'membership.changed' === $entry['type'],
        ));

        self::assertNotEmpty($membershipEvents);
        self::assertSame('/users/'.$carolId.'/system', end($membershipEvents)['topic']);
    }

    public function testANonMemberGetsA404AndNeverA403(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->client->request('POST', '/api/logout');
        $this->login('carol');

        $this->client->request('GET', '/api/conversations/'.$groupId);

        self::assertResponseStatusCodeSame(404, 'Un 403 confirmerait l\'existence de la conversation.');
    }

    public function testAnUnknownIdIsIndistinguishableFromAnInaccessibleOne(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->client->request('POST', '/api/logout');
        $this->login('carol');

        $this->client->request('GET', '/api/conversations/'.$groupId);
        $inaccessible = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZZ');
        $unknown = (string) $this->client->getResponse()->getContent();

        $normalize = static function (string $json): array {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            unset($decoded['correlation_id']);

            return $decoded;
        };

        self::assertSame($normalize($unknown), $normalize($inaccessible));
    }

    protected function createGroup(string $title, array $memberIds): string
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
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['id'];
    }
}
```

Le troisième test est celui qui verrouille la décision de sécurité : les deux réponses doivent être **identiques** au `correlation_id` près.

- [ ] **Step 11: Vérifier**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 12: Commit**

```bash
git checkout -b feat/groupes-et-membres
git add backend/src backend/tests backend/deptrac.yaml
git commit -m "feat(conversation): groupes, gestion des membres et autorisation"
```

---

## Task 10: Envoi de message idempotent et publication

**Files:**
- Create: `backend/src/Message/Domain/Message.php`, `MessageContent.php`, `ClientMessageId.php`, `MessageRepositoryInterface.php`, `Event/MessageWasSent.php`
- Create: `backend/src/Message/Application/Command/SendMessage.php`, `SendMessageHandler.php`, `SendMessageResult.php`
- Create: `backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php`, `MessageMapper.php`
- Create: `backend/src/Message/Infrastructure/Http/SendMessageController.php`
- Create: `backend/src/Realtime/Application/EventListener/PublishMessageWasSent.php`
- Create: `backend/src/Conversation/Application/EventListener/RecordLastMessageOnMessageWasSent.php`, `Command/RecordLastMessage.php` + handler
- Create: `backend/tests/Unit/Message/Domain/MessageContentTest.php`, `backend/tests/Functional/Message/SendMessageTest.php`

**Interfaces:**
- Consumes: `ConversationVoter` (tâche 9), `EventPublisherInterface` (tâche 7), `MessageId` (`Shared/Domain/Identifier`).
- Produces:
  - `MessageContent::fromString(string): self` — refuse le vide et au-delà de 4000 caractères.
  - `ClientMessageId extends AbstractUlidIdentifier`.
  - `MessageRepositoryInterface::insertIfAbsent(Message): ?Message` — renvoie `null` si le message a été inséré, l'existant en cas de conflit.
  - `SendMessageResult` : `messageId: MessageId`, `wasCreated: bool`.
  - `POST /api/conversations/{id}/messages` → 201 à la création, **200** au rejeu.

- [ ] **Step 1: Écrire le test du VO de contenu (il doit échouer)**

```php
<?php
// backend/tests/Unit/Message/Domain/MessageContentTest.php
declare(strict_types=1);

namespace App\Tests\Unit\Message\Domain;

use App\Message\Domain\EmptyMessageContentException;
use App\Message\Domain\MessageContent;
use App\Message\Domain\MessageContentTooLongException;
use PHPUnit\Framework\TestCase;

final class MessageContentTest extends TestCase
{
    public function testItKeepsTheTrimmedText(): void
    {
        self::assertSame('bonjour', MessageContent::fromString('  bonjour  ')->toString());
    }

    public function testItRejectsAnEmptyString(): void
    {
        $this->expectException(EmptyMessageContentException::class);

        MessageContent::fromString('');
    }

    public function testItRejectsWhitespaceOnly(): void
    {
        $this->expectException(EmptyMessageContentException::class);

        MessageContent::fromString("   \n\t  ");
    }

    public function testItAcceptsExactlyTheMaximumLength(): void
    {
        $text = str_repeat('a', MessageContent::MAX_LENGTH);

        self::assertSame($text, MessageContent::fromString($text)->toString());
    }

    public function testItRejectsOneCharacterTooMany(): void
    {
        $this->expectException(MessageContentTooLongException::class);

        MessageContent::fromString(str_repeat('a', MessageContent::MAX_LENGTH + 1));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $text = str_repeat('é', MessageContent::MAX_LENGTH);

        self::assertSame(MessageContent::MAX_LENGTH, mb_strlen(MessageContent::fromString($text)->toString()));
    }
}
```

Le dernier test évite le piège classique : `strlen` compterait `é` pour deux octets et rejetterait un message pourtant valide.

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=MessageContentTest
```

- [ ] **Step 3: Écrire les VO et l'agrégat**

```php
<?php
// backend/src/Message/Domain/MessageContent.php
declare(strict_types=1);

namespace App\Message\Domain;

final readonly class MessageContent implements \Stringable
{
    public const int MAX_LENGTH = 4000;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw EmptyMessageContentException::create();
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw MessageContentTooLongException::create();
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

```php
<?php
// backend/src/Message/Domain/EmptyMessageContentException.php
declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class EmptyMessageContentException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('Un message ne peut pas etre vide.');
    }
}
```

`MessageContentTooLongException` suit la même forme, avec le message « Un message ne peut pas depasser 4000 caracteres. ».

```php
<?php
// backend/src/Message/Domain/ClientMessageId.php
declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;

/** Cle d'idempotence generee par le CLIENT avant le premier envoi (spec 6). */
final class ClientMessageId extends AbstractUlidIdentifier
{
}
```

```php
<?php
// backend/src/Message/Domain/Message.php
declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Event\MessageWasSent;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final class Message
{
    use RecordsEventsTrait;

    private function __construct(
        private readonly MessageId $id,
        private readonly ConversationId $conversationId,
        private readonly UserId $senderId,
        private readonly MessageContent $content,
        private readonly ClientMessageId $clientMessageId,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function send(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        MessageContent $content,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $now,
    ): self {
        $message = new self($id, $conversationId, $senderId, $content, $clientMessageId, $now);
        $message->recordEvent(
            new MessageWasSent($id, $conversationId, $senderId, $content->toString(), $now),
        );

        return $message;
    }

    public static function reconstitute(
        MessageId $id,
        ConversationId $conversationId,
        UserId $senderId,
        MessageContent $content,
        ClientMessageId $clientMessageId,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, $senderId, $content, $clientMessageId, $createdAt);
    }

    public function id(): MessageId
    {
        return $this->id;
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function senderId(): UserId
    {
        return $this->senderId;
    }

    public function content(): MessageContent
    {
        return $this->content;
    }

    public function clientMessageId(): ClientMessageId
    {
        return $this->clientMessageId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

`reconstitute()` n'enregistre **aucun** événement : c'est ce qui garantit qu'un rejeu idempotent ne republie rien, sans le moindre `if`.

```php
<?php
// backend/src/Shared/Domain/Event/MessageWasSent.php
// Ecoute par Realtime : evenement partage, donc dans Shared (regle inter-contextes).
declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final readonly class MessageWasSent implements DomainEventInterface
{
    /**
     * Le contenu voyage en string, PAS en MessageContent : un evenement partage
     * ne transporte que des types de Shared et des scalaires. L'inverse ferait
     * dependre Shared du contexte Message — une inversion pire que le probleme.
     * L'invariant de validite a de toute facon deja ete verifie a la construction
     * du MessageContent, en amont.
     */
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public string $content,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
```

```php
<?php
// backend/src/Message/Domain/MessageRepositoryInterface.php
declare(strict_types=1);

namespace App\Message\Domain;

interface MessageRepositoryInterface
{
    /**
     * Insere le message, sauf si la cle (sender_id, client_message_id) existe deja.
     *
     * @return Message|null null si le message vient d'etre cree ; le message
     *                      deja present en cas de rejeu
     */
    public function insertIfAbsent(Message $message): ?Message;
}
```

```php
<?php
// backend/src/Message/Application/Command/SendMessage.php
declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\MessageContent;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class SendMessage
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $senderId,
        public MessageContent $content,
        public ClientMessageId $clientMessageId,
    ) {
    }
}
```

```php
<?php
// backend/src/Message/Application/Command/SendMessageResult.php
declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Shared\Domain\Identifier\MessageId;

final readonly class SendMessageResult
{
    public function __construct(
        public MessageId $messageId,
        /** false = rejeu idempotent, le controleur repondra 200 au lieu de 201 */
        public bool $wasCreated,
    ) {
    }
}
```

```php
<?php
// backend/src/Message/Infrastructure/Persistence/MessageMapper.php
declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\Message;
use App\Message\Domain\MessageContent;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final readonly class MessageMapper
{
    /** @param array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string} $row */
    public function fromRow(array $row): Message
    {
        return Message::reconstitute(
            MessageId::fromString($row['id']),
            ConversationId::fromString($row['conversation_id']),
            UserId::fromString($row['sender_id']),
            MessageContent::fromString($row['content']),
            ClientMessageId::fromString($row['client_message_id']),
            new \DateTimeImmutable($row['created_at']),
        );
    }
}
```

- [ ] **Step 4: Écrire le repository avec `ON CONFLICT`**

```php
<?php
// backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php
declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Domain\Message;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalMessageRepository implements MessageRepositoryInterface
{
    /**
     * Zero ligne retournee = la cle (sender_id, client_message_id) existe deja.
     * Le rejeu passe par du controle de flux ordinaire, pas par une exception.
     */
    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO messages (id, conversation_id, sender_id, content, client_message_id, created_at)
        VALUES (:id, :conversation_id, :sender_id, :content, :client_message_id, :created_at)
        ON CONFLICT (sender_id, client_message_id) DO NOTHING
        RETURNING id
        SQL;

    public function __construct(
        private Connection $connection,
        private MessageMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function insertIfAbsent(Message $message): ?Message
    {
        $inserted = $this->connection->fetchOne(self::INSERT_SQL, [
            'id' => $message->id()->toString(),
            'conversation_id' => $message->conversationId()->toString(),
            'sender_id' => $message->senderId()->toString(),
            'content' => $message->content()->toString(),
            'client_message_id' => $message->clientMessageId()->toString(),
            'created_at' => $message->createdAt()->format(\DateTimeInterface::ATOM),
        ]);

        if (false === $inserted) {
            return $this->ofClientKey(
                $message->senderId()->toString(),
                $message->clientMessageId()->toString(),
            );
        }

        // Message n'ecrit PAS dans conversations (ADR 0001) : le pointeur est mis
        // a jour par Conversation, qui ecoute MessageWasSent.
        $this->collector->collect(...$message->releaseEvents());

        return null;
    }

    private function ofClientKey(string $senderId, string $clientMessageId): Message
    {
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, conversation_id, sender_id, content, client_message_id, created_at
             FROM messages WHERE sender_id = :sender_id AND client_message_id = :client_message_id',
            ['sender_id' => $senderId, 'client_message_id' => $clientMessageId],
        );

        return $this->mapper->fromRow($row);
    }
}
```

- [ ] **Step 5: Écrire le handler**

```php
<?php
// backend/src/Message/Application/Command/SendMessageHandler.php
declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Message\Domain\Message;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Domain\Identifier\MessageId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SendMessageHandler
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private IdGeneratorInterface $idGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendMessage $command): SendMessageResult
    {
        $message = Message::send(
            MessageId::fromString($this->idGenerator->generate()),
            $command->conversationId,
            $command->senderId,
            $command->content,
            $command->clientMessageId,
            $this->clock->now(),
        );

        $existing = $this->messages->insertIfAbsent($message);

        if (null === $existing) {
            $this->logger->info('Message {message_id} envoye dans la conversation {conversation_id}', [
                'message_id' => $message->id()->toString(),
                'conversation_id' => $command->conversationId->toString(),
                'sender_id' => $command->senderId->toString(),
                'content_length' => mb_strlen($command->content->toString()),
            ]);

            return new SendMessageResult($message->id(), true);
        }

        // Meme cle, contenu different : signe d'un bug ou d'un abus cote client.
        // Le premier est conserve (spec 6) et l'anomalie est signalee.
        if ($existing->content()->toString() !== $command->content->toString()) {
            $this->logger->warning(
                'Rejeu de {client_message_id} avec un contenu different : le premier message est conserve',
                [
                    'client_message_id' => $command->clientMessageId->toString(),
                    'message_id' => $existing->id()->toString(),
                    'sender_id' => $command->senderId->toString(),
                ],
            );
        }

        return new SendMessageResult($existing->id(), false);
    }
}
```

Le log ne contient que la **longueur** du contenu, jamais le contenu.

- [ ] **Step 6: Écrire l'abonné de publication**

```php
<?php
// backend/src/Realtime/Application/EventListener/PublishMessageWasSent.php
declare(strict_types=1);

namespace App\Realtime\Application\EventListener;

use App\Realtime\Domain\EventPublisherInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Event\MessageWasSent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Un seul publish par message : le hub assure le fan-out O(N), le metier reste en O(1). */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class PublishMessageWasSent
{
    public function __construct(private EventPublisherInterface $publisher)
    {
    }

    public function __invoke(MessageWasSent $event): void
    {
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.created',
            [
                'id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'sender_id' => $event->senderId->toString(),
                'content' => $event->content,
                'created_at' => $event->createdAt->format(\DateTimeInterface::ATOM),
            ],
            // L'id de l'evenement SSE est l'ULID du message : Last-Event-ID
            // deviendra exploitable en T2 sans changer ce format.
            $event->messageId->toString(),
        );
    }
}
```

`MessageWasSent` étant dans `Shared/Domain/Event/`, **aucune règle deptrac n'est à modifier**.

- [ ] **Step 6 bis: Écrire le listener `Conversation` qui met à jour son propre pointeur**

C'est l'autre moitié de la chorégraphie : `Conversation` réagit au fait publié par `Message` et met à
jour **sa** table. `Message` n'y touche jamais.

```php
<?php
// backend/src/Conversation/Application/EventListener/RecordLastMessageOnMessageWasSent.php
declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RecordLastMessage;
use App\Shared\Domain\Event\MessageWasSent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class RecordLastMessageOnMessageWasSent
{
    private const int PREVIEW_LENGTH = 80;

    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(MessageWasSent $event): void
    {
        // Conversation reagit avec SA propre commande : un contexte ne pilote
        // jamais les use cases d'un autre, et n'est pilote par personne.
        $this->commandBus->dispatch(new RecordLastMessage(
            $event->conversationId,
            $event->messageId,
            $event->senderId,
            $event->createdAt,
            mb_substr($event->content, 0, self::PREVIEW_LENGTH),
        ));
    }
}
```

`RecordLastMessageHandler` exécute un seul `UPDATE` sur `conversations`
(`last_message_id`, `last_message_at`, `last_message_sender_id`, `last_message_preview`), et loggue en
`error` si la conversation est introuvable — cas anormal, mais non bloquant pour le message déjà
persisté.

Le test fonctionnel correspondant : après un envoi, `GET /api/conversations` renvoie l'aperçu du
message qui vient d'être envoyé.

> **Mode d'échec assumé** : si cette seconde transaction échoue, l'aperçu reste périmé jusqu'au
> message suivant, qui le corrige. Jamais de message perdu. C'est le prix de la chorégraphie, et il
> est documenté dans l'ADR 0001.

- [ ] **Step 7: Écrire le test fonctionnel de l'idempotence**

```php
<?php
// backend/tests/Functional/Message/SendMessageTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\Conversation\CreateDirectConversationTest;
use App\Tests\Support\InMemoryEventPublisher;

final class SendMessageTest extends CreateDirectConversationTest
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';

    public function testReplayingTheSameClientIdCreatesOnlyOneMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);
        \assert($publisher instanceof InMemoryEventPublisher);

        $first = $this->send($conversationId, self::CLIENT_ID, 'bonjour');
        self::assertResponseStatusCodeSame(201);

        $second = $this->send($conversationId, self::CLIENT_ID, 'bonjour');
        self::assertResponseStatusCodeSame(200);

        self::assertSame($first, $second, 'Le rejeu doit renvoyer le meme identifiant serveur.');

        $created = array_filter(
            $publisher->published(),
            static fn (array $entry): bool => 'message.created' === $entry['type'],
        );

        self::assertCount(1, $created, 'Le rejeu ne doit pas republier sur Mercure.');
    }

    public function testReplayWithDifferentContentKeepsTheFirstOne(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->send($conversationId, self::CLIENT_ID, 'premier');
        $this->send($conversationId, self::CLIENT_ID, 'second');

        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/conversations/'.$conversationId.'/messages');

        /** @var array{items: list<array{content: string}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('premier', $body['items'][0]['content']);
    }

    public function testAnEmptyMessageIsRejectedWithAProblemDocument(): void
    {
        $this->login('alice');

        $this->send($this->firstConversationId(), self::CLIENT_ID, '   ');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testANonMemberCannotPost(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->client->request('POST', '/api/logout');
        $this->login('carol');

        $this->send($conversationId, self::CLIENT_ID, 'intrusion');

        self::assertResponseStatusCodeSame(404);
    }

    private function firstConversationId(): string
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string}> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body[0]['id'];
    }

    private function send(string $conversationId, string $clientMessageId, string $content): string
    {
        $this->client->request(
            'POST',
            '/api/conversations/'.$conversationId.'/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'content' => $content],
                \JSON_THROW_ON_ERROR,
            ),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) && isset($decoded['id']) && \is_string($decoded['id']) ? $decoded['id'] : '';
    }
}
```

- [ ] **Step 8: Écrire le contrôleur**

`SendMessageController` : `#[IsGranted(ConversationVoter::POST_MESSAGE, subject: 'conversationId')]`, construit `SendMessage` depuis le corps JSON (`client_message_id`, `content`), dispatche, et renvoie **201** si `wasCreated`, **200** sinon, avec `{"id": "<ulid>"}`.

- [ ] **Step 9: Vérifier**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 10: Commit**

```bash
git checkout -b feat/envoi-de-messages
git add backend/src backend/tests backend/deptrac.yaml
git commit -m "feat(message): envoi idempotent, pointeur de conversation et publication"
```

---

## Task 11: Historique paginé par keyset

**Files:**
- Create: `backend/src/Message/Application/Query/GetMessagePage.php`, `MessageView.php`, `MessagePage.php`
- Create: `backend/src/Message/Infrastructure/Persistence/SqlMessagePageQuery.php`
- Create: `backend/src/Message/Infrastructure/Http/GetMessagesController.php`
- Create: `backend/tests/Functional/Message/MessagePaginationTest.php`

**Interfaces:**
- Consumes: `ConversationVoter` (tâche 9).
- Produces: `GET /api/conversations/{id}/messages?before={ulid}&limit=50` → `{"items": [...], "next_before": "<ulid>|null"}`, messages du plus récent au plus ancien.

- [ ] **Step 1: Écrire le test de pagination (il doit échouer)**

```php
<?php
// backend/tests/Functional/Message/MessagePaginationTest.php
declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\Conversation\CreateDirectConversationTest;
use App\Shared\Domain\IdGeneratorInterface;

final class MessagePaginationTest extends CreateDirectConversationTest
{
    public function testWalkingBackThroughHistoryHasNoGapAndNoDuplicate(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $sent = $this->sendMany($conversationId, 120);

        $collected = [];
        $before = null;

        do {
            $page = $this->fetchPage($conversationId, $before, 50);
            $collected = [...$collected, ...array_column($page['items'], 'id')];
            $before = $page['next_before'];
        } while (null !== $before);

        self::assertCount(120, $collected, 'Aucun message ne doit manquer.');
        self::assertSame(array_unique($collected), $collected, 'Aucun doublon.');
        self::assertSame(array_reverse($sent), $collected, 'Ordre : du plus recent au plus ancien.');
    }

    public function testAMessageInsertedBetweenTwoPagesNeverShiftsTheWindow(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $sent = $this->sendMany($conversationId, 60);

        $firstPage = $this->fetchPage($conversationId, null, 50);

        // Un message arrive pendant qu'on remonte l'historique : avec un OFFSET,
        // la page suivante sauterait un element. Avec un curseur, non.
        $this->sendMany($conversationId, 1);

        $secondPage = $this->fetchPage($conversationId, $firstPage['next_before'], 50);

        $ids = [...array_column($firstPage['items'], 'id'), ...array_column($secondPage['items'], 'id')];

        self::assertSame(array_unique($ids), $ids, 'Aucun doublon malgre l\'insertion concurrente.');
        self::assertContains($sent[0], $ids, 'Le plus ancien message doit rester atteignable.');
    }

    /** @return list<string> identifiants dans l'ordre d'envoi */
    private function sendMany(string $conversationId, int $count): array
    {
        $generator = static::getContainer()->get(IdGeneratorInterface::class);
        \assert($generator instanceof IdGeneratorInterface);

        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            $this->client->request(
                'POST',
                '/api/conversations/'.$conversationId.'/messages',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(
                    ['client_message_id' => $generator->generate(), 'content' => 'message '.$i],
                    \JSON_THROW_ON_ERROR,
                ),
            );

            /** @var array{id: string} $body */
            $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $ids[] = $body['id'];
        }

        return $ids;
    }

    /** @return array{items: list<array{id: string, content: string}>, next_before: string|null} */
    private function fetchPage(string $conversationId, ?string $before, int $limit): array
    {
        $query = ['limit' => (string) $limit];

        if (null !== $before) {
            $query['before'] = $before;
        }

        $this->client->request('GET', '/api/conversations/'.$conversationId.'/messages?'.http_build_query($query));

        self::assertResponseIsSuccessful();

        /** @var array{items: list<array{id: string, content: string}>, next_before: string|null} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }

    private function firstConversationId(): string
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string}> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body[0]['id'];
    }
}
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec backend vendor/bin/phpunit --filter=MessagePaginationTest
```

- [ ] **Step 3: Écrire les DTO de lecture**

```php
<?php
// backend/src/Message/Application/Query/MessageView.php
declare(strict_types=1);

namespace App\Message\Application\Query;

final readonly class MessageView
{
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        public string $content,
        public string $clientMessageId,
        public string $createdAt,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'content' => $this->content,
            'client_message_id' => $this->clientMessageId,
            'created_at' => $this->createdAt,
        ];
    }
}
```

```php
<?php
// backend/src/Message/Application/Query/MessagePage.php
declare(strict_types=1);

namespace App\Message\Application\Query;

final readonly class MessagePage
{
    /** @param list<MessageView> $items */
    public function __construct(
        public array $items,
        public ?string $nextBefore,
    ) {
    }
}
```

```php
<?php
// backend/src/Message/Application/Query/GetMessagePage.php
declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

final readonly class GetMessagePage
{
    public function __construct(
        public ConversationId $conversationId,
        public ?MessageId $before,
        public int $limit,
    ) {
    }
}
```

- [ ] **Step 4: Écrire la requête SQL keyset**

```php
<?php
// backend/src/Message/Infrastructure/Persistence/SqlMessagePageQuery.php
declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Application\Query\GetMessagePage;
use App\Message\Application\Query\MessagePage;
use App\Message\Application\Query\MessageView;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pagination par curseur : consomme l'index (conversation_id, id DESC).
 * Un OFFSET deviendrait faux des qu'un message arrive pendant la remontee.
 * Deux requetes litterales plutot qu'une requete conditionnelle : chacune
 * se lit d'un bloc et son plan d'execution est evident.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class SqlMessagePageQuery
{
    private const string COLUMNS = 'id, conversation_id, sender_id, content, client_message_id, created_at';

    private const string FIRST_PAGE_SQL = 'SELECT '.self::COLUMNS.'
        FROM messages
        WHERE conversation_id = :conversation_id
        ORDER BY id DESC
        LIMIT :limit';

    private const string NEXT_PAGE_SQL = 'SELECT '.self::COLUMNS.'
        FROM messages
        WHERE conversation_id = :conversation_id AND id < :before
        ORDER BY id DESC
        LIMIT :limit';

    public const int MAX_LIMIT = 100;

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetMessagePage $query): MessagePage
    {
        $limit = max(1, min($query->limit, self::MAX_LIMIT));

        $parameters = ['conversation_id' => $query->conversationId->toString(), 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if (null !== $query->before) {
            $parameters['before'] = $query->before->toString();
        }

        /** @var list<array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            null === $query->before ? self::FIRST_PAGE_SQL : self::NEXT_PAGE_SQL,
            $parameters,
            $types,
        );

        $items = array_map(
            static fn (array $row): MessageView => new MessageView(
                $row['id'],
                $row['conversation_id'],
                $row['sender_id'],
                $row['content'],
                $row['client_message_id'],
                $row['created_at'],
            ),
            $rows,
        );

        // Page pleine => il reste potentiellement des messages plus anciens.
        $nextBefore = count($items) === $limit ? $items[count($items) - 1]->id : null;

        return new MessagePage($items, $nextBefore);
    }
}
```

> Deux requêtes littérales plutôt qu'une seule avec un `CASE` ou un cast conditionnel sur `:before` :
> chacune se lit d'un bloc, et son plan d'exécution est évident à la lecture. C'est exactement ce que
> le SQL pur doit acheter.

- [ ] **Step 5: Écrire le contrôleur**

`GetMessagesController` : `#[IsGranted(ConversationVoter::VIEW, subject: 'conversationId')]`, lit `before` (optionnel, validé par `MessageId::fromString` — un ULID invalide donne donc un 422 cohérent) et `limit` (défaut 50), dispatche `GetMessagePage` sur le `query.bus`, renvoie `{"items": [...], "next_before": ...}`.

- [ ] **Step 6: Vérifier**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
```

- [ ] **Step 7: Vérification manuelle de bout en bout du backend**

```bash
docker compose exec backend bin/console app:fixtures:load
curl -s -c /tmp/jar -X POST http://localhost:8080/api/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"alice","password":"password"}'
curl -s -b /tmp/jar http://localhost:8080/api/conversations
```

Attendu : la liste des conversations d'Alice. La phase A est terminée.

- [ ] **Step 8: Commit**

```bash
git checkout -b feat/historique-pagine
git add backend/src backend/tests
git commit -m "feat(message): historique pagine par curseur keyset"
```

---

# Phase B — Frontend

> Nicolas est novice côté front. Chaque décision non évidente est commentée dans le code, et les commentaires expliquent le **pourquoi**, pas le *quoi*.

## Task 12: Socle frontend

**Files:**
- Create: `frontend/package.json`, `Dockerfile`, `vite.config.ts`, `tsconfig.json`, `tailwind.config.ts`, `postcss.config.js`, `index.html`, `src/main.tsx`, `src/App.tsx`, `src/index.css`
- Create: `frontend/src/smoke.test.ts`

**Interfaces:**
- Produces: `http://localhost:8080/` sert l'application React à travers Caddy, avec HMR ; `npx vitest run` s'exécute dans le conteneur.

- [ ] **Step 1: Écrire `package.json` et le `Dockerfile`**

```json
{
  "name": "instant-messaging-frontend",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "test": "vitest run",
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "ulid": "^2.3.0"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "autoprefixer": "^10.4.0",
    "postcss": "^8.4.0",
    "tailwindcss": "^3.4.0",
    "typescript": "^5.6.0",
    "vite": "^6.0.0",
    "vitest": "^2.1.0"
  }
}
```

```dockerfile
# frontend/Dockerfile
FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . .

EXPOSE 5173
```

- [ ] **Step 2: Configurer Vite pour fonctionner derrière Caddy**

```ts
// frontend/vite.config.ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Le navigateur parle a Caddy sur 8080, pas a Vite sur 5173.
    // Sans cette ligne, le client HMR tenterait de se connecter au mauvais port
    // et le rechargement a chaud ne fonctionnerait jamais.
    hmr: { clientPort: 8080 },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
```

- [ ] **Step 3: Écrire un test de fumée (il doit échouer avant l'installation)**

```ts
// frontend/src/smoke.test.ts
import { expect, test } from 'vitest';

test('la chaine de test fonctionne', () => {
  expect(1 + 1).toBe(2);
});
```

- [ ] **Step 4: Construire l'image, lever le service et vérifier**

```bash
docker compose up -d --build frontend
docker compose exec frontend npx vitest run
curl -s http://localhost:8080/ | head -5
```

Attendu : test vert, puis le HTML de l'application React servi par Caddy.

- [ ] **Step 5: Commit**

```bash
git checkout -b chore/socle-frontend
git add frontend/
git commit -m "chore(frontend): socle Vite, React, TypeScript, Tailwind et Vitest"
```

---

## Task 13: Client HTTP typé et écran de connexion

**Files:**
- Create: `frontend/src/api/types.ts`, `problem.ts`, `client.ts`
- Create: `frontend/src/ui/LoginScreen.tsx`
- Create: `frontend/src/api/problem.test.ts`
- Modify: `frontend/src/App.tsx`

**Interfaces:**
- Consumes: l'API de la phase A.
- Produces:
  - Types `Me`, `ConversationSummary`, `ApiMessage`, `MessagePage`, `RealtimeToken`.
  - `api.login(username, password)`, `api.me()`, `api.users()`, `api.conversations()`, `api.messages(conversationId, before?)`, `api.sendMessage(...)`, `api.realtimeToken()`, `api.createDirect(...)`, `api.createGroup(...)`, `api.addMembers(...)`.
  - `ProblemError` : erreur portant `status`, `type`, `detail`, `correlationId`.

- [ ] **Step 1: Écrire le test du parsing RFC 7807 (il doit échouer)**

```ts
// frontend/src/api/problem.test.ts
import { describe, expect, it } from 'vitest';
import { ProblemError, toProblemError } from './problem';

describe('toProblemError', () => {
  it('extrait les champs du document Problem Details', async () => {
    const response = new Response(
      JSON.stringify({
        type: '/problems/validation-failed',
        title: 'Requete invalide',
        status: 422,
        detail: 'Un message ne peut pas etre vide.',
        correlation_id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      }),
      { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
    );

    const error = await toProblemError(response);

    expect(error).toBeInstanceOf(ProblemError);
    expect(error.status).toBe(422);
    expect(error.type).toBe('/problems/validation-failed');
    expect(error.detail).toBe('Un message ne peut pas etre vide.');
    expect(error.correlationId).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAB');
  });

  it('reste utilisable si le corps n est pas un JSON exploitable', async () => {
    const response = new Response('<html>502</html>', { status: 502 });

    const error = await toProblemError(response);

    expect(error.status).toBe(502);
    expect(error.type).toBe('about:blank');
  });
});
```

Le second cas compte : un proxy en panne renvoie du HTML, et le client ne doit pas exploser sur un `JSON.parse`.

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec frontend npx vitest run src/api/problem.test.ts
```

- [ ] **Step 3: Écrire les types, le parsing et le client**

```ts
// frontend/src/api/types.ts
export type Me = { id: string; username: string; display_name: string };

export type UserSummary = Me;

export type ConversationSummary = {
  id: string;
  type: 'direct' | 'group';
  title: string | null;
  last_message_at: string | null;
  last_message_preview: string | null;
  last_message_sender_id: string | null;
};

export type ApiMessage = {
  id: string;
  conversation_id: string;
  sender_id: string;
  content: string;
  client_message_id: string;
  created_at: string;
};

export type MessagePageResponse = { items: ApiMessage[]; next_before: string | null };

export type RealtimeToken = { hub_url: string; topics: string[] };
```

```ts
// frontend/src/api/problem.ts
export class ProblemError extends Error {
  constructor(
    readonly status: number,
    readonly type: string,
    readonly detail: string,
    readonly correlationId: string | null,
  ) {
    super(`${status} ${type}: ${detail}`);
    this.name = 'ProblemError';
  }
}

export async function toProblemError(response: Response): Promise<ProblemError> {
  try {
    const body = (await response.json()) as Record<string, unknown>;

    return new ProblemError(
      typeof body.status === 'number' ? body.status : response.status,
      typeof body.type === 'string' ? body.type : 'about:blank',
      typeof body.detail === 'string' ? body.detail : response.statusText,
      typeof body.correlation_id === 'string' ? body.correlation_id : null,
    );
  } catch {
    // Corps illisible (HTML d'un proxy en panne, reponse vide) : on degrade
    // proprement plutot que de laisser remonter une erreur de parsing.
    return new ProblemError(response.status, 'about:blank', response.statusText, null);
  }
}
```

```ts
// frontend/src/api/client.ts
import { toProblemError } from './problem';
import type {
  ApiMessage,
  ConversationSummary,
  Me,
  MessagePageResponse,
  RealtimeToken,
  UserSummary,
} from './types';

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...init,
    // Indispensable : la session ET le cookie Mercure voyagent en cookies.
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...init.headers },
  });

  if (!response.ok) {
    throw await toProblemError(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export const api = {
  login: (username: string, password: string) =>
    request<{ status: string }>('/api/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }),

  logout: () => request<void>('/api/logout', { method: 'POST' }),

  me: () => request<Me>('/api/me'),

  users: () => request<UserSummary[]>('/api/users'),

  conversations: () => request<ConversationSummary[]>('/api/conversations'),

  messages: (conversationId: string, before?: string) => {
    const query = new URLSearchParams({ limit: '50' });
    if (before) query.set('before', before);

    return request<MessagePageResponse>(`/api/conversations/${conversationId}/messages?${query}`);
  },

  sendMessage: (conversationId: string, clientMessageId: string, content: string) =>
    request<{ id: string }>(`/api/conversations/${conversationId}/messages`, {
      method: 'POST',
      body: JSON.stringify({ client_message_id: clientMessageId, content }),
    }),

  createDirect: (peerId: string) =>
    request<{ id: string }>('/api/conversations', {
      method: 'POST',
      body: JSON.stringify({ type: 'direct', member_ids: [peerId] }),
    }),

  createGroup: (title: string, memberIds: string[]) =>
    request<{ id: string }>('/api/conversations', {
      method: 'POST',
      body: JSON.stringify({ type: 'group', title, member_ids: memberIds }),
    }),

  addMembers: (conversationId: string, userIds: string[]) =>
    request<void>(`/api/conversations/${conversationId}/members`, {
      method: 'POST',
      body: JSON.stringify({ user_ids: userIds }),
    }),

  realtimeToken: () => request<RealtimeToken>('/api/realtime/token'),
};

export type { ApiMessage };
```

- [ ] **Step 4: Écrire l'écran de connexion**

```tsx
// frontend/src/ui/LoginScreen.tsx
import { useState, type FormEvent } from 'react';
import { api } from '../api/client';
import { ProblemError } from '../api/problem';
import type { Me } from '../api/types';

export function LoginScreen({ onAuthenticated }: { onAuthenticated: (me: Me) => void }) {
  const [username, setUsername] = useState('alice');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.login(username, password);
      onAuthenticated(await api.me());
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Connexion impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="mx-auto mt-24 flex w-80 flex-col gap-4">
      <h1 className="text-xl font-semibold">Connexion</h1>

      <input
        className="rounded border border-slate-300 px-3 py-2"
        value={username}
        onChange={(event) => setUsername(event.target.value)}
        placeholder="Identifiant"
        autoComplete="username"
      />
      <input
        className="rounded border border-slate-300 px-3 py-2"
        type="password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        placeholder="Mot de passe"
        autoComplete="current-password"
      />

      {error && <p role="alert" className="text-sm text-red-600">{error}</p>}

      <button
        type="submit"
        disabled={busy}
        className="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
      >
        {busy ? 'Connexion…' : 'Se connecter'}
      </button>
    </form>
  );
}
```

- [ ] **Step 5: Vérifier**

```bash
docker compose exec frontend npx vitest run
docker compose exec frontend npm run typecheck
```

- [ ] **Step 6: Commit**

```bash
git checkout -b feat/front-client-et-connexion
git add frontend/src
git commit -m "feat(frontend): client HTTP type, erreurs RFC 7807 et ecran de connexion"
```

---

## Task 14: Store — dédup, réconciliation et ordre

**Files:**
- Create: `frontend/src/store/messagesReducer.ts`, `frontend/src/store/messagesReducer.test.ts`

**Interfaces:**
- Produces:
  - `type StoredMessage = { id: string | null; clientMessageId: string; conversationId: string; senderId: string; content: string; createdAt: string; status: 'pending' | 'sent' | 'failed' }`
  - `messagesReducer(state, action): MessagesState`
  - Actions : `page/loaded`, `message/optimistic`, `message/acknowledged`, `message/failed`, `message/received`.
  - `selectThread(state, conversationId): { items: StoredMessage[]; nextBefore: string | null; loaded: boolean }`

C'est **la** tâche qui porte la logique testable du front. Tout le reste n'est que branchement.

- [ ] **Step 1: Écrire les tests du reducer (ils doivent échouer)**

```ts
// frontend/src/store/messagesReducer.test.ts
import { describe, expect, it } from 'vitest';
import {
  emptyMessagesState,
  messagesReducer,
  selectThread,
  type StoredMessage,
} from './messagesReducer';

const CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';

function serverMessage(id: string, clientMessageId: string, content = 'texte'): StoredMessage {
  return {
    id,
    clientMessageId,
    conversationId: CONVERSATION,
    senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
    content,
    createdAt: '2026-07-25T10:00:00+00:00',
    status: 'sent',
  };
}

describe('messagesReducer', () => {
  it('ordonne les messages par ULID croissant', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c1'),
    });
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'c2'),
    });

    expect(selectThread(state, CONVERSATION).items.map((m) => m.id)).toEqual([
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    ]);
  });

  it('ignore un message deja present, identifie par son id serveur', () => {
    let state = emptyMessagesState();
    const message = serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c1');

    state = messagesReducer(state, { type: 'message/received', message });
    state = messagesReducer(state, { type: 'message/received', message });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
  });

  it('remplace le message optimiste par le message serveur via client_message_id', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        status: 'pending',
      },
    });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
    expect(selectThread(state, CONVERSATION).items[0].status).toBe('pending');

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'client-1', 'bonjour'),
    });

    const items = selectThread(state, CONVERSATION).items;

    expect(items).toHaveLength(1);
    expect(items[0].id).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAC');
    expect(items[0].status).toBe('sent');
  });

  it('ne duplique pas quand l ACK HTTP et le SSE arrivent tous les deux', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        status: 'pending',
      },
    });

    // 1. La reponse HTTP arrive.
    state = messagesReducer(state, {
      type: 'message/acknowledged',
      conversationId: CONVERSATION,
      clientMessageId: 'client-1',
      serverId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    });

    // 2. Le meme message revient par SSE.
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'client-1', 'bonjour'),
    });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
  });

  it('insere une page ancienne en tete sans doublon', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c3'),
    });

    state = messagesReducer(state, {
      type: 'page/loaded',
      conversationId: CONVERSATION,
      // L'API renvoie du plus recent au plus ancien, et la page chevauche
      // volontairement le message deja recu par SSE.
      items: [
        serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c3'),
        serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'c2'),
      ],
      nextBefore: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
    });

    const thread = selectThread(state, CONVERSATION);

    expect(thread.items.map((m) => m.id)).toEqual([
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    ]);
    expect(thread.nextBefore).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAB');
  });

  it('marque un envoi en echec sans le supprimer', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        status: 'pending',
      },
    });

    state = messagesReducer(state, {
      type: 'message/failed',
      conversationId: CONVERSATION,
      clientMessageId: 'client-1',
    });

    // Le message reste affiche : l'utilisateur doit pouvoir reessayer,
    // et le meme client_message_id garantit l'absence de doublon serveur.
    expect(selectThread(state, CONVERSATION).items[0].status).toBe('failed');
  });

  it('garde les messages en attente en fin de liste', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'en attente',
        createdAt: '2026-07-25T10:00:00+00:00',
        status: 'pending',
      },
    });
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c9'),
    });

    const items = selectThread(state, CONVERSATION).items;

    expect(items[items.length - 1].status).toBe('pending');
  });
});
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec frontend npx vitest run src/store/messagesReducer.test.ts
```

- [ ] **Step 3: Écrire le reducer**

```ts
// frontend/src/store/messagesReducer.ts

/**
 * Reducer pur : aucune dependance a React, donc testable en appelant une fonction.
 *
 * Deux invariants portent toute la complexite :
 *  - la deduplication se fait en DEUX passes (client_message_id puis id serveur),
 *    parce que l'expediteur recoit son propre message par la reponse HTTP ET par SSE ;
 *  - les messages sont tries par ULID croissant, les envois en attente restant
 *    en fin de liste puisqu'ils n'ont pas encore d'identifiant serveur.
 */

export type MessageStatus = 'pending' | 'sent' | 'failed';

export type StoredMessage = {
  id: string | null;
  clientMessageId: string;
  conversationId: string;
  senderId: string;
  content: string;
  createdAt: string;
  status: MessageStatus;
};

export type Thread = {
  items: StoredMessage[];
  nextBefore: string | null;
  loaded: boolean;
};

export type MessagesState = { threads: Record<string, Thread> };

export type MessagesAction =
  | { type: 'page/loaded'; conversationId: string; items: StoredMessage[]; nextBefore: string | null }
  | { type: 'message/optimistic'; message: StoredMessage }
  | { type: 'message/acknowledged'; conversationId: string; clientMessageId: string; serverId: string }
  | { type: 'message/failed'; conversationId: string; clientMessageId: string }
  | { type: 'message/received'; message: StoredMessage };

const EMPTY_THREAD: Thread = { items: [], nextBefore: null, loaded: false };

export function emptyMessagesState(): MessagesState {
  return { threads: {} };
}

export function selectThread(state: MessagesState, conversationId: string): Thread {
  return state.threads[conversationId] ?? EMPTY_THREAD;
}

/** Les messages en attente n'ont pas d'id serveur : ils passent toujours en dernier. */
function compare(a: StoredMessage, b: StoredMessage): number {
  if (a.id === null && b.id === null) return a.clientMessageId.localeCompare(b.clientMessageId);
  if (a.id === null) return 1;
  if (b.id === null) return -1;

  return a.id.localeCompare(b.id);
}

function upsert(items: StoredMessage[], incoming: StoredMessage): StoredMessage[] {
  // Passe 1 : le message correspond-il a un envoi optimiste en cours ?
  const byClientId = items.findIndex((item) => item.clientMessageId === incoming.clientMessageId);

  if (byClientId !== -1) {
    const merged = [...items];
    merged[byClientId] = { ...incoming, status: 'sent' };

    return merged.sort(compare);
  }

  // Passe 2 : deja recu par un autre canal ?
  if (incoming.id !== null && items.some((item) => item.id === incoming.id)) {
    return items;
  }

  return [...items, incoming].sort(compare);
}

function patchThread(
  state: MessagesState,
  conversationId: string,
  patch: (thread: Thread) => Thread,
): MessagesState {
  const current = state.threads[conversationId] ?? EMPTY_THREAD;

  return { threads: { ...state.threads, [conversationId]: patch(current) } };
}

export function messagesReducer(state: MessagesState, action: MessagesAction): MessagesState {
  switch (action.type) {
    case 'page/loaded':
      return patchThread(state, action.conversationId, (thread) => ({
        items: action.items.reduce(upsert, thread.items),
        nextBefore: action.nextBefore,
        loaded: true,
      }));

    case 'message/optimistic':
    case 'message/received':
      return patchThread(state, action.message.conversationId, (thread) => ({
        ...thread,
        items: upsert(thread.items, action.message),
      }));

    case 'message/acknowledged':
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items
          .map((item) =>
            item.clientMessageId === action.clientMessageId
              ? { ...item, id: action.serverId, status: 'sent' as const }
              : item,
          )
          .sort(compare),
      }));

    case 'message/failed':
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.clientMessageId === action.clientMessageId
            ? { ...item, status: 'failed' as const }
            : item,
        ),
      }));
  }
}
```

- [ ] **Step 4: Vérifier que les 7 tests passent**

```bash
docker compose exec frontend npx vitest run src/store/messagesReducer.test.ts
docker compose exec frontend npm run typecheck
```

- [ ] **Step 5: Commit**

```bash
git checkout -b feat/front-store-messages
git add frontend/src/store
git commit -m "feat(frontend): reducer de messages, dedup en deux passes et ordre ULID"
```

---

## Task 15: RealtimeClient — propriétaire unique de l'EventSource

**Files:**
- Create: `frontend/src/realtime/RealtimeClient.ts`, `frontend/src/realtime/RealtimeClient.test.ts`

**Interfaces:**
- Consumes: `api.realtimeToken()` (tâche 13).
- Produces:
  - `new RealtimeClient({ fetchToken, createEventSource, onEvent, onError?, refreshMarginMs? })`
  - `start(): Promise<void>`, `stop(): void`, `resubscribe(): Promise<void>`
  - **Invariant garanti et testé : jamais deux `EventSource` ouverts simultanément.**

- [ ] **Step 1: Écrire les tests (ils doivent échouer)**

```ts
// frontend/src/realtime/RealtimeClient.test.ts
import { describe, expect, it, vi } from 'vitest';
import { RealtimeClient, type EventSourceLike } from './RealtimeClient';

/** Double de test : un EventSource observable, sans reseau ni DOM. */
class FakeEventSource implements EventSourceLike {
  static instances: FakeEventSource[] = [];

  onmessage: ((event: { data: string; lastEventId: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this);
  }

  close(): void {
    this.closed = true;
  }

  emit(payload: unknown, id = 'evt-1'): void {
    this.onmessage?.({ data: JSON.stringify(payload), lastEventId: id });
  }
}

function build(overrides: Partial<Parameters<typeof RealtimeClient.prototype.constructor>[0]> = {}) {
  FakeEventSource.instances = [];

  const onEvent = vi.fn();
  const fetchToken = vi.fn().mockResolvedValue({
    hub_url: 'http://localhost:8080/.well-known/mercure',
    topics: ['/conversations/A', '/users/U/system'],
  });

  const client = new RealtimeClient({
    fetchToken,
    createEventSource: (url: string) => new FakeEventSource(url),
    onEvent,
    ...overrides,
  });

  return { client, onEvent, fetchToken };
}

describe('RealtimeClient', () => {
  it('demande un token puis souscrit a tous les topics autorises', async () => {
    const { client, fetchToken } = build();

    await client.start();

    expect(fetchToken).toHaveBeenCalledTimes(1);

    const url = new URL(FakeEventSource.instances[0].url);

    expect(url.pathname).toBe('/.well-known/mercure');
    expect(url.searchParams.getAll('topic')).toEqual(['/conversations/A', '/users/U/system']);

    client.stop();
  });

  it('n ouvre jamais deux connexions simultanement', async () => {
    const { client } = build();

    // StrictMode monte les effets deux fois en developpement : sans garde,
    // on se retrouverait avec deux flux et chaque message compte double.
    await client.start();
    await client.start();

    const open = FakeEventSource.instances.filter((source) => !source.closed);

    expect(open).toHaveLength(1);

    client.stop();
  });

  it('transmet les evenements decodes', async () => {
    const { client, onEvent } = build();

    await client.start();

    FakeEventSource.instances[0].emit({
      type: 'message.created',
      payload: { id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB' },
    });

    expect(onEvent).toHaveBeenCalledWith({
      type: 'message.created',
      payload: { id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB' },
    });

    client.stop();
  });

  it('ignore une charge utile illisible sans casser le flux', async () => {
    const { client, onEvent } = build();

    await client.start();

    FakeEventSource.instances[0].onmessage?.({ data: 'pas du json', lastEventId: 'evt-1' });

    expect(onEvent).not.toHaveBeenCalled();
    expect(FakeEventSource.instances[0].closed).toBe(false);

    client.stop();
  });

  it('ferme l ancienne connexion avant d en ouvrir une nouvelle a la resouscription', async () => {
    const { client, fetchToken } = build();

    await client.start();
    // Cas declencheur : quelqu un vient de nous ajouter a un groupe, donc le
    // JWT courant n autorise pas encore son topic.
    await client.resubscribe();

    expect(fetchToken).toHaveBeenCalledTimes(2);
    expect(FakeEventSource.instances).toHaveLength(2);
    expect(FakeEventSource.instances[0].closed).toBe(true);
    expect(FakeEventSource.instances.filter((source) => !source.closed)).toHaveLength(1);

    client.stop();
  });

  it('stop ferme la connexion et empeche toute reouverture', async () => {
    const { client } = build();

    await client.start();
    client.stop();

    expect(FakeEventSource.instances[0].closed).toBe(true);

    FakeEventSource.instances[0].onerror?.();

    expect(FakeEventSource.instances).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec frontend npx vitest run src/realtime/RealtimeClient.test.ts
```

- [ ] **Step 3: Écrire le client**

```ts
// frontend/src/realtime/RealtimeClient.ts
import type { RealtimeToken } from '../api/types';

export type EventSourceLike = {
  onmessage: ((event: { data: string; lastEventId: string }) => void) | null;
  onerror: (() => void) | null;
  close(): void;
};

export type RealtimeEvent = { type: string; payload: Record<string, unknown> };

type Options = {
  fetchToken: () => Promise<RealtimeToken>;
  createEventSource: (url: string) => EventSourceLike;
  onEvent: (event: RealtimeEvent) => void;
  onError?: (cause: unknown) => void;
  /** Le JWT vit 15 min ; on le renouvelle avant, pour ne jamais subir l'expiration. */
  refreshIntervalMs?: number;
};

/**
 * Unique proprietaire de l'EventSource de l'application.
 *
 * Centraliser la propriete de la connexion est ce qui rend verifiable
 * l'invariant "jamais deux flux ouverts" : en React, l'ouverture serait
 * dispersee dans des effets qui se remontent (StrictMode) et se recreent.
 */
export class RealtimeClient {
  private source: EventSourceLike | null = null;
  private timer: ReturnType<typeof setInterval> | null = null;
  private stopped = false;
  /** Serialise start/resubscribe : deux appels concurrents ne peuvent pas ouvrir deux flux. */
  private pending: Promise<void> = Promise.resolve();

  constructor(private readonly options: Options) {}

  start(): Promise<void> {
    this.stopped = false;

    return this.enqueue(async () => {
      if (this.source !== null) {
        return;
      }

      await this.open();
      this.scheduleRefresh();
    });
  }

  /** A appeler apres avoir cree ou rejoint une conversation, ou sur membership.changed. */
  resubscribe(): Promise<void> {
    return this.enqueue(async () => {
      if (this.stopped) {
        return;
      }

      this.closeSource();
      await this.open();
    });
  }

  stop(): void {
    this.stopped = true;
    this.closeSource();

    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  private enqueue(task: () => Promise<void>): Promise<void> {
    this.pending = this.pending.then(task, task);

    return this.pending;
  }

  private async open(): Promise<void> {
    const token = await this.options.fetchToken();

    if (this.stopped) {
      return;
    }

    const url = new URL(token.hub_url);
    for (const topic of token.topics) {
      url.searchParams.append('topic', topic);
    }

    const source = this.options.createEventSource(url.toString());

    source.onmessage = (event) => {
      try {
        const parsed = JSON.parse(event.data) as RealtimeEvent;
        this.options.onEvent(parsed);
      } catch (cause) {
        // Une charge utile illisible ne doit pas tuer le flux : on la jette.
        this.options.onError?.(cause);
      }
    };

    source.onerror = () => {
      if (this.stopped) {
        return;
      }

      // Le navigateur reconnecte seul un EventSource. On ne rouvre donc pas
      // ici : le faire creerait exactement le second flux qu'on veut interdire.
      this.options.onError?.(new Error('Flux temps reel interrompu'));
    };

    this.source = source;
  }

  private scheduleRefresh(): void {
    if (this.timer !== null) {
      return;
    }

    this.timer = setInterval(
      () => void this.resubscribe(),
      this.options.refreshIntervalMs ?? 13 * 60 * 1000,
    );
  }

  private closeSource(): void {
    this.source?.close();
    this.source = null;
  }
}
```

- [ ] **Step 4: Vérifier**

```bash
docker compose exec frontend npx vitest run
docker compose exec frontend npm run typecheck
```

- [ ] **Step 5: Commit**

```bash
git checkout -b feat/front-realtime-client
git add frontend/src/realtime
git commit -m "feat(frontend): RealtimeClient, proprietaire unique de l'EventSource"
```

---

## Task 16: Écrans — liste, historique et scroll

**Files:**
- Create: `frontend/src/hooks/useAppState.ts`, `frontend/src/ui/ConversationList.tsx`, `ConversationView.tsx`, `MessageList.tsx`, `useScrollAnchor.ts`
- Modify: `frontend/src/App.tsx`

**Interfaces:**
- Consumes: `api` (13), `messagesReducer` (14), `RealtimeClient` (15).
- Produces: application à deux colonnes ; l'historique se charge par pages de 50 en remontant, sans saut de scroll ; les messages reçus par SSE s'affichent immédiatement.

- [ ] **Step 1: Écrire le hook de restauration de scroll**

```ts
// frontend/src/ui/useScrollAnchor.ts
import { useLayoutEffect, useRef } from 'react';

/**
 * Insérer une page plus ancienne EN TETE de la liste augmente la hauteur totale
 * du conteneur : sans correction, le contenu visible saute vers le bas et
 * l'utilisateur perd sa place. On memorise la hauteur avant l'insertion et on
 * decale le scroll de la difference apres, ce qui donne l'illusion que rien
 * n'a bouge. C'est le bug classique de toute messagerie.
 *
 * useLayoutEffect et non useEffect : la correction doit avoir lieu avant que
 * le navigateur peigne la frame, sinon le saut reste visible.
 */
export function useScrollAnchor(container: React.RefObject<HTMLElement | null>, dependency: number) {
  const previousHeight = useRef(0);

  useLayoutEffect(() => {
    const element = container.current;
    if (!element) return;

    const delta = element.scrollHeight - previousHeight.current;

    if (previousHeight.current !== 0 && delta > 0) {
      element.scrollTop += delta;
    }

    previousHeight.current = element.scrollHeight;
  }, [container, dependency]);
}
```

- [ ] **Step 2: Écrire l'état applicatif**

`useAppState` expose : `me`, `conversations`, `selectedId`, `messagesState`, et les actions `selectConversation`, `loadOlder`, `send`, `refreshConversations`. Il possède l'instance de `RealtimeClient`, la démarre dans un `useEffect` et l'arrête au démontage.

```ts
// extrait de frontend/src/hooks/useAppState.ts — cablage du temps reel
useEffect(() => {
  const client = new RealtimeClient({
    fetchToken: api.realtimeToken,
    createEventSource: (url) => new EventSource(url, { withCredentials: true }),
    onEvent: (event) => {
      if (event.type === 'message.created') {
        dispatch({ type: 'message/received', message: toStoredMessage(event.payload) });
        void refreshConversations();
        return;
      }

      if (event.type === 'membership.changed') {
        // Quelqu un nous a ajoute (ou retire) : notre JWT ne couvre pas encore
        // ce topic. On en redemande un et on rouvre le flux.
        void client.resubscribe();
        void refreshConversations();
      }
    },
  });

  void client.start();
  clientRef.current = client;

  return () => client.stop();
}, []);
```

- [ ] **Step 3: Écrire les composants**

`ConversationList` : colonne de gauche, une ligne par conversation avec titre (ou nom de l'interlocuteur pour un direct), aperçu du dernier message et horodatage ; ligne active surlignée.

`MessageList` : conteneur `overflow-y-auto` portant `useScrollAnchor` ; déclenche `loadOlder()` quand `scrollTop < 100` et que `nextBefore` n'est pas `null` ; chaque message affiche l'expéditeur, le contenu, l'heure, et un indicateur discret pour `pending` (« envoi… ») et `failed` (« échec — réessayer »).

`ConversationView` : en-tête (titre, bouton membres pour les groupes), `MessageList`, puis `Composer` (tâche 17).

- [ ] **Step 4: Vérifier manuellement**

```bash
docker compose up -d
```

Ouvrir `http://localhost:8080/`, se connecter en `alice`, ouvrir une conversation, remonter l'historique. Attendu : le scroll ne saute pas.

- [ ] **Step 5: Commit**

```bash
git checkout -b feat/front-ecrans-historique
git add frontend/src
git commit -m "feat(frontend): liste, vue de conversation et historique sans saut de scroll"
```

---

## Task 17: Envoi optimiste, création de conversation et membres

**Files:**
- Create: `frontend/src/ui/Composer.tsx`, `NewConversationDialog.tsx`, `MembersPanel.tsx`
- Create: `frontend/src/api/retry.ts`, `frontend/src/api/retry.test.ts`
- Modify: `frontend/src/hooks/useAppState.ts`

**Interfaces:**
- Produces: envoi optimiste avec `client_message_id` généré côté client, retry à backoff exponentiel et jitter, création de directs et de groupes, ajout de membres.

- [ ] **Step 1: Écrire le test du backoff (il doit échouer)**

```ts
// frontend/src/api/retry.test.ts
import { describe, expect, it, vi } from 'vitest';
import { retryWithBackoff } from './retry';

describe('retryWithBackoff', () => {
  it('reussit sans attendre si le premier essai passe', async () => {
    const sleep = vi.fn().mockResolvedValue(undefined);
    const task = vi.fn().mockResolvedValue('ok');

    await expect(retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 })).resolves.toBe('ok');
    expect(sleep).not.toHaveBeenCalled();
  });

  it('reessaie avec des delais croissants', async () => {
    const delays: number[] = [];
    const sleep = vi.fn(async (ms: number) => {
      delays.push(ms);
    });

    const task = vi
      .fn()
      .mockRejectedValueOnce(new Error('reseau'))
      .mockRejectedValueOnce(new Error('reseau'))
      .mockResolvedValue('ok');

    await expect(retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 })).resolves.toBe('ok');

    expect(delays).toHaveLength(2);
    expect(delays[1]).toBeGreaterThan(delays[0]);
  });

  it('propage l erreur apres epuisement des tentatives', async () => {
    const task = vi.fn().mockRejectedValue(new Error('reseau'));

    await expect(
      retryWithBackoff(task, { attempts: 2, sleep: async () => {}, random: () => 0.5 }),
    ).rejects.toThrow('reseau');

    expect(task).toHaveBeenCalledTimes(2);
  });
});
```

- [ ] **Step 2: Lancer et vérifier l'échec**

```bash
docker compose exec frontend npx vitest run src/api/retry.test.ts
```

- [ ] **Step 3: Écrire le backoff**

```ts
// frontend/src/api/retry.ts
type Options = {
  attempts: number;
  baseDelayMs?: number;
  sleep?: (ms: number) => Promise<void>;
  random?: () => number;
};

/**
 * Backoff exponentiel avec jitter.
 *
 * Le jitter n'est pas cosmetique : sans lui, tous les clients deconnectes par
 * la meme coupure reessaient exactement au meme instant et achevent le serveur
 * qui vient de revenir (thundering herd).
 *
 * Rejouer est sans danger : le meme client_message_id est reutilise, donc le
 * serveur renvoie le message existant au lieu d'en creer un second.
 */
export async function retryWithBackoff<T>(task: () => Promise<T>, options: Options): Promise<T> {
  const base = options.baseDelayMs ?? 300;
  const sleep = options.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
  const random = options.random ?? Math.random;

  let lastError: unknown;

  for (let attempt = 0; attempt < options.attempts; attempt++) {
    try {
      return await task();
    } catch (cause) {
      lastError = cause;

      if (attempt === options.attempts - 1) break;

      const exponential = base * 2 ** attempt;
      await sleep(exponential * (0.5 + random() * 0.5));
    }
  }

  throw lastError;
}
```

- [ ] **Step 4: Écrire l'envoi optimiste**

```ts
// extrait de frontend/src/hooks/useAppState.ts
import { ulid } from 'ulid';

async function send(conversationId: string, content: string): Promise<void> {
  // L'identifiant est genere AVANT le premier envoi et reutilise a l'identique
  // a chaque tentative : c'est la cle d'idempotence.
  const clientMessageId = ulid();

  dispatch({
    type: 'message/optimistic',
    message: {
      id: null,
      clientMessageId,
      conversationId,
      senderId: me.id,
      content,
      createdAt: new Date().toISOString(),
      status: 'pending',
    },
  });

  try {
    const { id } = await retryWithBackoff(
      () => api.sendMessage(conversationId, clientMessageId, content),
      { attempts: 3 },
    );

    dispatch({ type: 'message/acknowledged', conversationId, clientMessageId, serverId: id });
  } catch {
    dispatch({ type: 'message/failed', conversationId, clientMessageId });
  }
}
```

- [ ] **Step 5: Écrire les composants**

`Composer` : `textarea` + bouton ; `Entrée` envoie, `Maj+Entrée` insère un saut de ligne ; champ vidé optimistiquement ; désactivé si le contenu est vide après `trim`.

`NewConversationDialog` : liste des utilisateurs issue de `api.users()` (moins soi-même) ; une seule sélection → `createDirect`, plusieurs → `createGroup` avec un titre obligatoire. **Après création, appeler `client.resubscribe()`** : sans cela, le nouveau topic n'est pas dans le JWT courant et les messages n'arriveraient pas en direct.

`MembersPanel` : visible pour les groupes ; liste les membres, permet d'en ajouter via `api.addMembers`.

- [ ] **Step 6: Vérification manuelle du critère d'acceptation principal**

Deux navigateurs (ou une fenêtre privée). Alice dans l'un, Bob dans l'autre.

1. Alice envoie un message → Bob le voit **sans rafraîchir**.
2. Alice crée un groupe et y ajoute Carol → Carol le voit apparaître **sans rafraîchir**.
3. Couper le réseau dans les outils de développement, envoyer, rétablir → un seul message affiché, un seul en base.

```bash
docker compose exec postgres psql -U app -d app -c 'SELECT count(*) FROM messages'
```

- [ ] **Step 7: Commit**

```bash
git checkout -b feat/front-envoi-et-creation
git add frontend/src
git commit -m "feat(frontend): envoi optimiste idempotent, creation de conversations et membres"
```

---

## Task 18: Intégration continue

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: CI par chemin exécutant la même chose que le poste de développement.

- [ ] **Step 1: Écrire le workflow**

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches-ignore: [main]
  pull_request:

jobs:
  backend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Lever les services
        run: docker compose up -d --build backend postgres
        env:
          MERCURE_JWT_SECRET: ci-secret-de-32-caracteres-minimum

      - name: Installer les dependances
        run: docker compose exec -T backend composer install --no-interaction

      - name: Migrations
        run: docker compose exec -T -e APP_ENV=test backend bin/console doctrine:migrations:migrate --no-interaction

      - name: Fixtures
        run: docker compose exec -T -e APP_ENV=test backend bin/console app:fixtures:load

      - name: Tests
        run: docker compose exec -T backend vendor/bin/phpunit

      - name: Architecture
        run: |
          docker compose exec -T backend vendor/bin/deptrac analyse --fail-on-uncovered
          docker compose exec -T backend vendor/bin/deptrac analyse -c deptrac-contexts.yaml --fail-on-uncovered

      - name: Analyse statique
        run: docker compose exec -T backend vendor/bin/phpstan analyse --no-progress

      - name: Style
        run: docker compose exec -T backend vendor/bin/php-cs-fixer fix --dry-run --diff

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Lever le service
        run: docker compose up -d --build frontend

      - name: Types
        run: docker compose exec -T frontend npm run typecheck

      - name: Tests
        run: docker compose exec -T frontend npx vitest run
```

Pas de `setup-php` ni de `setup-node` : les mêmes images qu'en local, donc les mêmes versions. `--fail-on-uncovered` sur deptrac garantit qu'un nouveau dossier non classé fait échouer le build au lieu d'échapper silencieusement aux règles.

- [ ] **Step 2: Vérifier localement l'équivalent**

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend vendor/bin/deptrac analyse --fail-on-uncovered
docker compose exec backend vendor/bin/phpstan analyse
docker compose exec backend vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec frontend npm run typecheck
docker compose exec frontend npx vitest run
```

Attendu : tout vert. C'est le critère d'acceptation 6 de la spec.

- [ ] **Step 3: Commit**

```bash
git checkout -b chore/ci
git add .github
git commit -m "chore(ci): workflow par chemin dans les memes conteneurs qu'en local"
```

---

## Critères d'acceptation de la tranche

Repris de la spec, à vérifier une fois les 18 tâches terminées.

| # | Critère | Vérifié par |
|---|---|---|
| 1 | Les services se lèvent, la base est jouable | Tâches 1 et 5 |
| 2 | Alice envoie, Bob voit sans rafraîchir | Tâche 17, étape 6 |
| 3 | Carol voit apparaître un groupe sans rafraîchir | Tâche 17, étape 6 (chemin `membership.changed`) |
| 4 | Coupure réseau : un seul message en base | Tâches 10 et 17 |
| 5 | Historique de 200 messages, ni trou ni doublon | Tâche 11 |
| 6 | Toute la chaîne qualité est verte | Tâche 18 |

## Ce qui reste explicitement hors périmètre

Accusés distribué/lu, présence, typing · édition, suppression · médias · recherche · rate limiting, modération · E2E · reprise `Last-Event-ID` · OAuth · déploiement. Chacun a sa tranche.
