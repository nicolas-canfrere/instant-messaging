# Instant Messaging

Messagerie instantanée PHP/Symfony + Mercure + React. Projet **portfolio** : la qualité
d'architecture et de code prime sur la vitesse de livraison.

- **Concepts et raisonnements** : vault Obsidian `~/Documents/obsidian_vaults/tech/InstantMessaging`
  (17 notes, en français) — le *pourquoi* de chaque mécanisme.
- **Décisions transverses** : `docs/adr/` — les ADR survivent aux tranches et **priment sur les
  specs** en cas de divergence. Toute décision d'archi qui dépasse une tranche s'y consigne.
- **Décisions d'architecture d'une tranche** : `docs/superpowers/specs/` — une spec par
  tranche, avec les alternatives écartées et leur coût.
- **Plans d'implémentation** : `docs/superpowers/plans/` — une story = une branche.

Découpage en 5 tranches (détail dans la spec T1) : **T1 noyau temps réel + conversations** ·
T2 accusés & présence · T3 édition/suppression · T4 médias · T5 recherche & modération.
**Ne pas déborder d'une tranche sur la suivante** — chacune aura sa spec et son plan.

## Règles absolues

- **Jamais de commit sur `main`.** Toujours une branche, même pour une ligne.
  `feat/<story>` · `fix/<sujet>` · `docs/<sujet>` · `chore/<sujet>`
- **Ni PHP ni Node.js ne sont installés sur la machine.** `php`, `composer`, `node`, `npm`,
  `vendor/bin/*` n'existent **que dans les conteneurs**. Ne jamais les invoquer directement :
  passer par `make` ou `docker compose run --rm <service> <cmd>`. Une commande écrite dans une
  story ou dans la doc doit être exécutable telle quelle.
- **Ne pas bootstraper le projet Symfony ni installer de paquets Composer.** C'est Nicolas
  qui s'en charge. Signaler les paquets manquants, ne pas les installer.
- **`Domain/` ne dépend de rien** — zéro paquet Composer, **aucune exception**, pas même
  `symfony/uid`. Le domaine valide un ULID par expression régulière et ne le génère jamais
  (c'est `IdGeneratorInterface`, implémenté en `Infrastructure`). `deptrac` échoue le build
  en cas de violation.

## Infrastructure

9 conteneurs : `caddy` (origine unique, `localhost:8080`) · `backend` (FrankenPHP) ·
`frontend` (Vite) · `mercure` · `postgres` · `redis` (présence éphémère, sans volume) ·
`minio` (stockage objet) · `rabbitmq` (transport Messenger) · `worker` (consomme la file
`media` : inspection des octets et miniature).

**Caddy proxifie `/messaging-media/*` vers MinIO en préservant le `Host`.** Le navigateur
téléverse donc en *same-origin* : ni CORS sur le bucket, ni entrée dans `/etc/hosts`. Le nom
du bucket sert de préfixe de chemin, ce que `use_path_style_endpoint` donne déjà — aucune
réécriture d'URL, donc aucune signature cassée. Défaire ce routage casse tous les téléversements.

Le hub Mercure reste un **service séparé** — c'est la leçon centrale du projet, on ne le
fusionne pas dans FrankenPHP. Et le **worker mode de FrankenPHP est désactivé** : un process
par requête, pour rester cohérent avec la prémisse shared-nothing qui justifie l'existence
même du hub.

## Architecture

Hexagonale par contexte borné, CQS, value objects systématiques.

```
backend/src/<Contexte>/
├── Domain/           # PHP pur : entités, VO, ports, domain events
├── Application/      # Command/ et Query/ + leurs handlers, et leurs ports
└── Infrastructure/   # Http/, Persistence/ — les adaptateurs
```

Contextes : `Identity` · `Conversation` · `Message` · `Media` · `Realtime` · `Shared`.
Ils communiquent **par identifiants**, jamais en référençant le `Domain` d'un autre contexte.

Règle de dépendance : `Infrastructure` → `Application` → `Domain`. Jamais l'inverse.

**`Application` ne connaît aucun vendor, à la seule exception des `Psr\*`** — ni `Symfony\`,
ni `Doctrine\`, ni `Monolog\`. Les PSR sont des interfaces normalisées : en dépendre
n'attache pas l'application à un framework. Un use case exprime un besoin par un port ;
`Infrastructure` le réalise. `deptrac.yaml` a donc une couche `Psr` distincte de `Vendor`.

### Communication inter-contextes — voir [ADR 0001](docs/adr/0001-cross-context-communication.md)

**Un contexte ne dépend jamais que du contrat publié d'un autre — jamais de ses internes, ni
de son code, ni de ses tables.** Un `SELECT` dans la table d'un contexte voisin est une
violation, même si deptrac ne la voit pas.

| Élément | Emplacement |
|---|---|
| Identifiants (`UserId`, `ConversationId`, `MessageId`) | `Shared/Domain/Identifier/` |
| Événements inter-contextes (`MessageWasSent`, `MembershipChanged`) | `Shared/Domain/Event/` |
| `SecurityUser` (tous les contrôleurs en dépendent) | `Shared/Infrastructure/Security/` |
| **Contrats de lecture publiés** | `{Contexte}/Application/Contract/` — **pas** dans `Shared` : le producteur possède sa surface publiée |
| Implémentation d'un contrat | `{Contexte}/Infrastructure/Contract/` |
| Le besoin, côté consommateur, **quand il se traduit** | `{Contexte}/Domain/Port/` + adaptateur qui délègue au contrat |
| Le besoin, côté consommateur, **sans traduction** | rien — nommer le contrat publié directement ([ADR 0001](docs/adr/0001-cross-context-communication.md)) |
| VO spécifiques (`MessageContent`, `DirectKey`, `Topic`…) | dans leur contexte |

**Lectures** : contrat publié (`{Chose}FinderInterface` + `{Chose}View`, jamais l'agrégat).

**Écritures** : **chorégraphie**. Un contexte ne pilote jamais les use cases d'un autre. Le
producteur publie un fait, l'intéressé réagit avec sa propre commande. `Conversation` écoute
`MessageWasSent` et met à jour son propre pointeur — `Message` n'écrit jamais dans
`conversations`.

**Charge utile d'un événement partagé** : uniquement des types de `Shared` et des scalaires.
`MessageWasSent` transporte le contenu en `string`, pas en `MessageContent` — sinon `Shared`
dépendrait de `Message`. Un événement qu'un seul contexte écoute reste chez lui.

**Modifier un `*View` ou la charge utile d'un événement partagé est un changement cassant.**

Deux fichiers deptrac : `deptrac.yaml` (dimension technique) et `deptrac-contexts.yaml`
(dimension contexte + allowlist vers les couches `*Contract`). `make deptrac` lance les deux.

### Persistance : DBAL, jamais l'ORM

`doctrine/dbal` + `doctrine/migrations`. **`doctrine/orm` n'est pas installé et ne doit pas
l'être.** Repositories écrits à la main, mappers explicites `fromRow()` / `toRow()`,
migrations en SQL explicite.

**SQL pur, pas de `QueryBuilder`.** Requêtes littérales passées à `executeQuery()` /
`executeStatement()`. On assume PostgreSQL : `ON CONFLICT`, `RETURNING`, index partiels sont
les bienvenus, aucune portabilité recherchée.

- **Toujours des paramètres liés**, jamais de concaténation de valeurs.
- Listes `IN (...)` : `ArrayParameterType` de DBAL, ne pas générer les placeholders à la main.
- Chaque requête vit dans le repository ou le `Reader` qui l'utilise, **toujours en
  `Infrastructure`**. Écrire la requête **en entier** : ni constante de liste de colonnes,
  ni concaténation de fragments — une requête doit se copier telle quelle dans `psql`.
- Le mapper est le point unique où la ligne brute devient un type précis (PHPStan `max`).
- Idempotence : `ON CONFLICT … DO NOTHING RETURNING id`, pas d'exception rattrapée.

### CQS (pas CQRS)

| | Écriture | Lecture |
|---|---|---|
| Bus | `command.bus` | `query.bus` |
| Message | `{X}Command` | `{X}Query` |
| Handler | `{X}CommandHandler` | `{X}QueryHandler` |
| Chemin | domaine + repository | SQL direct → DTO de lecture |
| Retour | **`void`, toujours** | DTO |

Une seule base, pas de read model séparé, pas d'event sourcing.

**Un handler de commande ne rend jamais rien.** Pour connaître l'effet d'une écriture, on
pose ensuite une query — y compris pour récupérer un identifiant créé. C'est la séparation
CQS, pas une gêne à contourner.

**Pas d'attribut `#[AsMessageHandler]`** : ce serait une dépendance d'`Application` vers
Messenger. Les handlers implémentent `App\Shared\Application\Bus\CommandHandlerInterface`
ou `QueryHandlerInterface`, que `_instanceof` dans `services.yaml` tague vers le bon bus. Le
bus est donc choisi par le **type** du handler.

**`QueryInterface<TResult>` est paramétrée par le type de son résultat**, que
`QueryDispatcher::ask()` propage. L'appelant reçoit un type précis : ni `assert`, ni `@var`
côté contrôleur.

**Jamais de SQL dans `Application`**, y compris côté lecture. Le handler de query déclare
son besoin par un port `{Chose}ReaderInterface` dans `{Contexte}/Application/Query/`, réalisé
par un `Dbal{Chose}Reader` dans `{Contexte}/Infrastructure/Persistence/`.

### Nommage (backend)

**Conventions Symfony**, sans exception :
<https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions>

Interfaces suffixées `Interface` (`MessageRepositoryInterface` — **pas** l'usage DDD sans
suffixe), classes abstraites préfixées `Abstract`, traits suffixés `Trait`, exceptions
suffixées `Exception`. Cas d'enum en `UpperCamelCase` (`ConversationType::Direct`).
Constantes en `SCREAMING_SNAKE_CASE`. Noms de routes et paramètres de config en
`snake_case`. PHPDoc : `bool`/`int`/`float`. Une classe par fichier.

Une classe d'`Infrastructure` qui lit du SQL hors d'un repository se suffixe **`Reader`**,
jamais `Query` : ce mot désigne déjà un message de bus dans `Application/Query/`.

**Construire les chaînes avec `sprintf()`**, jamais par concaténation avec `.` — le gabarit
complet se lit d'un bloc. Seule exception, les messages de log : voir *Journalisation*.

Le frontend suit les usages TypeScript/React, pas ceux de Symfony.

### Value objects

Pas de primitive obsession. Les identifiants (`UserId`, `ConversationId`, `MessageId`) sont
des types **non interchangeables**. Les invariants vivent dans le VO (`MessageContent`
valide sa longueur), pas dans le contrôleur. Les topics Mercure se construisent via
`Topic::conversation()` / `Topic::userSystem()`, jamais par concaténation de chaînes.

### Journalisation

**Logguer abondamment**, avec des niveaux PSR-3 réellement distingués.

- `Domain` ne loggue **jamais** (zéro dépendance) : un fait notable y est un domain event
  ou une exception. `psr/log` est autorisé dans `Application` et `Infrastructure`.
- Un middleware de log sur les bus couvre chaque commande/query (début, issue, durée) ;
  les handlers ajoutent les logs que le middleware ne peut pas connaître.
- Niveaux : `alert` = temps réel totalement rompu · `critical` = composant vital indisponible
  · `error` = opération échouée non rattrapée · `warning` = anomalie rattrapée ou signal de
  bug ailleurs (même `client_message_id` avec contenu différent, accès non autorisé) ·
  `notice` = événement métier normal mais significatif · `info` = flux nominal · `debug` =
  détail de mise au point. **`emergency` n'est pas utilisé par le code applicatif.**
- Repère : **`warning` et au-dessus doivent être actionnables.**

**Ne jamais logguer** : le `content` d'un message, un JWT ou cookie de session, un mot de
passe même haché, un e-mail complet. On loggue des **identifiants**, jamais des charges
utiles — y compris en `debug`.

**Placeholders `{entre_accolades}`, variables dans le second argument.** Jamais de
`sprintf`, jamais d'interpolation `"{$var}"`, jamais de concaténation. C'est la seule
exception à la règle « `sprintf` plutôt que concaténation » du *Nommage* : ici le message
n'est pas une chaîne à composer, c'est une clé d'agrégation.

```php
$logger->info('Message {message_id} envoyé dans la conversation {conversation_id}', [
    'message_id' => (string) $messageId,
    'conversation_id' => (string) $conversationId,
]);
```

Le message doit rester une chaîne littérale constante : c'est la clé sur laquelle on groupe
et on alerte. Toute valeur dynamique va dans le contexte, même sans accolade correspondante.

Un canal Monolog par contexte. Un identifiant de corrélation par requête. Une erreur se
loggue **une seule fois**, à la frontière qui la traite.

### Domain events

Enregistrés sur l'agrégat, dispatchés **après le commit** par le middleware transactionnel
du `command.bus`. Publier dans la transaction pousserait aux clients des messages qu'un
rollback ferait disparaître.

## Erreurs de l'API

**Toute** réponse d'erreur est un *Problem Details* RFC 7807, en-tête
`application/problem+json`. Jamais de `{"error": "..."}` ad hoc ni de page HTML Symfony sur
une route `/api`.

Membres : `type` (URI stable de la classe de problème), `title` (constant pour un `type`),
`status`, `detail` (spécifique à l'occurrence), `instance`, et l'extension
`correlation_id` — **le même identifiant que dans les logs**.

`type`/`title` constants et groupables, `detail` variable. Même discipline que les
placeholders de log.

**404, pas 403, pour un non-membre** : un 403 confirmerait l'existence de la conversation
(oracle d'énumération). Le 403 est réservé au cas où l'appartenance est établie et où seul le
rôle manque.

En 500 : `detail` générique, jamais de message d'exception ni de fragment SQL.

La traduction exception → statut HTTP vit **uniquement** dans le listener de
`Shared/Infrastructure`. Les exceptions de `Domain` ignorent HTTP.

### Validation des entrées

**Jamais de `json_decode` dans un contrôleur**, et jamais de `@var array{…}` sur une charge
utile : une annotation qui décrit ce qu'on espère recevoir ment à PHPStan, exactement comme
une baseline. Une entrée mal typée doit produire un **422**, jamais un 500.

- Corps de requête : `#[MapRequestPayload]` sur un DTO de
  `{Contexte}/Infrastructure/Http/Payload/`, avec ses contraintes `Symfony\…\Constraints`.
- Chaîne de requête : `#[MapQueryString]` pour un DTO, `#[MapQueryParameter]` pour un
  paramètre isolé.
- Le contrôleur reçoit donc un objet **déjà désérialisé et validé**.

Les violations sortent en extension RFC 7807, une entrée par champ fautif :

```json
"violations": [{ "field": "member_ids[0]", "message": "Cet identifiant n'est pas un ULID valide." }]
```

Le client doit savoir **quel** champ corriger, et les recevoir **toutes** d'un coup — pas les
découvrir une par une en devinant depuis le `detail`.

Ce qu'une contrainte de champ ne peut pas exprimer — une règle qui dépend d'un autre champ,
comme « un groupe requiert un titre » — reste dans le contrôleur et lève une exception
`InvalidInputExceptionInterface`.

Une contrainte ne redéfinit jamais un format déjà défini ailleurs : elle référence
`AbstractUlidIdentifier::PATTERN` ou `{Enum}::values()`.

Le sérialiseur convertit globalement `snake_case` ↔ `camelCase`
(`config/packages/serializer.yaml`) : l'API parle snake_case, le PHP camelCase. **Ne pas
poser de `#[SerializedName]` au cas par cas** — il suffit de l'oublier une fois pour qu'un
champ arrive vide en silence. Les chemins de violation passent par le même convertisseur :
le client ne voit jamais un nom de propriété PHP.

## Temps réel — contrats à ne pas casser

Les tranches suivantes étendent ces mécanismes ; les modifier casserait le front.

- **Topics** construits uniquement via `Topic::conversation()` / `Topic::userSystem()`.
  Jamais de concaténation.
- `/users/{id}/system` est dans **tous** les JWT et **ne change jamais** : c'est le seul
  canal par lequel un utilisateur apprend qu'on l'a ajouté à une conversation. T2 ajoutera
  `/users/{id}/receipts` sur le même modèle.
- **`GET /api/realtime/token`** renvoie **200** avec `{"hub_url": …, "topics": [...]}` *et*
  pose le cookie `mercureAuthorization`. Le cookie **autorise**, le corps dit au front quels
  topics **sélectionner** dans l'URL du hub (`?topic=…`) — Mercure exige les deux.
- L'`id` de l'événement Mercure est **l'ULID du message** : c'est ce qui rendra
  `Last-Event-ID` exploitable sans changer le format.
- La charge utile `message.created` porte **`client_message_id`** : l'écho SSE part avant la
  réponse du `POST`, c'est la seule clé par laquelle le front réconcilie son envoi optimiste.
- Un `publish` par message. Le hub fait le fan-out ; le métier reste en O(1).
- **Publication après commit uniquement** (middleware transactionnel).
  `Message::reconstitute()` n'enregistre aucun domain event : c'est **par là** qu'un rejeu
  idempotent ne republie rien. Ne pas ajouter d'enregistrement d'événement dans
  `reconstitute()`.

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

Les portes de qualité : `make static-code-analysis` · `make check-cs` · `make deptrac` ·
`make test`. Les quatre doivent être vertes avant chaque commit.

## Commandes

Toutes passent par des conteneurs (voir règles absolues).

Le `Makefile` est écrit en partie par Nicolas : **le lire avant d'écrire une commande**, et
utiliser les cibles qui existent réellement. Ne pas inventer de cible ni supposer un nom.
S'il manque une cible, passer par `docker compose run --rm <service> <cmd>` et le signaler.

## Périmètre

Tranches 1 à 4 livrées. **Tranche 5 en cours : recherche & modération.** Ne pas déborder —
elle a sa spec, comme les précédentes.

Les **aperçus de liens** (et la défense SSRF qui va avec) sont une tranche à part entière, pas
un appendice de T4 : ne pas les commencer ici. Voir la section « Ce que cette tranche ne fait
délibérément pas » de la spec T4 pour le reste de ce qui a été écarté — vidéo et audio,
plusieurs résolutions, CDN, antivirus, quotas, chiffrement au repos, planificateur de purge.
