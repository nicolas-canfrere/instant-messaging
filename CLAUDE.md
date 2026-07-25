# Instant Messaging

Messagerie instantanée PHP/Symfony + Mercure + React. Projet **portfolio** : la qualité
d'architecture et de code prime sur la vitesse de livraison.

Conception : `docs/superpowers/specs/`. Concepts et raisonnements : vault Obsidian
`~/Documents/obsidian_vaults/tech/InstantMessaging`.

## Règles absolues

- **Jamais de commit sur `main`.** Toujours une branche, même pour une ligne.
  `feat/<story>` · `fix/<sujet>` · `docs/<sujet>` · `chore/<sujet>`
- **Ni PHP ni Node.js ne sont installés sur la machine.** `php`, `composer`, `node`, `npm`,
  `vendor/bin/*` n'existent **que dans les conteneurs**. Ne jamais les invoquer directement :
  passer par `make` ou `docker compose run --rm <service> <cmd>`. Une commande écrite dans une
  story ou dans la doc doit être exécutable telle quelle.
- **Ne pas bootstraper le projet Symfony ni installer de paquets Composer.** C'est Nicolas
  qui s'en charge. Signaler les paquets manquants, ne pas les installer.
- **`Domain/` ne dépend de rien** — ni Symfony, ni Doctrine. Seule exception whitelistée :
  `symfony/uid`. `deptrac` échoue le build en cas de violation.

## Infrastructure

5 conteneurs : `caddy` (origine unique, `localhost:8080`) · `backend` (FrankenPHP) ·
`frontend` (Vite) · `mercure` · `postgres`.

Le hub Mercure reste un **service séparé** — c'est la leçon centrale du projet, on ne le
fusionne pas dans FrankenPHP. Et le **worker mode de FrankenPHP est désactivé** : un process
par requête, pour rester cohérent avec la prémisse shared-nothing qui justifie l'existence
même du hub.

## Architecture

Hexagonale par contexte borné, CQS, value objects systématiques.

```
backend/src/<Contexte>/
├── Domain/           # PHP pur : entités, VO, ports, domain events
├── Application/      # Command/ et Query/ + leurs handlers
└── Infrastructure/   # Http/, Persistence/ — les adaptateurs
```

Contextes : `Identity` · `Conversation` · `Message` · `Realtime` · `Shared`.
Ils communiquent **par identifiants**, jamais en référençant le `Domain` d'un autre contexte.

Règle de dépendance : `Infrastructure` → `Application` → `Domain`. Jamais l'inverse.

### Persistance : DBAL, jamais l'ORM

`doctrine/dbal` + `doctrine/migrations`. **`doctrine/orm` n'est pas installé et ne doit pas
l'être.** Repositories écrits à la main, mappers explicites `fromRow()` / `toRow()`,
migrations en SQL explicite.

**SQL pur, pas de `QueryBuilder`.** Requêtes littérales passées à `executeQuery()` /
`executeStatement()`. On assume PostgreSQL : `ON CONFLICT`, `RETURNING`, index partiels sont
les bienvenus, aucune portabilité recherchée.

- **Toujours des paramètres liés**, jamais de concaténation de valeurs.
- Listes `IN (...)` : `ArrayParameterType` de DBAL, ne pas générer les placeholders à la main.
- Chaque requête vit dans le repository ou la classe de query qui l'utilise.
- Le mapper est le point unique où la ligne brute devient un type précis (PHPStan `max`).
- Idempotence : `ON CONFLICT … DO NOTHING RETURNING id`, pas d'exception rattrapée.

### CQS (pas CQRS)

| | Écriture | Lecture |
|---|---|---|
| Bus | `command.bus` | `query.bus` |
| Chemin | domaine + repository | SQL direct → DTO de lecture |
| Retour | rien, ou l'identifiant créé | DTO |

Une seule base, pas de read model séparé, pas d'event sourcing.

### Nommage (backend)

**Conventions Symfony**, sans exception :
<https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions>

Interfaces suffixées `Interface` (`MessageRepositoryInterface` — **pas** l'usage DDD sans
suffixe), classes abstraites préfixées `Abstract`, traits suffixés `Trait`, exceptions
suffixées `Exception`. Cas d'enum en `UpperCamelCase` (`ConversationType::Direct`).
Constantes en `SCREAMING_SNAKE_CASE`. Noms de routes et paramètres de config en
`snake_case`. PHPDoc : `bool`/`int`/`float`. Une classe par fichier.

Le frontend suit les usages TypeScript/React, pas ceux de Symfony.

### Value objects

Pas de primitive obsession. Les identifiants (`UserId`, `ConversationId`, `MessageId`) sont
des types **non interchangeables**. Les invariants vivent dans le VO (`MessageContent`
valide sa longueur), pas dans le contrôleur. Les topics Mercure se construisent via
`Topic::conversation()` / `Topic::userSystem()`, jamais par concaténation de chaînes.

### Domain events

Enregistrés sur l'agrégat, dispatchés **après le commit** par le middleware transactionnel
du `command.bus`. Publier dans la transaction pousserait aux clients des messages qu'un
rollback ferait disparaître.

## Frontend

React + TypeScript + Vite + Tailwind. La logique vit **hors de React** :

- `store/` — reducer pur : dédup, réconciliation optimiste, ordre ULID
- `realtime/` — `RealtimeClient`, seul propriétaire de l'`EventSource`
- `api/` — client HTTP typé
- `hooks/` puis `ui/` — liaison React et composants, aussi bêtes que possible

Nicolas est novice côté front : **commenter généreusement** les points non évidents (cycle de
vie de l'`EventSource`, dédup, restauration du scroll) et expliquer le *pourquoi*.

## Workflow

- **Petits commits, beaucoup de user stories étroites.** Une story = une branche = 1 à 3
  commits relisibles. « Créer un direct » et « créer un groupe » sont deux stories.
- Chaque story laisse le dépôt vert. Une story qui ne peut pas être verte seule est mal
  découpée.
- Commits conventionnels, en français, à l'impératif.
- TDD : le test qui décrit le comportement avant le code.

## Qualité

PHPStan **niveau `max`**, PHP-CS-Fixer, deptrac (zéro violation). Les trois sont en
**`require-dev`** dans `composer.json`, comme PHPUnit, et s'exécutent depuis `vendor/bin/`
**dans le conteneur backend**.

Les configs PHPStan et PHP-CS-Fixer sont à la charge de Nicolas — **ne pas les modifier**,
et ne jamais ajouter de `baseline` ni d'`@phpstan-ignore` pour faire passer du code. La
config deptrac se décide à deux.

Niveau `max` : annoter les génériques (`@return list<MessageView>`), typer précisément les
lignes DBAL (`array{id: string, …}`), aucun `mixed` implicite. Les mappers sont la frontière
désignée où le tableau brut devient un type précis.

La CI lance `make qa` dans les mêmes conteneurs que le poste de dev — pas de `setup-php`.

## Commandes

Toutes passent par des conteneurs (voir règles absolues).

Le `Makefile` est écrit en partie par Nicolas : **le lire avant d'écrire une commande**, et
utiliser les cibles qui existent réellement. Ne pas inventer de cible ni supposer un nom.
S'il manque une cible, passer par `docker compose run --rm <service> <cmd>` et le signaler.

## Périmètre

Tranche 1 en cours : noyau temps réel + conversations. **Ne pas déborder** sur les tranches
suivantes (accusés de lecture, présence, édition/suppression, médias, recherche, modération)
— chacune aura sa spec. Voir la section « Hors périmètre » de la spec T1.
