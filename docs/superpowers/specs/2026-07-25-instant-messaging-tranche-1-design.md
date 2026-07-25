# Instant Messaging — Tranche 1 : noyau temps réel + conversations

> Source conceptuelle : vault Obsidian `tech/InstantMessaging`. Ce document ne réexplique pas les
> concepts — il fige les **décisions d'implémentation** et renvoie aux notes.

## Contexte

Mise en œuvre du system design étudié dans le vault. Objectif : **vitrine / portfolio** — qualité de
code, tests, architecture démontrable et défendable en entretien.

### Découpage en tranches

Les 17 notes du vault représentent plusieurs projets. Découpage retenu :

| Tranche | Contenu | Notes couvertes |
|---|---|---|
| **1 — Noyau + conversations** | Infra, auth locale, CRUD conversations, envoi/réception temps réel, ULID, idempotence | Stack, SSE vs WebSocket, Modèle de données, ULID, Idempotence, Groupes et fan-out, Organisation du code |
| 2 — Statuts & présence | Watermarks distribué/lu, typing, online (Redis TTL) | Accusés de réception et présence |
| 3 — Cycle de vie | Édition, soft-delete / tombstone, fuseaux horaires | Suppression et édition, Gestion du temps |
| 4 — Médias | MinIO, URLs pré-signées, unfurling, SSRF | Médias et aperçus, Dev local MinIO |
| 5 — Robustesse | Rate limiting, modération, recherche | Modération et rate limiting, Recherche |

**Ce document couvre la tranche 1 uniquement.** Chaque tranche aura sa propre spec et son plan.

### Choix transverses

| Sujet | Décision |
|---|---|
| Backend | Symfony 7 / PHP 8.4, **contrôleurs manuels** (pas d'API Platform : on veut voir le code, pas la config) |
| Persistance | **Doctrine DBAL uniquement, sans ORM** (section 3.5) |
| Architecture backend | **Hexagonale par contexte + CQS**, value objects systématiques (section 3) |
| Frontend | React + TypeScript, Vite, **Tailwind CSS** |
| Versionnement | **Monorepo**, un seul dépôt git (section 1.5) |
| Base | PostgreSQL 17 |
| Temps réel | Mercure (hub `dunglas/mercure`), SSE |
| Auth T1 | Login local (session Symfony), **conçue pour accueillir OAuth sans refonte** |
| Tests | PHPUnit unitaires (domaine + use cases) et fonctionnels (adaptateurs) ; Vitest côté front |

---

## Section 1 — Architecture & topologie

### Services (5 conteneurs)

Redis et MinIO sont **volontairement absents** : aucun usage en tranche 1 (présence → T2, médias → T4).

| Service | Image / base | Rôle | Exposé |
|---|---|---|---|
| `caddy` | `caddy` | Reverse proxy, **seule origine publique** | `localhost:8080` |
| `backend` | FrankenPHP, **mode classique** (section 1.4) | API métier | interne |
| `frontend` | `node` + Vite dev server | Client React | interne |
| `mercure` | `dunglas/mercure` | Hub temps réel | interne |
| `postgres` | `postgres:17` | Persistance | `5432` (confort outillage) |

### Origine unique

```
localhost:8080/                      → frontend:5173   (HMR websocket inclus)
localhost:8080/api/*                 → backend:80
localhost:8080/.well-known/mercure   → mercure:80
```

**Pourquoi une origine unique** : le JWT Mercure voyage dans un cookie ; les futurs `redirect_uri`
OAuth exigent une origine stable ; le CORS disparaît. Le hub reste un **service séparé** — la leçon
centrale de [[Stack - PHP + Mercure + JS]] est préservée (contrairement à FrankenPHP tout-en-un, qui
la masquerait).

### Piège « URL interne vs publique »

Le piège décrit dans [[Organisation du code (repo local)]] devient trivial à énoncer — deux variables
d'environnement distinctes :

| Variable | Valeur | Utilisateur |
|---|---|---|
| `MERCURE_PUBLISH_URL` | `http://mercure/.well-known/mercure` | le **backend** publie |
| `MERCURE_PUBLIC_URL` | `http://localhost:8080/.well-known/mercure` | le **navigateur** s'abonne |

### Arborescence du dépôt

```
instant-messaging/
├── docker-compose.yml
├── .env.example              # commité
├── .env.local                # gitignored
├── Makefile                  # make up / migrate / fixtures / test / qa
├── README.md
├── backend/
│   ├── Dockerfile
│   ├── src/                  # voir section 3
│   ├── migrations/
│   ├── tests/
│   └── deptrac.yaml
├── frontend/
│   ├── Dockerfile
│   └── src/                  # voir section 7
├── infra/
│   ├── caddy/Caddyfile
│   └── mercure/
└── docs/superpowers/specs/
```

### 1.4 Serveur PHP : FrankenPHP, sans worker mode

[[Organisation du code (repo local)]] présente FrankenPHP comme « un service de moins à câbler », parce
qu'il fusionne serveur PHP et hub Mercure. **Cette justification ne s'applique pas ici** : on a
délibérément gardé le hub dans son propre conteneur (section 1). L'argument d'origine tombe, il faut
donc en donner un autre — ou changer de serveur.

**Justification retenue** : un conteneur backend **autonome**, un seul binaire, aucune configuration
nginx. La paire `php-fpm` + `nginx` est du boilerplate que personne ne relit et où tout le monde
copie-colle ; s'en passer est un gain réel, indépendant de Mercure.

**Alternative écartée** : `php-fpm` nu, avec le Caddy de bord parlant FastCGI directement. Plus léger
d'un hop HTTP et d'un serveur web, mais le proxy de bord devrait monter le `public/` du backend en
volume partagé. Coupler le proxy au système de fichiers de l'application dégrade la lisibilité du
`docker-compose` pour un gain marginal.

> **Le worker mode est explicitement désactivé.**
>
> C'est pourtant l'argument principal de FrankenPHP : noyau Symfony persistant en mémoire, gain de
> performance important. Deux raisons de le refuser ici :
>
> 1. **Cohérence du propos.** Tout le raisonnement de [[Stack - PHP + Mercure + JS]] part de « PHP est
>    shared-nothing : requête → réponse → le process meurt », et c'est *de là* que découle la nécessité
>    d'un hub séparé. Un backend en worker persistant rendrait la démonstration incohérente.
> 2. **Risque sans contrepartie.** Le worker mode introduit les fuites d'état entre requêtes (services
>    stateful, `static`), classe de bugs coûteuse à diagnostiquer, pour un bénéfice de performance nul
>    à cette échelle.
>
> Un process par requête. Documenté comme un **choix**, pas comme une méconnaissance de l'outil.

### 1.5 Versionnement : un seul dépôt

**Monorepo.** Décision déjà posée par [[Organisation du code (repo local)]] et
[[Monorepo vs polyrepo (en équipe)]] ; la tranche 1 la confirme.

**Submodules git écartés.** Séparer `backend/` et `frontend/` en dépôts référencés par le parent
coûterait :

- tout changement de contrat front/back (un champ de message, un nouvel événement temps réel) passerait
  de une PR atomique à **trois commits dans trois dépôts** ;
- un submodule pointe un commit figé, pas une branche → oubli de bump et `clone` sans `--recursive`
  produisent un front qui parle à une API périmée, sans message d'erreur explicite ;
- objectif portfolio : un `git clone` suivi de `make up` doit suffire.

**Monorepo ≠ monolithe.** Backend, frontend et hub restent des services déployés indépendamment dans le
`docker-compose`. Organisation du code et architecture d'exécution sont deux axes distincts. Un split
se justifiera quand une couture stable aura son propre cycle de vie — service média (T4), client mobile
— pas avant.

### 1.6 Workflow git

**Règle absolue : aucun commit direct sur `main`.** Tout passe par une branche, même un changement
d'une ligne. `main` n'avance que par merge.

| Convention | Règle |
|---|---|
| Branches | `feat/<story>`, `fix/<sujet>`, `docs/<sujet>`, `chore/<sujet>` |
| Commits | conventionnels (`feat(message): …`), impératif, en français |
| Taille | **petits commits relisibles** — voir ci-dessous |
| Merge | `main` ne reçoit que des merges de branches |

**Petits commits, beaucoup de user stories.** Contrainte explicite du projet : préférer une story
étroite et complète à une story large. Une story = une branche = idéalement 1 à 3 commits, chacun
relisible d'une traite. Concrètement, en tranche 1 : « créer un direct » et « créer un groupe » sont
deux stories, pas une. « Envoyer un message » et « rendre l'envoi idempotent » sont deux stories, la
seconde arrivant avec son test de rejeu. Le plan d'implémentation découpera à ce grain.

Corollaire : chaque story doit laisser le dépôt dans un état vert (`make qa`). Une story qui ne peut pas
être verte seule est mal découpée.

`CODEOWNERS` et la branch protection GitHub viendront s'il y a des contributeurs — inutiles en solo, la
règle « pas de commit sur `main` » se tient à la discipline. La CI par chemin (`backend/**` ne déclenche
pas `vitest`) est en place dès T1.

### 1.7 Répartition des responsabilités

| Périmètre | Qui |
|---|---|
| Bootstrap Symfony + installation des paquets Composer | **Nicolas** |
| `Makefile` (une partie déjà posée) | **Nicolas** |
| Configuration PHPStan (niveau `max`) et PHP-CS-Fixer | **Nicolas** |
| Configuration deptrac | **à deux** |
| Code backend (domaine, use cases, adaptateurs, tests) | Claude |
| Frontend intégral (setup Vite compris) | Claude — Nicolas est novice sur cette partie |
| Infra Docker, Caddy, Mercure | Claude |
| Revue et validation | Nicolas |

Le plan d'implémentation démarre donc **après** le bootstrap Symfony et suppose les paquets déjà
installés. La liste exacte des paquets requis est fournie avec le plan.

Le frontend étant hors zone de confort de Nicolas, le code front doit être **commenté plus
généreusement que le back** sur les points non évidents (cycle de vie de l'`EventSource`, dédup du
store, restauration du scroll), et les revues front doivent expliquer le *pourquoi*, pas seulement le
*quoi*.

### 1.8 Tout s'exécute en conteneur

**Contrainte de la machine de développement : ni PHP ni Node.js ne sont installés sur l'hôte.**
`php`, `composer`, `node`, `npm`, `vendor/bin/*` n'existent pas hors conteneur. Aucune commande du
projet ne doit les invoquer directement.

**Le `Makefile` est la seule interface.** Chaque cible enveloppe un `docker compose`, ce qui rend la
contrainte invisible à l'usage.

> **Le `Makefile` est en partie écrit par Nicolas.** Cette spec ne fige donc **aucun nom de cible**.
> Avant d'écrire une commande dans une story ou dans la documentation, **lire le `Makefile`** et
> utiliser les cibles qui existent réellement. En l'absence de cible adaptée, passer par
> `docker compose run --rm <service> <cmd>` et signaler le manque plutôt qu'inventer une cible.

Conséquence sur la rédaction du plan et des stories : toute commande écrite dans la documentation ou
dans une story passe par `make` ou par `docker compose run`. Une story qui dirait « lancer
`vendor/bin/phpunit` » serait inexécutable telle quelle.

#### Outils qualité : en `require-dev`

**Décision** : PHPStan, PHP-CS-Fixer et deptrac sont des dépendances `require-dev` de l'application,
au même titre que PHPUnit. Ils s'exécutent depuis `vendor/bin/`, dans le conteneur backend.

| Outil | Paquet | Seuil | Configuration |
|---|---|---|---|
| PHPStan | `phpstan/phpstan` (+ `phpstan/phpstan-symfony`) | **niveau `max`** | **Nicolas** |
| PHP-CS-Fixer | `friendsofphp/php-cs-fixer` | zéro écart, `--dry-run` en CI | **Nicolas** (fichier de config dédié) |
| deptrac | `qossmic/deptrac` | zéro violation tolérée (section 3.8) | **à définir ensemble** |

**PHPStan au niveau `max`** — pas 8, le maximum. Conséquence directe sur la façon d'écrire le code, à
intégrer dès la première story plutôt qu'à subir ensuite : génériques annotés sur les collections
(`@return list<MessageView>`), types de tableaux précis pour les lignes DBAL
(`array{id: string, content: string, …}`), aucun `mixed` implicite. Le retour de
`Connection::fetchAssociative()` est typé très largement par DBAL : c'est le point qui produira le plus
de bruit au niveau `max`, et les mappers de la section 3.5 sont l'endroit désigné pour le canaliser —
une seule frontière où l'on passe du tableau brut au type précis, plutôt que des assertions dispersées.

La configuration de deptrac se fera à deux : c'est elle qui encode les couches et les contextes de la
section 3.1, donc elle mérite d'être écrite en même temps qu'on pose la première arborescence réelle.

L'objection classique — « ces outils tirent leurs propres versions de composants Symfony et contraignent
celles de l'application » — ne tient plus : `phpstan/phpstan` et `qossmic/deptrac` sont distribués en
PHAR scopé, sans dépendances. Seul PHP-CS-Fixer tire encore des composants Symfony, avec des contraintes
assez larges pour ne pas gêner.

Le bénéfice : les versions d'outillage sont dans `composer.lock`, versionnées et reproductibles avec le
reste, plutôt que dans une couche d'image Docker à maintenir séparément.

*Si* PHP-CS-Fixer venait un jour à bloquer une montée de version de Symfony, la sortie de secours est
`bamarni/composer-bin-plugin` (vendor isolé pour cet outil seul) — pas une refonte de l'outillage.

---

## Section 2 — Modèle de données

Fidèle à [[Modèle de données]] : 1-1 et groupes unifiés, ULID serveur, `created_at` conservé,
**fan-out de stockage en pull**.

```
users
  id ULID PK · username UNIQUE · display_name · email UNIQUE
  password_hash NULLABLE · provider ('local') · external_id NULLABLE
  created_at
  UNIQUE (provider, external_id)

conversations
  id ULID PK · type ('direct'|'group') · title NULLABLE · created_by FK
  direct_key NULLABLE UNIQUE                            ← unicité des 1-1
  last_message_id NULLABLE · last_message_at NULLABLE   ← pointeur dénormalisé
  created_at

conversation_members
  conversation_id FK · user_id FK · role ('member'|'admin') · joined_at
  PK (conversation_id, user_id)

messages
  id ULID PK (serveur) · conversation_id FK · sender_id FK
  content TEXT · client_message_id · created_at
  UNIQUE (sender_id, client_message_id)     ← idempotence
  INDEX (conversation_id, id DESC)          ← requête dominante
```

### Ajouts par rapport aux notes, et pourquoi

1. **`direct_key`** — sans lui, cliquer deux fois sur « écrire à Bob » crée deux conversations 1-1.
   Valeur = les deux `user_id` triés puis concaténés ; `NULL` pour les groupes. Postgres autorise
   plusieurs `NULL` dans un index unique, donc une seule colonne couvre les deux types.
2. **`last_message_id` / `last_message_at`** — le « pointeur dénormalisé » recommandé par
   [[Groupes et fan-out]] pour absorber le coût de l'écran d'accueil en pull. Mis à jour **dans la
   même transaction** que l'insert du message (voir section 3, « franchissement d'agrégat assumé »).

### Extensibilité OAuth

`password_hash` nullable + `provider` + `external_id` dès la première migration. Passer à OAuth =
ajouter un authenticator dans `security.yaml` (`knpuniversity/oauth2-client-bundle`). **Aucun**
changement d'entité de domaine, de use case ou de topic.

### Hors périmètre T1 (ajout par migration en T2/T3)

`last_read_message_id`, `last_delivered_message_id`, `last_active_at`, `deleted_at`, `edited_at`.
Ajouter une colonne est trivial ; coder une UI de watermarks à moitié ne l'est pas.

### Décision produit tranchée

**Un nouveau membre voit l'historique antérieur à son arrivée** (modèle Slack). La question était
laissée ouverte par [[Groupes et fan-out]]. Sinon il faudrait filtrer chaque requête d'historique par
`joined_at`, ce qui complique la pagination sans rien démontrer d'intéressant.

---

## Section 3 — Architecture applicative du backend

Contrainte : **architecture hexagonale, CQS, pas de primitive obsession.** Cette section fige comment
ces principes se traduisent concrètement, et où on les assouplit délibérément.

### 3.1 Découpage : hexagone par contexte

Quatre contextes, chacun avec ses trois couches, plus un noyau partagé.

```
backend/src/
├── Shared/
│   ├── Domain/           # Clock, IdGenerator (ports), exceptions de base
│   └── Infrastructure/   # bus Messenger, mapping problem+json, types Doctrine génériques
├── Identity/
├── Conversation/
├── Message/
└── Realtime/
```

Exemple complet, le contexte `Message` :

```
src/Message/
├── Domain/                      # PHP pur — zéro Symfony, zéro Doctrine
│   ├── Message.php
│   ├── MessageId.php  ClientMessageId.php  MessageContent.php
│   ├── MessageRepositoryInterface.php   (port secondaire)
│   ├── Event/MessageWasSent.php
│   └── Exception/EmptyMessageContentException.php …
├── Application/
│   ├── Command/SendMessage.php  SendMessageHandler.php
│   └── Query/GetMessagePage.php  GetMessagePageHandler.php  MessageView.php
└── Infrastructure/
    ├── Http/SendMessageController.php  GetMessagesController.php
    ├── Persistence/DbalMessageRepository.php  MessageMapper.php
    └── Persistence/SqlMessagePageQuery.php
```

**Règle de dépendance** : `Domain` ne dépend de rien. `Application` dépend de `Domain`.
`Infrastructure` dépend des deux. Jamais l'inverse.

### 3.2 Ports de l'hexagone

| Port | Type | Implémentation T1 |
|---|---|---|
| `ConversationRepositoryInterface`, `MessageRepositoryInterface`, `UserRepositoryInterface` | secondaire | Doctrine **DBAL** + mappers écrits à la main |
| `EventPublisherInterface` | secondaire | Mercure (`Realtime/Infrastructure`) |
| `IdGeneratorInterface` (port de `Domain`) | secondaire | ULID via `symfony/uid`, dans `Infrastructure` |
| `Psr\Clock\ClockInterface` (PSR-20, consommé par `Application`) | secondaire | `symfony/clock` |
| Contrôleurs HTTP → bus | primaire | Symfony |

L'horloge et le générateur d'identifiants en ports, ce n'est pas du dogmatisme : c'est ce qui rend les
tests **déterministes** — ULIDs fixes et temps gelé, indispensable pour tester l'ordre des messages et
la pagination keyset sans flakiness.

Les deux ne vivent pas au même endroit, et la nuance a des conséquences (section 3.5) :

- **`IdGeneratorInterface` est un port de `Domain`.** Que les identifiants soient générés par le
  serveur et triables par le temps est une contrainte métier ([[Modèle de données]], décision 2), pas
  un détail technique. L'interface est déclarée dans le domaine ; `symfony/uid` n'apparaît que dans
  l'implémentation, en `Infrastructure`.
- **L'horloge est consommée par `Application`.** Les entités reçoivent un `DateTimeImmutable` déjà
  résolu ; elles ne demandent jamais l'heure. On prend `Psr\Clock\ClockInterface` (PSR-20) plutôt qu'un
  port maison — l'interface standard existe, la réinventer n'apporterait rien — et `symfony/clock`
  fournit `MockClock` pour geler le temps en test.

### 3.3 CQS, pas CQRS

**Ce qu'on fait** : séparer les chemins de lecture et d'écriture.

| | Écriture | Lecture |
|---|---|---|
| Objet | `SendMessage` (command) | `GetMessagePage` (query) |
| Traverse le domaine | oui | **non** |
| Persistance | repository → DBAL + mapper | SQL direct via DBAL → DTO |
| Retour | rien (ou l'identifiant créé) | DTO de lecture (`MessageView`) |
| Bus | `command.bus` | `query.bus` |

**Ce qu'on ne fait pas** : CQRS. Une seule base, pas de read model séparé, pas d'event sourcing, pas
de projections asynchrones. La distinction est explicite dans le README — c'est précisément le genre
de nuance qu'un relecteur technique cherche. Le vrai read model apparaîtra en T5
([[Recherche dans l'historique]]).

**Pourquoi les queries contournent le domaine** : reconstruire des objets de domaine pour les
resérialiser en JSON est du gaspillage pur, et cela force le domaine à exposer des getters dont il n'a
pas besoin. Le SQL de lecture rend l'index `(conversation_id, id DESC)` visible dans le code.

**Deux bus Messenger, synchrones.** Le `command.bus` porte un middleware transactionnel **écrit pour le
projet** (`Connection::transactional()` de DBAL — le middleware `doctrine_transaction` de Symfony
suppose l'ORM, cf. section 3.5). Ce n'est pas décoratif : c'est ce qui garantit que l'insert du message
et la mise à jour du pointeur `last_message_*` sont dans la même transaction, sans un seul
`beginTransaction()` dans un handler.

### 3.4 Value objects — la fin de la primitive obsession

| VO | Invariant porté |
|---|---|
| `UserId` `ConversationId` `MessageId` | ULID valide ; **types non interchangeables** |
| `ClientMessageId` | format ULID/UUID valide, fourni par le client |
| `MessageContent` | non vide après trim, ≤ 4000 caractères |
| `DirectKey` | `DirectKey::forPair(UserId, UserId)` — trie en interne, donc commutatif par construction |
| `ConversationType` `MemberRole` | enums PHP |
| `Topic` | `Topic::conversation(ConversationId)` / `Topic::userSystem(UserId)` |

**Le gain concret, pas la théorie :**

- Passer un `ConversationId` là où on attend un `UserId` devient une **erreur de type**, pas un bug de
  production silencieux. Dans un modèle où tout est ULID, c'est loin d'être théorique.
- `MessageContent` porte la validation « non vide, ≤ 4000 » **une seule fois**. Impossible de
  construire un message invalide, quel que soit le point d'entrée.
- `DirectKey` est commutatif *par construction* — l'invariant vit dans le type, pas dans la discipline
  de l'appelant.
- `Topic` supprime la construction de chaînes `'/conversations/' . $id` disséminée. Une faute de frappe
  y serait un bug de sécurité silencieux : le message part sur un topic auquel personne n'est abonné,
  ou pire, sur un topic mal cloisonné.

La conversion VO ↔ colonne se fait dans les **mappers** de la couche Infrastructure (section 3.5), pas
dans des types Doctrine : le domaine ignore totalement la base.

### 3.5 Persistance : DBAL, sans ORM

**Décision** : `doctrine/dbal` et `doctrine/migrations`, **pas `doctrine/orm`**.

C'est ce qui rend l'hexagone réel plutôt qu'affiché. Avec l'ORM il aurait fallu du mapping XML pour
éviter les attributs `#[ORM\Entity]` dans `Domain/`, et des types Doctrine custom pour chaque VO —
c'est-à-dire beaucoup de configuration dont le seul objectif est de neutraliser le couplage que l'ORM
introduit. Sans ORM, le problème n'existe pas : les objets de domaine sont du PHP nu.

| Rôle | Composant | Emplacement |
|---|---|---|
| Reconstruire un objet de domaine depuis une ligne | `MessageMapper::fromRow()` | `Infrastructure/Persistence` |
| Aplatir un objet de domaine en colonnes | `MessageMapper::toRow()` | idem |
| `INSERT` / `UPDATE` explicites | `DbalMessageRepository` | idem |
| Lecture optimisée → DTO | `SqlMessagePageQuery` | idem |

**Ce qu'on gagne :**

- `Domain/` est vraiment pur — `deptrac` n'a même plus besoin de whitelister Doctrine.
- Chaque requête SQL est visible et relisible. Sur un portfolio, montrer une requête keyset indexée vaut
  mieux que montrer une annotation qui en génère une.
- Les VO d'identifiant se convertissent en un endroit unique, le mapper. Aucun type Doctrine à écrire.
- Plus de magie de flush : ce qui part en base est ce qu'on a écrit, quand on l'a écrit. Le
  franchissement d'agrégat de la section 3.6 devient deux `UPDATE`/`INSERT` explicites dans une
  transaction explicite — bien plus lisible qu'un `flush()` qui décide seul.

**Ce qu'on perd, et pourquoi c'est acceptable ici :**

| Perte | Impact sur T1 |
|---|---|
| Change tracking automatique | Les repositories font des `INSERT`/`UPDATE` explicites — 4 tables, aucun graphe d'objets complexe |
| Lazy loading, identity map | Le fan-out en pull ne charge jamais de graphe profond ; on ne chargeait rien en cascade |
| `DoctrineFixturesBundle` | Fixtures écrites en SQL/DBAL dans une commande console — quelques dizaines de lignes |
| Middleware `doctrine_transaction` | Remplacé par un middleware Messenger maison sur `Connection::transactional()` (section 3.3) |
| Repositories générés | Écrits à la main — c'est précisément ce qu'on veut montrer |

`doctrine/migrations` s'utilise **sans l'ORM** : les migrations sont écrites en SQL explicite plutôt que
générées par diff. Sur 4 tables c'est un avantage — les index, les contraintes uniques partielles et le
`NULL` multiple de `direct_key` sont écrits intentionnellement, pas déduits.

#### SQL pur, pas de QueryBuilder

**Décision** : les requêtes sont écrites en **SQL littéral**, passé à `Connection::executeQuery()` /
`executeStatement()`. Le `QueryBuilder` de DBAL n'est pas utilisé.

Motif : une requête lue d'un bloc dit immédiatement quel index elle emprunte. Une requête assemblée par
appels chaînés oblige à la reconstituer mentalement — et sur un portfolio, la requête keyset de la
section 4 doit être lisible telle quelle. Le `QueryBuilder` sert à composer dynamiquement des filtres
optionnels ; la tranche 1 n'en a aucun.

**On assume Postgres.** Aucune tentative de portabilité : `ON CONFLICT`, index unique partiel,
`RETURNING` sont utilisés sans complexe. Prétendre rester agnostique tout en ciblant un seul SGBD
coûterait de la lisibilité pour une flexibilité que personne n'utilisera.

**Règles non négociables :**

| Règle | Raison |
|---|---|
| **Toujours des paramètres liés**, jamais de concaténation de valeurs | injection SQL — la contrepartie directe du SQL écrit à la main |
| Listes `IN (...)` via `ArrayParameterType` de DBAL | générer les placeholders à la main est la source d'erreur classique ; c'est le seul endroit où l'abstraction DBAL est le bon outil |
| Chaque requête vit dans le repository ou la classe de query qui l'utilise | pas de « dépôt central de SQL » déconnecté de son consommateur |
| Le résultat brut est typé au passage du mapper | PHPStan `max` : `fetchAssociative()` renvoie un type très large, il est resserré en un point unique |

**Conséquence sur l'idempotence** (section 6) : plutôt que de provoquer puis rattraper une
`UniqueConstraintViolationException`, l'insert s'écrit
`INSERT … ON CONFLICT (sender_id, client_message_id) DO NOTHING RETURNING id`. Zéro ligne retournée
signifie « déjà présent » → un `SELECT` récupère l'existant. Le cas nominal **et** le rejeu passent par
du contrôle de flux ordinaire, pas par une exception — plus lisible, et l'intention est portée par le
SQL lui-même.

#### `Domain/` n'a aucune dépendance externe

**Zéro paquet Composer**, pas même `symfony/uid`. Aucune exception whitelistée dans `deptrac.yaml`.

L'ULID est le seul candidat sérieux à une dépendance, et il ne résiste pas à l'examen. Ce que le
domaine fait d'un identifiant :

| Besoin | Comment | Dépendance |
|---|---|---|
| Valider le format | expression régulière `/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/` (26 caractères, base32 Crockford) | aucune |
| Ordonner par le temps | tri lexicographique de la chaîne — c'est *la* propriété de l'ULID | aucune |
| **Générer** | `IdGeneratorInterface`, port implémenté en `Infrastructure` avec `symfony/uid` | hors du domaine |
| Extraire le timestamp | **jamais** : on a une colonne `created_at` explicite (section 2) | sans objet |

Le domaine ne génère pas d'identifiant, il en reçoit. Une bibliothèque ULID dans `Domain/` ne servirait
donc qu'à remplacer une expression régulière — un couplage sans contrepartie.

> Un test verrouille la validation de `MessageId` sur des cas connus (longueur, caractères exclus `I`,
> `L`, `O`, `U`, premier caractère > `7`). C'est la seule contrepartie de ne pas utiliser
> `Ulid::isValid()`, et elle est bon marché.

Bénéfice concret : la règle deptrac s'énonce sans exception — « `Domain` ne dépend de rien ». Une règle
sans dérogation ne se négocie pas et ne se dégrade pas.

### 3.6 Agrégats et frontières

| Agrégat | Racine | Contenu |
|---|---|---|
| Conversation | `Conversation` | ses membres, son type, son pointeur de dernier message |
| Message | `Message` | référence `ConversationId` et `UserId` par identifiant, pas par objet |

**Pourquoi les messages ne sont pas dans l'agrégat Conversation** : charger 50 000 messages pour en
ajouter un serait absurde. La frontière est choisie selon la **taille de la transaction d'écriture**,
pas selon la logique de contenance. C'est le critère correct, et il vaut d'être écrit.

**Franchissement d'agrégat assumé** : `SendMessageHandler` modifie deux agrégats dans une transaction
(insert du `Message`, mise à jour du pointeur sur `Conversation`).

- *Le puriste dirait* : passer par un domain event et une cohérence à terme.
- *Décision* : même transaction. Le pointeur serait faux le temps d'un aller-retour, ce qui se verrait
  directement dans la liste des conversations. Sur un projet mono-base, l'asynchronisme achèterait un
  bug d'affichage au prix d'une complexité réelle.
- Documenté comme un **choix**, pas comme un oubli.

### 3.7 Domain events : publier après le commit

`Message::send()` enregistre un `MessageWasSent` sur l'agrégat. L'événement est dispatché **après le
commit** de la transaction, et c'est le listener qui appelle `EventPublisher`.

**Pourquoi c'est non négociable** : publier sur Mercure à l'intérieur de la transaction permet de
pousser aux clients un message qu'un rollback fera ensuite disparaître de la base. Les destinataires
verraient un message qui n'existe pas. Le bug serait rare, non reproductible, et très coûteux à
diagnostiquer.

**Comment, concrètement** : le middleware transactionnel du `command.bus` (section 3.3) collecte les
événements enregistrés pendant la transaction et les dispatche **après** le `commit`. L'absence d'ORM
simplifie : plus de listener `postFlush` ni de cycle de vie Doctrine à comprendre — un seul middleware,
lisible d'un bout à l'autre.

Effet de bord agréable : le rejeu idempotent (violation d'unicité → on renvoie l'existant) n'enregistre
aucun événement, donc **ne republie rien**. La règle de la section 6 tombe de la structure au lieu
d'être un `if` à ne pas oublier.

### 3.8 Faire respecter l'architecture : deptrac en CI

`qossmic/deptrac` échoue le build si :

- `Domain` référence **quoi que ce soit hors de lui-même et du cœur de PHP** — aucune whitelist
  (section 3.5) ;
- `Application` référence `Infrastructure` ;
- un contexte référence le `Domain` d'un autre contexte (ils communiquent par identifiants, pas par
  objets).

Sans cet outil, la règle de dépendance se dégrade en deux semaines. **C'est ce qui fait la différence
entre une architecture hexagonale et un dossier nommé `Domain`** — et c'est vérifiable par un
relecteur en une commande.

### 3.9 Coût assumé

Cette architecture multiplie environ par deux le nombre de fichiers de la tranche 1 par rapport à des
contrôleurs Symfony classiques. Sur un projet de cette taille, elle ne se justifie **que** par
l'objectif portfolio et par le fait que les tranches 2 à 5 vont réellement s'y greffer. C'est écrit ici
pour que le choix reste conscient.

### 3.10 Conventions de nommage

Le backend suit les **conventions de nommage Symfony**
(<https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions>), sans exception.
Elles s'appliquent au backend uniquement — le frontend suit les usages TypeScript/React.

| Élément | Convention | Exemple du projet |
|---|---|---|
| Classes, interfaces, traits, enums | `UpperCamelCase` | `SendMessageHandler` |
| **Interfaces** | suffixe `Interface` | `MessageRepositoryInterface` |
| **Classes abstraites** | préfixe `Abstract` | `AbstractDbalRepository` |
| **Traits** | suffixe `Trait` | |
| **Exceptions** | suffixe `Exception` | `EmptyMessageContentException` |
| Variables, méthodes, arguments | `camelCase` | `$clientMessageId`, `lastMessageAt()` |
| Constantes | `SCREAMING_SNAKE_CASE` | `MessageContent::MAX_LENGTH` |
| **Cas d'enum** | `UpperCamelCase` | `ConversationType::Direct`, `MemberRole::Admin` |
| Noms de routes, paramètres de config | `snake_case` | `conversation_messages_send` |
| Attributs de config de service | préfixe `As` | `#[AsMessageHandler]` |
| Fichiers PHP | `UpperCamelCase` | `SendMessageHandler.php` |
| PHPDoc scalaires | `bool`, `int`, `float` | jamais `boolean`, `integer`, `double` |
| Fichiers | une classe par fichier | |

**Tension assumée avec l'usage DDD.** Une part de la littérature hexagonale nomme les ports sans
suffixe — `MessageRepository` pour le port, `DbalMessageRepository` pour l'implémentation — au motif que
le port *est* le concept et que c'est l'implémentation qui mérite un qualificatif. **On ne suit pas cet
usage** : la convention Symfony l'emporte, donc `MessageRepositoryInterface`. Un seul système de nommage
dans le projet vaut mieux que deux qui se disputent la frontière, et un relecteur PHP y retrouvera ses
repères immédiatement.

Ces conventions sont vérifiées par PHP-CS-Fixer là où c'est automatisable ; le reste tient à la revue.

### 3.11 Journalisation

**Exigence : logguer abondamment, partout où c'est utile, avec des niveaux PSR-3 correctement
distingués.** Un log par niveau réel de gravité, pas huit synonymes de « il s'est passé un truc ».

#### Où l'on loggue — et où l'on ne loggue pas

| Couche | Loggue ? | Pourquoi |
|---|---|---|
| `Domain` | **jamais** | zéro dépendance (section 3.5) : y injecter `psr/log` casserait la règle. Un fait notable s'y exprime par un **domain event** ou une **exception**, pas par une ligne de log |
| `Application` | oui, c'est le lieu principal | les use cases connaissent l'intention métier — c'est là que « Bob a envoyé un message dans la conversation X » a un sens |
| `Infrastructure` | oui, pour les I/O | résultats des appels sortants : publication Mercure, requêtes SQL lentes, émission de JWT |

`psr/log` est autorisé dans `Application` et `Infrastructure`, **interdit dans `Domain`** — règle
deptrac.

#### Deux sources complémentaires

1. **Un middleware de log sur les bus** (`command.bus` et `query.bus`) : début, succès ou échec, durée,
   pour **chaque** commande et requête, uniformément. Aucune ligne répétitive dans les handlers, et une
   couverture qui ne peut pas être oubliée.
2. **Des logs explicites dans les handlers**, pour ce que le middleware ne peut pas savoir : un rejeu
   idempotent détecté, un membre ajouté, un token réémis.

#### Niveaux : sémantique retenue pour ce projet

| Niveau | Sens | Exemples concrets |
|---|---|---|
| `emergency` | système inutilisable | **non utilisé par le code applicatif** — réservé à l'infrastructure |
| `alert` | intervention humaine immédiate | hub Mercure injoignable de façon répétée : plus aucun temps réel, l'app est fonctionnellement cassée |
| `critical` | composant vital indisponible | perte de connexion PostgreSQL ; échec de publication après épuisement des tentatives |
| `error` | opération échouée, non rattrapée | exception non gérée remontée en 500 ; transaction annulée |
| `warning` | anomalie rattrapée, ou signal de bug ailleurs | **même `client_message_id` avec un contenu différent** (bug ou abus côté client) ; tentative d'accès à une conversation dont on n'est pas membre ; JWT Mercure expiré à la reconnexion |
| `notice` | événement métier normal mais significatif | conversation créée, membre ajouté ou retiré, connexion d'un utilisateur |
| `info` | flux nominal | message envoyé et publié, page d'historique servie, token Mercure réémis |
| `debug` | détail de mise au point | SQL exécuté et durée, topics inscrits dans un JWT, décision de dédup |

> **Choisir de ne pas utiliser `emergency` fait partie du bon usage des niveaux.** Un niveau qu'on
> émet « au cas où » perd son sens : si `emergency` peut vouloir dire n'importe quoi, il ne déclenche
> plus rien. Même logique pour `alert`, réservé au seul cas où le temps réel est totalement rompu.

Le repère pratique : **`warning` et au-dessus doivent être actionnables.** Si personne ne ferait rien en
lisant la ligne, c'est un `info` ou un `notice`.

#### Confidentialité : ce qu'on ne loggue jamais

> Sur une messagerie, c'est une contrainte de conception, pas une précaution de style.

**Interdits absolus** : le `content` d'un message, le JWT Mercure ou le cookie de session, un mot de
passe même haché, l'adresse e-mail complète.

**On loggue des identifiants, jamais des charges utiles.** `message_id`, `conversation_id`, `user_id`,
et à la rigueur la *longueur* du contenu. Un log `debug` n'échappe pas à la règle : le mode debug finit
toujours par être activé en production un jour de panne.

#### Forme

- **Placeholders PSR-3 entre accolades, variables dans le second argument.** Le message reste une
  chaîne littérale constante ; les valeurs vivent dans le tableau de contexte, et les clés du contexte
  correspondent aux accolades du message
  (<https://symfony.com/doc/current/logging.html#logging-a-message>).

  ```php
  // Attendu
  $logger->info('Message {message_id} envoyé dans la conversation {conversation_id}', [
      'message_id' => (string) $messageId,
      'conversation_id' => (string) $conversationId,
  ]);

  // Interdit
  $logger->info(sprintf('Message %s envoyé dans la conversation %s', $messageId, $conversationId));
  $logger->info("Message {$messageId} envoyé…");
  $logger->info('Message envoyé : ' . $messageId);
  ```

  Trois raisons, dans l'ordre d'importance : le message devient une **clé stable** sur laquelle grouper
  et alerter (avec `sprintf`, chaque ligne est unique et l'agrégation est impossible) ; les valeurs
  restent des **champs exploitables** au lieu d'être noyées dans une phrase ; et un formateur peut
  décider de ne pas interpoler du tout. Corollaire : **toute valeur dynamique va dans le contexte**, y
  compris celles qui n'ont pas d'accolade correspondante dans le message.
- **Un canal Monolog par contexte** : `identity`, `conversation`, `message`, `realtime`.
- **Un identifiant de corrélation par requête HTTP**, propagé dans le contexte de toutes les lignes
  qu'elle produit — indispensable pour reconstituer un envoi de message de bout en bout.
- **Une erreur se loggue une seule fois**, à la frontière qui la traite. Loguer puis relancer produit la
  même erreur trois fois dans le fichier avec trois niveaux différents.

#### Configuration par environnement

| Env | Stratégie |
|---|---|
| `dev` | tout à partir de `debug`, sur `stderr` (conteneur) |
| `test` | `error` et au-dessus, pour ne pas noyer la sortie de PHPUnit |
| `prod` | handler `fingers_crossed` déclenché sur `error` — les lignes `debug` sont **conservées en mémoire et écrites uniquement si une erreur survient** |

Le `fingers_crossed` est ce qui rend l'exigence « logguer abondamment » soutenable : on garde la trace
complète menant à l'incident, sans écrire des gigaoctets de `debug` en fonctionnement normal.

---

## Section 4 — API HTTP

Routes sous `/api`, réponses JSON, erreurs en `application/problem+json` (RFC 7807). Les contrôleurs
sont des **adaptateurs primaires** : désérialiser, construire la commande ou la query, dispatcher,
sérialiser. Aucune logique métier.

| Méthode | Route | Rôle |
|---|---|---|
| `POST` | `/api/login` | `json_login` Symfony → pose le cookie de session **et** le cookie Mercure |
| `POST` | `/api/logout` | invalide les deux cookies |
| `GET` | `/api/me` | identité courante |
| `GET` | `/api/users` | annuaire (petit projet, fixtures) |
| `GET` | `/api/conversations` | mes conversations, triées `last_message_at DESC`, avec aperçu |
| `POST` | `/api/conversations` | `{type, title?, member_ids[]}` |
| `GET` | `/api/conversations/{id}` | détail + membres |
| `GET` | `/api/conversations/{id}/messages` | historique, `?before={ulid}&limit=50` |
| `POST` | `/api/conversations/{id}/messages` | `{client_message_id, content}` |
| `POST` | `/api/conversations/{id}/members` | `{user_ids[]}` — groupes, admin uniquement |
| `DELETE` | `/api/conversations/{id}/members/{userId}` | idem, ou quitter soi-même |
| `GET` | `/api/realtime/token` | réémet le cookie Mercure (section 5) |

### Pagination par keyset, pas par offset

`?before={ulid}` se traduit en `WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT 50`, qui
consomme l'index `(conversation_id, id DESC)`. Un `OFFSET` deviendrait **faux** dès qu'un message
arrive pendant qu'on remonte l'historique — scénario permanent dans une messagerie active.

Réponse : `{ "items": [...], "next_before": "<ulid>|null" }`.

### Création de direct idempotente

`POST /api/conversations` avec `type: "direct"` calcule le `DirectKey` ; si la conversation existe,
renvoie **200** avec l'existante au lieu de **201**. Même philosophie que `ClientMessageId`, appliquée
aux conversations.

### Autorisation

Un voter Symfony `ConversationVoter` (`VIEW`, `POST_MESSAGE`, `MANAGE_MEMBERS`) couvre toutes les
routes `/conversations/{id}/*`. Il délègue à un service d'appartenance du contexte `Conversation`.

**Source de vérité unique** : ce même service alimente le voter **et** la liste des topics du JWT
Mercure. Une divergence entre « qui peut lire l'API » et « qui peut s'abonner au topic » serait une
faille — elle est structurellement impossible ici.

### Format d'erreur : RFC 7807

**Toute** réponse d'erreur de l'API est un *Problem Details*, avec l'en-tête
`Content-Type: application/problem+json`. Aucune exception : pas de `{"error": "..."}` ad hoc, pas de
page HTML d'erreur Symfony qui fuirait sur une route `/api`.

> RFC 7807 est formellement remplacée par la **RFC 9457**, qui la clarifie sans rien casser. Le format
> ci-dessous est valide pour les deux.

#### Membres

| Membre | Obligatoire | Contenu |
|---|---|---|
| `type` | oui | URI stable identifiant **la classe** de problème, ex. `/problems/conversation-not-found`. `about:blank` pour une erreur HTTP générique |
| `title` | oui | libellé court, **constant pour un `type` donné** — jamais de valeur variable dedans |
| `status` | oui | code HTTP, dupliqué dans le corps |
| `detail` | recommandé | explication de **cette occurrence**, lisible par un humain |
| `instance` | oui | chemin de la requête |
| `correlation_id` | oui *(extension)* | identifiant de corrélation de la requête — **le même que dans les logs** (section 3.11) |

Le `correlation_id` est le détail qui rend le reste utile : un utilisateur signale une erreur, colle la
réponse, et la trace complète est retrouvable dans les logs sans chercher.

`type` et `title` sont constants et groupables ; `detail` porte le variable. C'est exactement la même
discipline que les placeholders de log (section 3.11), appliquée aux réponses HTTP.

#### Catalogue de la tranche 1

| `type` | Statut | Cas |
|---|---|---|
| `/problems/malformed-request` | 400 | JSON illisible, corps absent |
| `/problems/authentication-required` | 401 | pas de session valide |
| `/problems/access-denied` | 403 | authentifié, membre, mais **rôle insuffisant** (non-admin voulant gérer les membres) |
| `/problems/resource-not-found` | 404 | ressource inexistante **ou non accessible** — voir ci-dessous |
| `/problems/validation-failed` | 422 | charge utile bien formée mais invalide (contenu vide, ULID mal formé) — extension `errors` : champ → messages |
| `/problems/internal-error` | 500 | tout le reste. `detail` **générique**, jamais de message d'exception ni de fragment SQL |

#### Décision : 404 plutôt que 403 pour un non-membre

Répondre 403 sur une conversation dont on n'est pas membre **confirme qu'elle existe**. En itérant sur
des identifiants, on énumère les conversations du système — un oracle d'existence.

**Règle retenue :**

- **non-membre → 404**, indistinguable d'un identifiant inexistant ;
- **403 uniquement quand l'appartenance est déjà établie** et que seul le rôle manque. Le 403 ne révèle
  alors rien que l'appelant ne sache déjà.

Les identifiants étant des ULID (non séquentiels), l'énumération est déjà peu praticable — mais faire
reposer une propriété de sécurité sur la difficulté de deviner un identifiant est un pari, pas une
décision. Un test fonctionnel verrouille les deux cas.

#### Mise en œuvre

Un listener d'exception dans `Shared/Infrastructure` traduit les exceptions en Problem Details. Il porte
**seul** la table de correspondance exception → statut HTTP.

C'est structurellement obligatoire : les exceptions de `Domain` ont zéro dépendance (section 3.5) et
ignorent donc totalement HTTP. `EmptyMessageContentException` ne sait pas qu'elle vaut 422 — c'est
l'adaptateur qui le décide. La couche de traduction est le seul endroit où le domaine rencontre le
protocole.

Le listener loggue l'erreur **une fois** (section 3.11) et attache le `correlation_id` à la réponse.

---

## Section 5 — Topics Mercure & autorisation

### Topics de la tranche 1

| Topic | Contenu | Présence dans le JWT |
|---|---|---|
| `/conversations/{ulid}` | `message.created` | une entrée par conversation dont je suis membre |
| `/users/{monUlid}/system` | `membership.changed` | **toujours**, ne change jamais |

Ces chaînes ne sont jamais écrites à la main : `Topic::conversation()` / `Topic::userSystem()`
(section 3.4).

### Publication

Un seul `publish` par message sur `/conversations/{id}`, en *private update*. Le hub assure le fan-out
O(N) ; le métier reste en O(1) — cf. [[Groupes et fan-out]]. L'`id` de l'événement Mercure est l'ULID
du message, ce qui rendra `Last-Event-ID` exploitable en T2 sans changement de format.

### Le JWT

HS256, claim `mercure.subscribe` = les topics ci-dessus, **TTL 15 min**, livré en cookie
`mercureAuthorization` (`HttpOnly`, `SameSite=Lax`, `Path=/.well-known/mercure`). Le front ne le lit
jamais → pas de fuite par XSS.

### Le problème de la réémission

La liste des topics est figée à l'émission. Trois événements la périment :

| Cas | Détection | Réaction |
|---|---|---|
| Je crée / rejoins une conversation | action locale | `GET /api/realtime/token` puis reconnexion `EventSource` |
| Le JWT expire | minuteur front à 13 min | refresh silencieux |
| **Quelqu'un m'ajoute à un groupe** | *aucune détection possible* | ← voir ci-dessous |

**Solution du cas 3** : le topic `/users/{monUlid}/system` est dans **tous** mes JWT et ne change
jamais, donc toujours joignable. Le backend y publie `membership.changed` ; le front appelle
`/api/realtime/token` et se reconnecte. Un topic personnel permanent comme canal de notification des
changements d'autorisation — pattern standard, réutilisé tel quel en T2 (`/users/{id}/receipts`).

**Alternative écartée** : un wildcard `/conversations/{*}` dans le JWT. Supprimerait toute la mécanique
de réémission, mais autoriserait n'importe qui à s'abonner à n'importe quelle conversation. Le contrôle
d'accès disparaîtrait.

---

## Section 6 — Idempotence & flux d'envoi

```
1. Front génère un ULID client → client_message_id
2. Affichage optimiste immédiat, statut « envoi… »
3. POST /api/conversations/{id}/messages
4. command.bus → SendMessageHandler, dans une transaction :
   INSERT … ON CONFLICT (sender_id, client_message_id) DO NOTHING RETURNING id
   ├─ une ligne  → MAJ pointeur, MessageWasSent enregistré        → 201
   └─ zéro ligne → SELECT l'existant, AUCUN événement enregistré  → 200
5. Après commit : le listener publie sur Mercure (uniquement si événement)
6. Front réconcilie par client_message_id → statut « envoyé », adopte l'ULID serveur
7. Échec réseau : retry backoff exponentiel + jitter, MÊME client_message_id
```

### Dédup côté client, en deux passes

L'expéditeur reçoit son propre message **deux fois** (réponse HTTP + SSE). Le store dédup d'abord par
`client_message_id` (remplace l'optimiste), sinon par `id` serveur (ignore le doublon). C'est la
« dédup aux deux bouts » de [[Idempotence et déduplication]].

### Décision tranchée : même clé, contenu différent

Le serveur **garde le premier** et renvoie l'existant sans erreur. La question était posée sans être
fermée par les notes. Verrouillé par un test unitaire sur le handler.

---

## Section 7 — Front (React + TypeScript)

Principe structurant, symétrique de l'hexagone backend : **toute la logique intéressante vit hors de
React**, en TypeScript pur.

```
frontend/src/
├── api/        # client HTTP typé, une fonction par endpoint
├── realtime/   # RealtimeClient : EventSource, refresh du token, reconnexion
├── store/      # reducer pur : conversations, messages, dédup, réconciliation
├── hooks/      # liaison React (useSyncExternalStore)
└── ui/         # composants, aussi bêtes que possible
```

`realtime/` et `store/` ne connaissent **rien** de React. Le `RealtimeClient` expose `start()`,
`stop()`, `on(event)` — un `EventSource` factice suffit à le tester intégralement. Le store est un
reducer `(state, action) => state` : dédup, réconciliation optimiste et ordre ULID se testent en
appelant une fonction.

**Écrans** : Login → layout deux colonnes (liste des conversations / conversation ouverte), modale
« nouvelle conversation » (choix d'un ou plusieurs users → direct ou groupe), panneau membres pour les
groupes.

**Styles** : **Tailwind CSS**, avec les tokens du projet (palette, espacements, typo) déclarés dans
`tailwind.config.ts` plutôt qu'en classes arbitraires dispersées. Pas de librairie de composants : sur
un portfolio, un chat sobre écrit à la main démontre plus qu'un assemblage de composants tiers.

Deux endroits où Tailwind seul ne suffira pas et où on écrira du CSS explicite : la zone scrollable de
l'historique (`overflow-anchor`, restauration de scroll) et les transitions d'apparition des messages.

### Deux points délicats identifiés d'avance

**Le scroll infini vers le haut.** Charger une page plus ancienne fait bondir le scroll. Il faut
mémoriser `scrollHeight` avant insertion et le restaurer après. Bug classique de toute messagerie :
anticipé dans le plan plutôt que découvert.

**Le cycle de vie de l'`EventSource`.** Se reconnecter sur `membership.changed`, sur expiration du
token et sur erreur réseau, sans jamais laisser deux connexions ouvertes (React StrictMode monte les
effets deux fois en dev — piège garanti). Le `RealtimeClient` est le seul propriétaire de la connexion,
ce qui rend l'invariant vérifiable par un test.

---

## Section 8 — Tests

L'architecture de la section 3 change la nature des tests : **les use cases sont testables sans base de
données**, avec des repositories en mémoire implémentant les ports. Les tests fonctionnels ne couvrent
plus que les adaptateurs.

### Backend — unitaires (aucune I/O)

- **Value objects** : `MessageContent` (vide, espaces seuls, 4001 caractères), `DirectKey` commutatif,
  `Topic`, refus de croiser `UserId` et `ConversationId`.
- **Domaine** : `Message::send()` enregistre `MessageWasSent` ; règles d'appartenance et de rôle.
- **Use cases**, avec repositories en mémoire, `Clock` gelée et `IdGenerator` déterministe :
  - `SendMessageHandler` — cas nominal ; rejeu du même `ClientMessageId` → même identifiant, **aucun**
    événement enregistré ; même clé + contenu différent → le premier est conservé ;
  - `CreateConversationHandler` — direct en double → l'existante ;
  - `AddMembersHandler` — un `membership.changed` par nouveau membre, sur le bon topic.
- **`MercureTokenFactory`** : la liste de topics correspond exactement aux appartenances,
  `/users/{id}/system` toujours présent.
- **`ConversationVoter`** : membre / non-membre / admin.

### Backend — fonctionnels (`WebTestCase`, base de test, rollback par test)

Chaque endpoint sur trois axes : cas nominal, non authentifié (401), **non-membre (404, pas 403** —
section 4). Chaque réponse d'erreur est vérifiée comme un Problem Details valide : en-tête
`application/problem+json`, membres obligatoires présents, `type` conforme au catalogue. Plus les
scénarios qui portent la valeur :

- rejouer le même `POST /messages` → **200**, même `id`, **une seule** publication Mercure ;
- `POST /conversations` direct en double → 201 puis 200, même `id` ;
- pagination keyset : 120 messages, remontée par pages de 50, ni trou ni doublon **même quand un
  message est inséré entre deux pages** ;
- ajouter un membre → `membership.changed` publié sur `/users/{id}/system` du **nouveau** membre ;
- publication **après commit** : un handler qui échoue après l'insert ne publie rien ;
- **pas d'oracle d'existence** : une conversation existante dont on n'est pas membre et un identifiant
  totalement inconnu renvoient des réponses **indistinguables** (même statut, même `type`, même
  `title`).

`EventPublisher` est remplacé par un espion en mémoire : on assert le topic **et** la charge utile.
**Aucun hub Mercure n'est nécessaire en CI.**

### Front — Vitest

Le reducer (dédup par `client_message_id`, dédup par `id`, ordre ULID, insertion d'une page ancienne en
tête) et le `RealtimeClient` avec un `EventSource` factice (refresh à l'expiration, reconnexion sur
`membership.changed`, jamais deux connexions simultanées).

### Pas de Playwright

Choix assumé. En contrepartie, le README documente une vérification manuelle explicite : deux
navigateurs, Alice envoie, Bob reçoit sans rafraîchir.

### CI GitHub Actions

Un workflow qui exécute **les mêmes conteneurs que le poste de développement** — pas de `setup-php`,
pas de `setup-node`. La CI lance `make qa`, exactement ce que Nicolas lance en local (section 1.8).
C'est ce qui garantit qu'un build vert en local l'est aussi en CI : mêmes images, mêmes versions,
mêmes PHARs épinglés.

Contenu : PHPUnit (avec un service Postgres), Vitest, PHPStan niveau `max`, PHP-CS-Fixer en `--dry-run`,
**deptrac**. CI par chemin : `backend/**` ne déclenche pas Vitest.

`deptrac` est ce qui empêche l'architecture de se dégrader silencieusement — sans lui, la règle de
dépendance de la section 3.1 se serait érodée en quelques semaines.

---

## Section 9 — Hors périmètre, explicitement

Accusés distribué/lu, présence, typing, Redis · édition, suppression, tombstones · médias, MinIO ·
recherche · rate limiting, modération · E2E · notifications push · reprise `Last-Event-ID` (le format
d'événement la prépare, on ne l'implémente pas) · OAuth (le schéma la prépare, on ne la câble pas) ·
CQRS avec read model séparé (T5) · déploiement en production.

---

## Critères d'acceptation de la tranche 1

1. `make up` lève les 5 services ; `make migrate fixtures` prépare une base jouable.
2. Deux navigateurs, deux utilisateurs : Alice envoie un message, Bob le voit **sans rafraîchir**.
3. Alice crée un groupe et y ajoute Carol ; Carol le voit apparaître **sans rafraîchir** (chemin
   `membership.changed`).
4. Couper le réseau, envoyer, rétablir : un seul message en base, un seul affiché.
5. Remonter l'historique d'une conversation de 200 messages : pages successives, ni trou ni doublon.
6. `make qa` (phpunit + vitest + deptrac + phpstan + cs-fixer) est vert.

## Questions restées ouvertes

Aucune bloquante. Un point sera tranché à l'implémentation, sans impact sur l'architecture : la
bibliothèque ULID côté front (`ulid` ou `ulidx`).

La stratégie de dispatch après commit est désormais fermée : middleware Messenger sur le `command.bus`
(section 3.7), l'abandon de l'ORM ayant supprimé l'alternative `postFlush`.
