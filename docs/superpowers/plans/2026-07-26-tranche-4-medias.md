# Tranche 4 — Pièces jointes : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à un message de porter des images, téléversées **directement** du navigateur vers un stockage objet, inspectées par un worker asynchrone, et affichées chez tous les membres sans rafraîchissement.

**Architecture:** Un 6ᵉ contexte borné `Media` possède le stockage objet et le cycle de vie d'un objet téléversé (`Pending` → `Processing` → `Ready` | `Rejected`). `Message` possède la liaison message ↔ média et consomme le contrat publié de `Media`. La mise à jour temps réel passe par une chorégraphie à trois sauts : `Media` publie un fait, `Message` le traduit en fait métier, `Realtime` le pousse sur le topic de la conversation.

**Tech Stack:** PHP 8.5 / Symfony 7.4 · DBAL (jamais l'ORM) · MinIO + `aws/aws-sdk-php` · RabbitMQ + `symfony/amqp-messenger` · Mercure · React + TypeScript + Vite.

**Spec de référence :** `docs/superpowers/specs/2026-07-26-instant-messaging-tranche-4-design.md`. Les renvois `§N` ci-dessous y pointent.

## Global Constraints

Ces contraintes s'appliquent à **toutes** les tâches, sans rappel à chaque fois.

- **Branche unique `feat/tranche-4-medias`.** Jamais de commit sur `main`. La branche existe déjà et porte le commit de la spec.
- **Ni PHP ni Node sur la machine.** Toute commande passe par `make` ou `docker compose run --rm <service> <cmd>`. Les commandes de ce plan sont exécutables telles quelles.
- **`Domain/` ne dépend de rien** — zéro `use` de vendor, pas même `symfony/uid`.
- **`Application/` ne connaît que `Psr\*`** — ni `Symfony\`, ni `Doctrine\`, ni `Aws\`.
- **SQL pur**, jamais de `QueryBuilder`. Requêtes littérales complètes en heredoc `<<<'SQL'`, paramètres liés, dans `Infrastructure/` uniquement.
- **CQS** : un handler de commande rend `void`. Pour connaître l'effet d'une écriture, on pose une query.
- **PHPStan niveau `max`** : génériques annotés (`list<MediaView>`), lignes DBAL typées (`array{id: string, …}`), aucun `mixed` implicite. Ni baseline, ni `@phpstan-ignore`.
- **Logs** : placeholders `{entre_accolades}`, variables au second argument, message littéral constant. **Jamais** de nom de fichier téléversé, de clé de stockage ni d'URL signée dans un log — on loggue des identifiants.
- **Erreurs API** : RFC 7807 uniquement, via les exceptions du domaine implémentant `ForbiddenExceptionInterface` / `ConflictExceptionInterface` / `NotFoundExceptionInterface` / `InvalidInputExceptionInterface`. Le `ProblemDetailsListener` n'est jamais modifié.
- **Nommage Symfony** : interfaces suffixées `Interface`, exceptions suffixées `Exception`, enums en `UpperCamelCase`, constantes en `SCREAMING_SNAKE_CASE`, une classe par fichier. Une classe d'`Infrastructure` qui lit du SQL hors repository se suffixe `Reader`.
- **`sprintf()`**, jamais de concaténation avec `.` — sauf dans les logs, qui gardent leurs placeholders.
- **TDD** : le test qui décrit le comportement avant le code.
- **Quatre portes vertes avant chaque commit** : `make static-code-analysis`, `make check-cs`, `make deptrac`, `make test`.
- **Commits conventionnels, en français, à l'impératif.** Message sans accents dans le corps (le dépôt en est dépourvu).

### Valeurs figées par la spec, à copier telles quelles

| Constante | Valeur | Où |
|---|---|---|
| Types acceptés | `image/jpeg`, `image/png`, `image/webp`, `image/gif` | `MediaMimeType` |
| Plafond | 10 Mio, soit `10 * 1024 * 1024` | `MediaObject::MAX_BYTES` |
| TTL upload | 5 minutes | `S3MediaStorage::UPLOAD_TTL` |
| TTL lecture | 15 minutes | `S3MediaStorage::DOWNLOAD_TTL` |
| Côté long miniature | 400 px | `GdImageInspector::THUMBNAIL_MAX_SIDE` |
| Médias par message | 10 max | `SendMessagePayload` |
| Âge de purge | 24 h | `PurgeOrphanMediaCommand::ORPHAN_AGE_HOURS` |
| Bucket | `messaging-media` | variable d'env `MEDIA_BUCKET` |
| Préfixe de clé | `media/` | `StorageKey` |

---

## Structure des fichiers

### Contexte `Media` (nouveau)

| Fichier | Responsabilité |
|---|---|
| `src/Shared/Domain/Identifier/MediaId.php` | identifiant partagé, comme `MessageId` |
| `src/Media/Domain/MediaObject.php` | agrégat : les quatre transitions et leurs invariants |
| `src/Media/Domain/MediaStatus.php` | enum `Pending`/`Processing`/`Ready`/`Rejected` |
| `src/Media/Domain/MediaMimeType.php` | enum : l'allowlist, `values()`, `extension()` |
| `src/Media/Domain/MediaRejectionReason.php` | enum des motifs de refus |
| `src/Media/Domain/StorageKey.php` | VO : seul endroit où une clé de stockage se fabrique |
| `src/Media/Domain/MediaRepositoryInterface.php` | port de persistance |
| `src/Media/Domain/MediaNotFoundException.php` | 404 |
| `src/Media/Domain/MediaNotOwnedException.php` | 403 |
| `src/Media/Domain/InvalidMediaTransitionException.php` | garde d'état, jamais atteinte par une entrée utilisateur |
| `src/Media/Application/MediaStorageInterface.php` | port : signer, lire, écrire, effacer |
| `src/Media/Application/ImageInspectorInterface.php` | port : mesurer, miniaturiser |
| `src/Media/Application/Command/RequestMediaUploadCommand.php` + `…Handler.php` | pré-signature |
| `src/Media/Application/Command/ConfirmMediaUploadCommand.php` + `…Handler.php` | `Pending` → `Processing` |
| `src/Media/Application/Command/ProcessMediaCommand.php` + `…Handler.php` | le worker |
| `src/Media/Application/Command/PurgeOrphanMediaCommand.php` + `…Handler.php` | ramasse-miettes |
| `src/Media/Application/Query/GetUploadTicketQuery.php` + `…Handler.php` + `UploadTicket.php` + `UploadTicketReaderInterface.php` | relecture après pré-signature |
| `src/Media/Application/Contract/MediaOwnershipInterface.php` | « ce média est-il à cette personne et libre ? » |
| `src/Media/Application/Contract/MediaFinderInterface.php` | « les `MediaView` de ces ids » |
| `src/Media/Application/Contract/MediaView.php` | **forme figée**, changement cassant |
| `src/Media/Infrastructure/Persistence/DbalMediaRepository.php` + `MediaMapper.php` + `DbalUploadTicketReader.php` + `DbalOrphanMediaReader.php` | SQL |
| `src/Media/Infrastructure/Storage/S3MediaStorage.php` | les deux clients S3 (§5.1) |
| `src/Media/Infrastructure/Image/GdImageInspector.php` | `finfo` + `getimagesize` + miniature |
| `src/Media/Infrastructure/Contract/DbalMediaOwnership.php` + `DbalMediaFinder.php` | réalisation des contrats |
| `src/Media/Infrastructure/Http/RequestMediaUploadController.php` + `ConfirmMediaUploadController.php` | deux routes |
| `src/Media/Infrastructure/Http/Payload/PresignUploadPayload.php` | validation d'entrée |
| `src/Media/Infrastructure/Console/PurgeOrphanMediaConsoleCommand.php` | `media:purge-orphans` |

### Contexte `Message` (modifié)

| Fichier | Changement |
|---|---|
| `src/Message/Domain/Message.php` | `send()` accepte `?MessageContent` + `list<MediaId>` ; `deleteForEveryone()` détache |
| `src/Message/Domain/EmptyMessageException.php` | **nouveau** : ni texte ni média |
| `src/Message/Domain/Port/MediaOwnershipPortInterface.php` | **nouveau** : le besoin, côté consommateur |
| `src/Message/Infrastructure/Contract/MediaOwnershipAdapter.php` | **nouveau** : délègue au contrat de `Media` |
| `src/Message/Infrastructure/Persistence/DbalMessageRepository.php` | écrit et efface `message_media` |
| `src/Message/Application/Query/MessageView.php` | `+ list<MediaView> $media` |
| `src/Message/Infrastructure/Persistence/DbalMessagePageReader.php` + `DbalMessageReader.php` | hydratent les médias |
| `src/Message/Application/EventListener/PropagateMediaReadyListener.php` | **nouveau** : la traduction du §6.1 |

### `Shared` et `Realtime` (modifiés)

| Fichier | Changement |
|---|---|
| `src/Shared/Domain/Event/MediaWasProcessed.php` | **nouveau**, scalaires seuls |
| `src/Shared/Domain/Event/MessageMediaBecameReady.php` | **nouveau** |
| `src/Realtime/Application/EventListener/PublishMessageMediaBecameReadyListener.php` | **nouveau** |

### Frontend

| Fichier | Changement |
|---|---|
| `frontend/src/api/types.ts` | `ApiMedia`, `media` sur `ApiMessage` |
| `frontend/src/api/upload.ts` | **nouveau** : le `PUT` brut, hors du client typé |
| `frontend/src/api/client.ts` | `presignUpload()`, `confirmUpload()` |
| `frontend/src/store/messagesReducer.ts` | `StoredMessage.media`, action `MEDIA_READY` |
| `frontend/src/hooks/useMediaUpload.ts` | **nouveau** : le cycle complet, et la révocation de l'`objectURL` |
| `frontend/src/ui/MessageMedia.tsx` | **nouveau** : les trois états |
| `frontend/src/ui/Composer.tsx` | sélection de fichiers, vignettes en attente |

---

## Task 1 : Infrastructure — paquets, extensions, conteneurs, routage

Tâche délibérément **inerte côté applicatif** : elle porte tout le changement d'infrastructure, ce qui rend les onze suivantes lisibles comme du code métier. Rien de nouveau ne fonctionne à la fin — mais tout est prêt, et les quatre portes sont vertes.

**Files:**
- Modify: `backend/composer.json`
- Modify: `backend/Dockerfile:33-41` (bloc `install-php-extensions`)
- Modify: `compose.yaml`
- Modify: `compose.test.yaml`
- Modify: `infra/caddy/Caddyfile`
- Modify: `backend/config/packages/messenger.yaml`
- Modify: `backend/deptrac.yaml`
- Modify: `backend/deptrac-contexts.yaml`
- Modify: `.env`, `.env.example`
- Create: `infra/rabbitmq/rabbitmq.conf`
- Create: `infra/rabbitmq/definitions.json`

**Interfaces:**
- Consumes: rien.
- Produces: les variables d'environnement `MEDIA_BUCKET`, `MEDIA_S3_INTERNAL_ENDPOINT`, `MEDIA_S3_PUBLIC_ENDPOINT`, `MEDIA_S3_KEY`, `MEDIA_S3_SECRET`, `MESSENGER_TRANSPORT_DSN` — consommées par les tâches 4 et 5. Le transport Messenger nommé `media`, consommé par la tâche 5. Les couches deptrac `Media` et `MediaContract`, consommées par toutes les suivantes.

- [ ] **Step 1: Ajouter les trois paquets**

```bash
make composer-req PACKAGES="aws/aws-sdk-php symfony/amqp-messenger"
make composer-req PACKAGES="zenstruck/messenger-test --dev"
```

- [ ] **Step 2: Déclarer les extensions dans `composer.json`**

Dans le bloc `require`, à leur place alphabétique parmi les `ext-*` existants :

```json
"ext-amqp": "*",
"ext-ctype": "*",
"ext-fileinfo": "*",
"ext-gd": "*",
"ext-iconv": "*",
"ext-redis": "*",
```

Les déclarer rend la dépendance vérifiable par `composer check-platform-reqs` au lieu de tomber à l'exécution.

- [ ] **Step 3: Installer les extensions dans l'image**

Dans `backend/Dockerfile`, le bloc `install-php-extensions` devient :

```dockerfile
# `install-php-extensions` est fourni par l'image FrankenPHP : il installe les
# dépendances système de chaque extension puis les retire après compilation.
#   pdo_pgsql : doctrine/dbal      intl : Symfony
#   zip       : Composer           opcache : toujours
#   redis     : présence éphémère (T2) — état à TTL, jamais en base
#   amqp      : transport Messenger vers RabbitMQ (T4)
#   gd        : mesure d'image et miniature, dans le worker (T4)
#   fileinfo  : type MIME réel des octets reçus — le déclaré n'est jamais cru
RUN install-php-extensions \
        amqp \
        fileinfo \
        gd \
        intl \
        opcache \
        pdo_pgsql \
        redis \
        zip
```

- [ ] **Step 4: Reconstruire l'image et vérifier les extensions**

```bash
make build
docker compose run --rm --no-deps backend php -m | grep -E '^(amqp|gd|fileinfo)$'
```

Expected : les trois lignes `amqp`, `fileinfo`, `gd`.

- [ ] **Step 5: Écrire la topologie RabbitMQ**

`infra/rabbitmq/rabbitmq.conf` :

```
# La topologie est une décision d'infrastructure versionnée, chargée au boot.
# Le transport Messenger est en `auto_setup: false` : l'application ne crée
# jamais de file, elle consomme celles qui sont déclarées ici.
load_definitions = /etc/rabbitmq/definitions.json
```

`infra/rabbitmq/definitions.json` :

```json
{
    "rabbit_version": "4.2.4",
    "rabbitmq_version": "4.2.4",
    "users": [
        {
            "name": "app",
            "password_hash": "yLLK0wnJDPqYbHDLLQMBDSQMDLBLMlBRLpJnJDLBPBLBQnLB",
            "hashing_algorithm": "rabbit_password_hashing_sha256",
            "tags": ["administrator"]
        }
    ],
    "vhosts": [{ "name": "/" }],
    "permissions": [
        { "user": "app", "vhost": "/", "configure": ".*", "write": ".*", "read": ".*" }
    ],
    "parameters": [],
    "global_parameters": [],
    "policies": [],
    "queues": [
        {
            "name": "messaging.media",
            "vhost": "/",
            "durable": true,
            "auto_delete": false,
            "type": "classic",
            "arguments": {}
        }
    ],
    "exchanges": [
        {
            "name": "messaging.media.exchange",
            "vhost": "/",
            "type": "direct",
            "durable": true,
            "auto_delete": false,
            "internal": false,
            "arguments": {}
        }
    ],
    "bindings": [
        {
            "source": "messaging.media.exchange",
            "vhost": "/",
            "destination": "messaging.media",
            "destination_type": "queue",
            "routing_key": "",
            "arguments": {}
        }
    ]
}
```

Le `password_hash` ci-dessus est un exemple **à régénérer** — le hash doit correspondre au mot de passe `app` :

```bash
docker run --rm rabbitmq:4-management rabbitmqctl hash_password app
```

Coller la valeur rendue dans `password_hash`. Un hash qui ne correspond pas fait échouer l'authentification du worker **au premier message**, pas au démarrage.

- [ ] **Step 6: Ajouter les conteneurs**

Dans `compose.yaml`, après le service `redis` :

```yaml
  # Stockage objet compatible S3. La console web (`:9001`) permet de VOIR les
  # objets arriver pendant qu'on teste — c'est la moitié de l'intérêt de MinIO
  # en local. Le port 9000 n'est PAS publié : le navigateur passe par Caddy,
  # qui est l'origine unique du projet (spec §5.2).
  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: "${MEDIA_S3_KEY}"
      MINIO_ROOT_PASSWORD: "${MEDIA_S3_SECRET}"
    ports:
      - "9001:9001"
    volumes:
      - minio_data:/data
    healthcheck:
      test: ["CMD", "mc", "ready", "local"]
      interval: 2s
      timeout: 3s
      retries: 30

  # One-shot : cree le bucket au demarrage puis sort. `anonymous set none` est
  # explicite plutot qu'implicite — un bucket media ne doit JAMAIS etre lisible
  # sans signature, et le dire ici vaut mieux que de supposer le defaut.
  minio-create-bucket:
    image: minio/mc
    depends_on:
      minio:
        condition: service_healthy
    entrypoint: >
      /bin/sh -c "
      mc alias set local http://minio:9000 ${MEDIA_S3_KEY} ${MEDIA_S3_SECRET} &&
      mc mb --ignore-existing local/${MEDIA_BUCKET} &&
      mc anonymous set none local/${MEDIA_BUCKET}
      "

  rabbitmq:
    image: rabbitmq:4-management
    environment:
      RABBITMQ_DEFAULT_USER: app
      RABBITMQ_DEFAULT_PASS: app
    ports:
      - "15672:15672"
    volumes:
      - rabbitmq_data:/var/lib/rabbitmq
      - ./infra/rabbitmq/rabbitmq.conf:/etc/rabbitmq/rabbitmq.conf:ro
      - ./infra/rabbitmq/definitions.json:/etc/rabbitmq/definitions.json:ro
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "ping"]
      interval: 5s
      timeout: 5s
      retries: 20

  # Meme image que `backend`, autre commande. `--time-limit` fait sortir le
  # process toutes les heures : `restart: unless-stopped` le relance, ce qui
  # borne les fuites memoire d'un process PHP longue duree sans supervision.
  worker:
    build:
      context: ./backend
      args:
        USER_ID: "${USER_ID:-1000}"
        GROUP_ID: "${GROUP_ID:-1000}"
    environment:
      APP_ENV: dev
      DATABASE_URL: "postgresql://app:app@postgres:5432/app?serverVersion=17&charset=utf8"
      MERCURE_URL: "http://mercure/.well-known/mercure"
      MERCURE_PUBLIC_URL: "http://localhost:8080/.well-known/mercure"
      MERCURE_JWT_SECRET: "${MERCURE_JWT_SECRET}"
      REDIS_HOST: "redis"
      REDIS_PORT: "6379"
      MESSENGER_TRANSPORT_DSN: "${MESSENGER_TRANSPORT_DSN}"
      MEDIA_BUCKET: "${MEDIA_BUCKET}"
      MEDIA_S3_INTERNAL_ENDPOINT: "${MEDIA_S3_INTERNAL_ENDPOINT}"
      MEDIA_S3_PUBLIC_ENDPOINT: "${MEDIA_S3_PUBLIC_ENDPOINT}"
      MEDIA_S3_KEY: "${MEDIA_S3_KEY}"
      MEDIA_S3_SECRET: "${MEDIA_S3_SECRET}"
    volumes:
      - ./backend:/app
    depends_on:
      postgres:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy
      minio:
        condition: service_healthy
    entrypoint: []
    command: ["php", "bin/console", "messenger:consume", "media", "--time-limit=3600", "-v"]
    restart: unless-stopped
```

Le service `backend` gagne les mêmes cinq variables `MEDIA_*` et `MESSENGER_TRANSPORT_DSN`. Le bloc `volumes:` de fin gagne `minio_data:` et `rabbitmq_data:`.

- [ ] **Step 7: Ajouter les variables d'environnement**

Dans `.env` **et** `.env.example` :

```dotenv
# Stockage objet. `INTERNAL` sert aux appels serveur (worker, purge) ;
# `PUBLIC` sert UNIQUEMENT a signer les URLs que le navigateur ouvrira.
# Une URL pre-signee signe le Host : signer avec `minio:9000` puis appeler
# `localhost:8080` rendrait `SignatureDoesNotMatch` (spec §5.1).
MEDIA_BUCKET=messaging-media
MEDIA_S3_INTERNAL_ENDPOINT=http://minio:9000
MEDIA_S3_PUBLIC_ENDPOINT=http://localhost:8080
MEDIA_S3_KEY=minioadmin
MEDIA_S3_SECRET=minioadmin

MESSENGER_TRANSPORT_DSN=amqp://app:app@rabbitmq:5672/%2f
```

- [ ] **Step 8: Router le stockage derrière l'origine unique**

Dans `infra/caddy/Caddyfile`, **avant** le `handle` fourre-tout :

```caddyfile
	# Le stockage objet passe par l'origine unique, comme tout le reste. Caddy
	# preserve le Host d'origine par defaut : le backend signe donc avec
	# `localhost:8080`, l'hote que le navigateur appelle vraiment, et la
	# signature tient. Consequence : le PUT du navigateur est SAME-ORIGIN.
	# Ni preflight, ni regle CORS sur le bucket, ni entree dans /etc/hosts —
	# les deux pieges de la note vault disparaissent (spec §5.2).
	#
	# Le nom du bucket sert de prefixe de chemin, ce que le path-style endpoint
	# de MinIO donne deja. Aucune reecriture d'URL, donc aucune signature cassee.
	handle /messaging-media/* {
		reverse_proxy minio:9000
	}
```

- [ ] **Step 9: Déclarer le transport Messenger**

Dans `backend/config/packages/messenger.yaml`, remplacer le bloc `transports` et ajouter `routing` :

```yaml
        failure_transport: failed

        transports:
            sync: 'sync://'

            # `auto_setup: false` : la file et l'exchange sont declares dans
            # infra/rabbitmq/definitions.json, charges au boot du hub. La
            # topologie est versionnee, pas improvisee au premier message.
            media:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
                options:
                    auto_setup: false
                    exchange:
                        name: 'messaging.media.exchange'
                    queues:
                        messaging.media: ~

            # Un echec definitif ne doit pas disparaitre : il atterrit en base,
            # ou `messenger:failed:show` le retrouve.
            failed:
                dsn: 'doctrine://default?table_name=messenger_failed'

        routing:
            App\Media\Application\Command\ProcessMediaCommand: media

when@test:
    framework:
        messenger:
            transports:
                # `in-memory` : aucun RabbitMQ n'est necessaire en CI, et
                # zenstruck/messenger-test peut asserter ce qui part sans
                # le consommer.
                media: 'in-memory://'
                failed: 'in-memory://'
```

- [ ] **Step 10: Ouvrir les deux couches deptrac**

Dans `backend/deptrac.yaml`, la couche `Vendor` doit connaître `Aws` — sans quoi `--fail-on-uncovered` échoue au premier `use Aws\S3\S3Client` :

```yaml
        - name: Vendor
          collectors:
              - type: classNameRegex
                value: '#^(Aws|Symfony|Doctrine|Monolog|Lcobucci)\\.*#'
```

Dans `backend/deptrac-contexts.yaml`, même correction sur `Vendor`, puis deux couches et trois lignes de ruleset :

```yaml
        # Surface publiee de Media, symetrique de celles de Conversation et Message.
        - name: MediaContract
          collectors: [{ type: directory, value: 'src/Media/Application/Contract/.*' }]

        - name: Media
          collectors:
              - type: bool
                must:
                    - { type: directory, value: 'src/Media/.*' }
                must_not:
                    - { type: directory, value: 'src/Media/Application/Contract/.*' }
```

```yaml
        MediaContract: [Shared]

        # Media ne connait NI les conversations NI les messages : son allowlist
        # ne cite aucun autre contexte. C'est cette ignorance qui rendra son
        # extraction en service triviale le jour venu (spec §1.1).
        Media: [Shared, MediaContract, Vendor]

        # Message gagne MediaContract : elargir cette ligne est un geste
        # delibere, et le couplage vise une surface publiee.
        Message: [Shared, ConversationContract, MessageContract, MediaContract, Vendor]
```

- [ ] **Step 11: Refléter la stack de test**

Dans `compose.test.yaml`, `backend-test` gagne les cinq `MEDIA_*` — en pointant vers un MinIO de test — et un service `minio-test` calqué sur `minio` (sans port publié, sans volume : les octets de test sont jetables) :

```yaml
      MEDIA_BUCKET: "messaging-media"
      MEDIA_S3_INTERNAL_ENDPOINT: "http://minio-test:9000"
      MEDIA_S3_PUBLIC_ENDPOINT: "http://localhost:8080"
      MEDIA_S3_KEY: "minioadmin"
      MEDIA_S3_SECRET: "minioadmin"
```

```yaml
  # Les tests fonctionnels exercent le VRAI adaptateur S3, comme ils exercent
  # le vrai PostgreSQL. Un double en memoire ne verifierait pas ce qui casse en
  # pratique : la signature, l'expiration et le path-style endpoint.
  minio-test:
    image: minio/minio
    command: server /data
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    tmpfs:
      - /data
    healthcheck:
      test: ["CMD", "mc", "ready", "local"]
      interval: 1s
      timeout: 3s
      retries: 30
```

Dans le `Makefile`, la cible `functional-test` lève `minio-test` avec les autres :

```makefile
	@$(DOCKER_COMPOSE_TEST) up -d --wait postgres-test redis-test minio-test
```

et la commande enchaînée crée le bucket avant les tests :

```makefile
	@$(DOCKER_COMPOSE_TEST_RUN) backend-test sh -c "php bin/console doctrine:database:create --if-not-exists -n -q \
	&& php bin/console doctrine:migrations:migrate -n -q --allow-no-migration \
	&& php bin/console app:fixtures:load -q \
	&& php vendor/bin/phpunit --testsuite=Functional --stop-on-error --stop-on-failure $(ARGS)" ; \
```

Le bucket est créé par le code, pas par `mc` : `S3MediaStorage` (tâche 4) appelle `createBucket` si absent au démarrage en environnement de test. **Ne pas ajouter de conteneur `mc` à la stack de test** — un one-shot supplémentaire à attendre pour chaque exécution.

- [ ] **Step 12: Lever la stack et vérifier**

```bash
make up
make status
```

Expected : `minio`, `rabbitmq` et `worker` en `running`, `minio-create-bucket` sorti en `exited (0)`.

```bash
docker compose logs worker --tail=20
```

Expected : aucune erreur d'authentification AMQP, le consumer attend des messages.

- [ ] **Step 13: Vérifier que le routage Caddy atteint MinIO**

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/messaging-media/
```

Expected : `403` — MinIO répond, et refuse un accès non signé. Un `502` signifierait que Caddy n'atteint pas le conteneur ; un `404` que le `handle` est placé après le fourre-tout.

- [ ] **Step 14: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

Expected : les quatre vertes. `deptrac` en particulier : les nouvelles couches sont déclarées mais encore vides, ce qui est valide.

- [ ] **Step 15: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/Dockerfile backend/config/packages/messenger.yaml \
        backend/deptrac.yaml backend/deptrac-contexts.yaml compose.yaml compose.test.yaml Makefile \
        infra/caddy/Caddyfile infra/rabbitmq/ .env .env.example
git commit -m "chore(medias): monter le stockage objet et la file de traitement

MinIO derriere l'origine unique : Caddy proxifie /messaging-media/* en
preservant le Host, donc le backend signe avec l'hote que le navigateur
appelle. Le PUT devient same-origin — ni CORS sur le bucket, ni entree
dans /etc/hosts.

RabbitMQ avec sa topologie versionnee dans infra/rabbitmq/definitions.json
et le transport en auto_setup: false : l'application ne cree jamais de file.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2 : Le domaine de `Media`

PHP pur, zéro dépendance, testable sans conteneur de base. C'est ici que vivent l'allowlist, le plafond et les quatre transitions.

**Files:**
- Create: `backend/src/Shared/Domain/Identifier/MediaId.php`
- Create: `backend/src/Media/Domain/MediaStatus.php`, `MediaMimeType.php`, `MediaRejectionReason.php`, `StorageKey.php`, `MediaObject.php`, `MediaRepositoryInterface.php`, `MediaNotFoundException.php`, `MediaNotOwnedException.php`, `InvalidMediaTransitionException.php`
- Test: `backend/tests/Unit/Media/Domain/MediaObjectTest.php`, `StorageKeyTest.php`, `MediaMimeTypeTest.php`

**Interfaces:**
- Consumes: `App\Shared\Domain\Identifier\{UserId, AbstractUlidIdentifier}`, `App\Shared\Domain\Event\RecordsEventsTrait`, `App\Shared\Domain\Exception\{NotFoundExceptionInterface, ForbiddenExceptionInterface}` (tâche 1 : rien).
- Produces, consommé par les tâches 3 à 7 :
  - `MediaId::fromString(string): static`
  - `MediaMimeType::values(): list<string>`, `MediaMimeType::extension(): string`, `MediaMimeType::tryFrom(string): ?self`
  - `StorageKey::forOriginal(MediaId, MediaMimeType): self`, `StorageKey::forThumbnail(MediaId): self`, `StorageKey::fromString(string): self`, `->toString(): string`
  - `MediaObject::MAX_BYTES` (int), `::request(...)`, `->markUploaded(...)`, `->markReady(...)`, `->markRejected(...)`, `::reconstitute(...)`, et les accesseurs `id() ownerId() storageKey() thumbnailKey() status() declaredMimeType() declaredSize() mimeType() width() height() byteSize() rejectionReason() createdAt() processedAt()`
  - `MediaRepositoryInterface::{add, ofId, save}`

- [ ] **Step 1: Écrire les tests de `StorageKey` et `MediaMimeType`**

`backend/tests/Unit/Media/Domain/StorageKeyTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageKey::class)]
final class StorageKeyTest extends TestCase
{
    private const string ULID = '01JQZ0000000000000000000AB';

    public function testOriginalKeyCarriesThePrefixAndTheExtension(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString(self::ULID), MediaMimeType::Jpeg);

        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $key->toString());
    }

    public function testThumbnailKeyIsDerivedFromTheSameIdentifier(): void
    {
        $key = StorageKey::forThumbnail(MediaId::fromString(self::ULID));

        // La miniature est toujours du JPEG, quel que soit l'original : c'est
        // le worker qui la produit, il choisit donc son format.
        self::assertSame('media/01JQZ0000000000000000000AB-thumb.jpg', $key->toString());
    }

    public function testAKeyOutsideThePrefixIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Une cle relue depuis la base doit rester dans le prefixe : sans cette
        // garde, une ligne corrompue ferait signer un acces a n'importe quel
        // objet du bucket.
        StorageKey::fromString('../../etc/passwd');
    }
}
```

`backend/tests/Unit/Media/Domain/MediaMimeTypeTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\MediaMimeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaMimeType::class)]
final class MediaMimeTypeTest extends TestCase
{
    public function testTheAllowlistIsExactlyFourImageTypes(): void
    {
        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            MediaMimeType::values(),
        );
    }

    public function testAnythingOutsideTheAllowlistIsUnknown(): void
    {
        self::assertNull(MediaMimeType::tryFrom('application/pdf'));
        self::assertNull(MediaMimeType::tryFrom('image/svg+xml'));
    }

    public function testEachTypeKnowsItsExtension(): void
    {
        self::assertSame('jpg', MediaMimeType::Jpeg->extension());
        self::assertSame('png', MediaMimeType::Png->extension());
        self::assertSame('webp', MediaMimeType::Webp->extension());
        self::assertSame('gif', MediaMimeType::Gif->extension());
    }
}
```

- [ ] **Step 2: Lancer les tests, vérifier qu'ils échouent**

```bash
make unit-test ARGS="--filter='StorageKeyTest|MediaMimeTypeTest'"
```

Expected : FAIL — `Class "App\Media\Domain\StorageKey" not found`.

- [ ] **Step 3: Écrire `MediaId`, les enums et `StorageKey`**

`backend/src/Shared/Domain/Identifier/MediaId.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

final class MediaId extends AbstractUlidIdentifier
{
}
```

`backend/src/Media/Domain/MediaMimeType.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * L'allowlist. « Quels fichiers cette messagerie accepte » est une regle
 * metier, au meme titre que la fenetre d'edition de 15 minutes : elle vit
 * dans le domaine, pas dans la configuration.
 *
 * Les contraintes de validation des charges utiles REFERENCENT `values()`,
 * elles ne redeclarent jamais la liste.
 */
enum MediaMimeType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Gif = 'image/gif';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Gif => 'gif',
        };
    }
}
```

`backend/src/Media/Domain/MediaStatus.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

enum MediaStatus: string
{
    /** Pre-signe : les octets ne sont pas encore arrives. */
    case Pending = 'pending';
    /** Le client a confirme le transfert ; le worker n'a pas encore tranche. */
    case Processing = 'processing';
    case Ready = 'ready';
    case Rejected = 'rejected';

    /** Un etat terminal ne se quitte plus : c'est ce qui borne les transitions. */
    public function isTerminal(): bool
    {
        return self::Ready === $this || self::Rejected === $this;
    }
}
```

`backend/src/Media/Domain/MediaRejectionReason.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Un rejet est un etat legitime, pas une erreur (spec §1.4). Le motif est
 * conserve : il alimente le log et pourra un jour nourrir un message
 * d'interface plus precis que « fichier refuse ».
 */
enum MediaRejectionReason: string
{
    /** Les octets ne sont jamais arrives dans le bucket. */
    case MissingObject = 'missing_object';
    /** Le type REEL des octets n'est pas dans l'allowlist. */
    case UnsupportedType = 'unsupported_type';
    /** La taille reelle depasse le plafond — verifiable seulement apres transfert. */
    case TooLarge = 'too_large';
    /** Le type est bon mais le decodage echoue : fichier tronque ou corrompu. */
    case Undecodable = 'undecodable';
}
```

`backend/src/Media/Domain/StorageKey.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Identifier\MediaId;

/**
 * Seul endroit du projet ou une cle de stockage se fabrique — meme role que
 * `Topic` pour les canaux Mercure. Un `sprintf` disperse dans un adaptateur
 * serait un bug de securite silencieux : une cle mal formee ferait signer
 * l'acces au mauvais objet.
 *
 * Constructeur prive : on ne fabrique une cle que par un constructeur nomme,
 * jamais depuis une chaine arbitraire — sauf `fromString()`, qui relit ce que
 * la base a stocke et re-valide le prefixe.
 */
final readonly class StorageKey implements \Stringable
{
    private const string PREFIX = 'media/';

    /** Prefixe, ULID, suffixe optionnel, extension. Rien d'autre ne passe. */
    private const string PATTERN = '/\Amedia\/[0-7][0-9A-HJKMNP-TV-Z]{25}(-thumb)?\.(jpg|png|webp|gif)\z/';

    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function forOriginal(MediaId $mediaId, MediaMimeType $mimeType): self
    {
        return new self(sprintf('%s%s.%s', self::PREFIX, $mediaId->toString(), $mimeType->extension()));
    }

    /**
     * La miniature est toujours du JPEG : c'est le worker qui la produit, donc
     * il choisit son format. Le format de l'original n'y change rien.
     */
    public static function forThumbnail(MediaId $mediaId): self
    {
        return new self(sprintf('%s%s-thumb.jpg', self::PREFIX, $mediaId->toString()));
    }

    /**
     * Relecture depuis la base. La re-validation n'est pas de la paranoia :
     * sans elle, une ligne corrompue ferait signer un acces a un objet
     * arbitraire du bucket.
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Cette cle de stockage ne respecte pas le format attendu.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 4: Relancer les deux tests**

```bash
make unit-test ARGS="--filter='StorageKeyTest|MediaMimeTypeTest'"
```

Expected : PASS.

- [ ] **Step 5: Écrire le test de l'agrégat**

`backend/tests/Unit/Media/Domain/MediaObjectTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\InvalidMediaTransitionException;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Event\MediaWasProcessed;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaObject::class)]
final class MediaObjectTest extends TestCase
{
    private const string MEDIA_ID = '01JQZ0000000000000000000AB';
    private const string OWNER_ID = '01JQZ0000000000000000000CD';

    public function testARequestedMediaIsPendingAndRecordsNothing(): void
    {
        $media = $this->request();

        self::assertSame(MediaStatus::Pending, $media->status());
        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $media->storageKey()->toString());
        self::assertNull($media->mimeType());
        // La pre-signature n'est pas un fait metier : personne n'a a en etre
        // averti tant que rien n'a ete televerse.
        self::assertSame([], $media->releaseEvents());
    }

    public function testConfirmingTheUploadMovesToProcessing(): void
    {
        $media = $this->request();

        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        self::assertSame(MediaStatus::Processing, $media->status());
    }

    public function testConfirmingTwiceIsANoOp(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));
        $media->markReady(
            MediaMimeType::Jpeg,
            1600,
            900,
            120_000,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );
        $media->releaseEvents();

        // Un reessai reseau du client ne doit produire NI seconde transition,
        // NI second traitement. Meme mecanique d'idempotence que le rejeu
        // d'envoi cote Message : rien d'enregistre, donc rien de republie.
        $media->markUploaded($this->at('2026-07-26T10:00:10+00:00'));

        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame([], $media->releaseEvents());
    }

    public function testBecomingReadyRecordsTheFactWithMeasuredValues(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        $media->markReady(
            MediaMimeType::Png,
            1600,
            900,
            120_000,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );

        self::assertSame(MediaStatus::Ready, $media->status());
        // Le type CONSTATE remplace le declare comme source de verite, et les
        // deux restent cote a cote : l'ecart est observable.
        self::assertSame(MediaMimeType::Png, $media->mimeType());
        self::assertSame(MediaMimeType::Jpeg, $media->declaredMimeType());
        self::assertSame(1600, $media->width());

        $events = $media->releaseEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MediaWasProcessed::class, $event);
        self::assertSame('ready', $event->status);
        self::assertSame('image/png', $event->mimeType);
        self::assertSame(1600, $event->width);
    }

    public function testARejectedMediaKeepsItsReasonAndAnnouncesItself(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));

        $media->markRejected(MediaRejectionReason::UnsupportedType, $this->at('2026-07-26T10:00:05+00:00'));

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());

        $events = $media->releaseEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MediaWasProcessed::class, $event);
        // Le front doit apprendre le refus : sans cet evenement, le message
        // resterait « en cours… » pour toujours.
        self::assertSame('rejected', $event->status);
        self::assertNull($event->mimeType);
    }

    public function testAReadyMediaCannotBeReprocessed(): void
    {
        $media = $this->request();
        $media->markUploaded($this->at('2026-07-26T10:00:00+00:00'));
        $media->markReady(
            MediaMimeType::Jpeg,
            10,
            10,
            100,
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            $this->at('2026-07-26T10:00:05+00:00'),
        );

        $this->expectException(InvalidMediaTransitionException::class);

        $media->markRejected(MediaRejectionReason::TooLarge, $this->at('2026-07-26T10:00:10+00:00'));
    }

    public function testReconstituteRecordsNothing(): void
    {
        $media = MediaObject::reconstitute(
            MediaId::fromString(self::MEDIA_ID),
            UserId::fromString(self::OWNER_ID),
            StorageKey::forOriginal(MediaId::fromString(self::MEDIA_ID), MediaMimeType::Jpeg),
            StorageKey::forThumbnail(MediaId::fromString(self::MEDIA_ID)),
            MediaStatus::Ready,
            MediaMimeType::Jpeg,
            2_000,
            MediaMimeType::Jpeg,
            10,
            10,
            100,
            null,
            $this->at('2026-07-26T09:00:00+00:00'),
            $this->at('2026-07-26T09:00:05+00:00'),
        );

        // Comme Message::reconstitute() : c'est par la qu'un rejeu ne republie
        // rien. Ne pas ajouter d'enregistrement ici.
        self::assertSame([], $media->releaseEvents());
    }

    private function request(): MediaObject
    {
        $mediaId = MediaId::fromString(self::MEDIA_ID);

        return MediaObject::request(
            $mediaId,
            UserId::fromString(self::OWNER_ID),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            $this->at('2026-07-26T09:59:00+00:00'),
        );
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
```

- [ ] **Step 6: Lancer, vérifier l'échec**

```bash
make unit-test ARGS="--filter=MediaObjectTest"
```

Expected : FAIL — `Class "App\Media\Domain\MediaObject" not found`.

- [ ] **Step 7: Écrire l'événement partagé**

`backend/src/Shared/Domain/Event/MediaWasProcessed.php` :

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\MediaId;

/**
 * Emis par Media, ecoute par Message (qui le traduit en fait metier).
 *
 * Charge utile en SCALAIRES uniquement : `status` et `mimeType` voyagent en
 * `string`, pas en MediaStatus ni MediaMimeType. L'inverse ferait dependre
 * Shared du contexte Media (ADR 0001).
 *
 * Ni cle de stockage, ni URL signee : une URL vit 15 minutes, la mettre dans
 * un evenement serait y mettre quelque chose de perimable.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MediaWasProcessed implements DomainEventInterface
{
    public function __construct(
        public MediaId $mediaId,
        public string $status,
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public ?int $byteSize,
        public \DateTimeImmutable $processedAt,
    ) {
    }
}
```

- [ ] **Step 8: Écrire l'agrégat et ses exceptions**

`backend/src/Media/Domain/InvalidMediaTransitionException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Garde d'etat interne. AUCUNE entree utilisateur ne doit pouvoir l'atteindre
 * — les routes passent par des transitions idempotentes. Elle n'implemente
 * donc pas d'interface de traduction HTTP : si elle sort, c'est un 500, et
 * c'est correct.
 */
final class InvalidMediaTransitionException extends \LogicException
{
    public static function from(MediaStatus $from, MediaStatus $to): self
    {
        return new self(sprintf('Transition interdite de %s vers %s.', $from->value, $to->value));
    }
}
```

`backend/src/Media/Domain/MediaNotFoundException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Exception\NotFoundExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

final class MediaNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function withId(MediaId $mediaId): self
    {
        return new self(sprintf('Le media %s est introuvable.', $mediaId->toString()));
    }
}
```

`backend/src/Media/Domain/MediaNotOwnedException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;
use App\Shared\Domain\Identifier\MediaId;

final class MediaNotOwnedException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function forMedia(MediaId $mediaId): self
    {
        return new self(sprintf('Le media %s ne vous appartient pas.', $mediaId->toString()));
    }

    public function problemSlug(): string
    {
        return 'media-not-owned';
    }

    public function problemTitle(): string
    {
        return 'Media non possede';
    }
}
```

`backend/src/Media/Domain/MediaObject.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Event\MediaWasProcessed;
use App\Shared\Domain\Event\RecordsEventsTrait;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Un objet televerse, et rien de plus. Cet agregat IGNORE l'existence des
 * messages et des conversations : il connait un proprietaire et des octets.
 * C'est cette ignorance qui rendra l'extraction du contexte en service
 * triviale le jour venu (spec §1.1) — ne pas l'entamer.
 */
final class MediaObject
{
    use RecordsEventsTrait;

    /** Dix mebioctets. Regle metier, pas reglage d'exploitation. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private function __construct(
        private readonly MediaId $id,
        private readonly UserId $ownerId,
        private readonly StorageKey $storageKey,
        private ?StorageKey $thumbnailKey,
        private MediaStatus $status,
        private readonly MediaMimeType $declaredMimeType,
        private readonly int $declaredSize,
        private ?MediaMimeType $mimeType,
        private ?int $width,
        private ?int $height,
        private ?int $byteSize,
        private ?MediaRejectionReason $rejectionReason,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $processedAt,
    ) {
    }

    public static function request(
        MediaId $id,
        UserId $ownerId,
        StorageKey $storageKey,
        MediaMimeType $declaredMimeType,
        int $declaredSize,
        \DateTimeImmutable $now,
    ): self {
        // AUCUN evenement : la pre-signature n'est pas un fait metier. Personne
        // n'a a en etre averti tant que rien n'a ete televerse.
        return new self(
            $id, $ownerId, $storageKey, null, MediaStatus::Pending,
            $declaredMimeType, $declaredSize,
            null, null, null, null, null, $now, null,
        );
    }

    /** @see MediaObjectTest::testReconstituteRecordsNothing() — ne rien enregistrer ici. */
    public static function reconstitute(
        MediaId $id,
        UserId $ownerId,
        StorageKey $storageKey,
        ?StorageKey $thumbnailKey,
        MediaStatus $status,
        MediaMimeType $declaredMimeType,
        int $declaredSize,
        ?MediaMimeType $mimeType,
        ?int $width,
        ?int $height,
        ?int $byteSize,
        ?MediaRejectionReason $rejectionReason,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $processedAt,
    ): self {
        return new self(
            $id, $ownerId, $storageKey, $thumbnailKey, $status,
            $declaredMimeType, $declaredSize,
            $mimeType, $width, $height, $byteSize, $rejectionReason, $createdAt, $processedAt,
        );
    }

    /**
     * Rejouable sans condition dans le controleur : un media deja au-dela de
     * `Pending` ressort inchange, sans evenement, donc sans second traitement.
     */
    public function markUploaded(\DateTimeImmutable $now): void
    {
        if (MediaStatus::Pending !== $this->status) {
            return;
        }

        $this->status = MediaStatus::Processing;
    }

    public function markReady(
        MediaMimeType $mimeType,
        int $width,
        int $height,
        int $byteSize,
        StorageKey $thumbnailKey,
        \DateTimeImmutable $now,
    ): void {
        $this->guardNotTerminal(MediaStatus::Ready);

        $this->status = MediaStatus::Ready;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
        $this->byteSize = $byteSize;
        $this->thumbnailKey = $thumbnailKey;
        $this->processedAt = $now;

        $this->recordEvent(new MediaWasProcessed(
            $this->id, $this->status->value, $mimeType->value, $width, $height, $byteSize, $now,
        ));
    }

    public function markRejected(MediaRejectionReason $reason, \DateTimeImmutable $now): void
    {
        $this->guardNotTerminal(MediaStatus::Rejected);

        $this->status = MediaStatus::Rejected;
        $this->rejectionReason = $reason;
        $this->processedAt = $now;

        // Le refus est annonce comme la reussite : sans cet evenement, un
        // message porteur resterait « en cours… » pour toujours.
        $this->recordEvent(new MediaWasProcessed(
            $this->id, $this->status->value, null, null, null, null, $now,
        ));
    }

    public function id(): MediaId
    {
        return $this->id;
    }

    public function ownerId(): UserId
    {
        return $this->ownerId;
    }

    public function storageKey(): StorageKey
    {
        return $this->storageKey;
    }

    public function thumbnailKey(): ?StorageKey
    {
        return $this->thumbnailKey;
    }

    public function status(): MediaStatus
    {
        return $this->status;
    }

    public function declaredMimeType(): MediaMimeType
    {
        return $this->declaredMimeType;
    }

    public function declaredSize(): int
    {
        return $this->declaredSize;
    }

    public function mimeType(): ?MediaMimeType
    {
        return $this->mimeType;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function byteSize(): ?int
    {
        return $this->byteSize;
    }

    public function rejectionReason(): ?MediaRejectionReason
    {
        return $this->rejectionReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    private function guardNotTerminal(MediaStatus $to): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidMediaTransitionException::from($this->status, $to);
        }
    }
}
```

`backend/src/Media/Domain/MediaRepositoryInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Identifier\MediaId;

interface MediaRepositoryInterface
{
    public function add(MediaObject $media): void;

    /** @throws MediaNotFoundException */
    public function ofId(MediaId $mediaId): MediaObject;

    public function save(MediaObject $media): void;
}
```

- [ ] **Step 9: Relancer les tests unitaires du domaine**

```bash
make unit-test ARGS="--filter='MediaObjectTest|StorageKeyTest|MediaMimeTypeTest'"
```

Expected : PASS, 12 tests.

- [ ] **Step 10: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

`deptrac` est le contrôle qui compte ici : `Media/Domain/` ne doit citer que `App\Shared\Domain\…`. Un seul `use Symfony\` ou `use Aws\` fait échouer le build.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Shared/Domain/Identifier/MediaId.php backend/src/Shared/Domain/Event/MediaWasProcessed.php \
        backend/src/Media/Domain/ backend/tests/Unit/Media/
git commit -m "feat(medias): poser le domaine du contexte Media

Agregat MediaObject et ses quatre transitions. markUploaded() sur un media
deja au-dela de Pending est un no-op : c'est ce qui rend la confirmation
rejouable sans condition dans le controleur.

MediaWasProcessed ne transporte que des scalaires — ni cle de stockage, ni
URL signee : une URL vit 15 minutes, un evenement ne porte pas de perimable.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3 : Persistance de `Media` — migration, mapper, repository

**Files:**
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (générée)
- Create: `backend/src/Media/Infrastructure/Persistence/MediaMapper.php`, `DbalMediaRepository.php`
- Modify: `backend/config/services.yaml` (alias du port)
- Test: `backend/tests/Functional/Media/MediaRepositoryTest.php`

**Interfaces:**
- Consumes: tout ce que produit la tâche 2.
- Produces: `MediaMapper::fromRow(array): MediaObject` avec la forme de ligne
  `array{id: string, owner_id: string, storage_key: string, thumbnail_key: string|null, status: string, declared_mime_type: string, declared_size: int, mime_type: string|null, width: int|null, height: int|null, byte_size: int|null, rejection_reason: string|null, created_at: string, processed_at: string|null}` — **cette forme est réutilisée telle quelle** par les tâches 4, 5 et 11.

- [ ] **Step 1: Générer la migration**

```bash
make generate-migration
```

- [ ] **Step 2: Écrire le SQL de la migration**

Dans la classe générée. `getDescription()` d'abord, puis `up()` et `down()` :

```php
    public function getDescription(): string
    {
        return 'Tranche 4 : media_objects, message_media, et le CHECK des tombstones relache.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE media_objects (
                id                  CHAR(26)    PRIMARY KEY,
                owner_id            CHAR(26)    NOT NULL REFERENCES users(id),
                storage_key         TEXT        NOT NULL UNIQUE,
                thumbnail_key       TEXT,
                status              TEXT        NOT NULL,
                declared_mime_type  TEXT        NOT NULL,
                declared_size       INTEGER     NOT NULL,
                mime_type           TEXT,
                width               INTEGER,
                height              INTEGER,
                byte_size           INTEGER,
                rejection_reason    TEXT,
                created_at          TIMESTAMPTZ NOT NULL,
                processed_at        TIMESTAMPTZ,

                CONSTRAINT media_ready_is_measured CHECK (
                    status <> 'ready'
                    OR (mime_type IS NOT NULL AND width IS NOT NULL AND height IS NOT NULL
                        AND byte_size IS NOT NULL AND thumbnail_key IS NOT NULL)
                ),
                CONSTRAINT media_rejected_has_reason CHECK (
                    status <> 'rejected' OR rejection_reason IS NOT NULL
                )
            )
            SQL);

        // Index PARTIEL : la purge des orphelins ne balaie que le non-terminal,
        // qui reste minoritaire. Un index complet sur created_at grossirait
        // avec l'historique pour une requete qui ne le lit jamais.
        $this->addSql(<<<'SQL'
            CREATE INDEX media_objects_pending_idx ON media_objects (created_at)
            WHERE status IN ('pending', 'processing')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE message_media (
                message_id CHAR(26) NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                media_id   CHAR(26) NOT NULL REFERENCES media_objects(id),
                position   SMALLINT NOT NULL,
                PRIMARY KEY (message_id, media_id),
                UNIQUE (media_id),
                UNIQUE (message_id, position)
            )
            SQL);

        // T3 posait une EQUIVALENCE entre « supprime » et « sans contenu ».
        // Un message qui n'a jamais porte que des images la viole. On la
        // relache en implication : « un tombstone n'a pas de contenu » reste
        // garanti, « un message sans contenu est un tombstone » cesse de
        // l'etre — c'est exactement ce que la tranche rend faux (spec §2.4).
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages ADD CONSTRAINT messages_tombstone_has_no_payload
            CHECK (deleted_at IS NULL OR content IS NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Restaurer l'equivalence exige qu'aucun message image-seule ne
        // subsiste : on leur redonne un contenu, comme la migration de T3
        // le faisait pour les tombstones.
        $this->addSql(<<<'SQL'
            UPDATE messages SET content = '(image)'
            WHERE content IS NULL AND deleted_at IS NULL
            SQL);
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages ADD CONSTRAINT messages_tombstone_has_no_payload
            CHECK ((deleted_at IS NULL) = (content IS NOT NULL))
            SQL);
        $this->addSql('DROP TABLE message_media');
        $this->addSql('DROP TABLE media_objects');
    }
```

- [ ] **Step 3: Jouer la migration sur la base de dev**

```bash
make migrate
make migration-status
```

Expected : la nouvelle version en `migrated`.

- [ ] **Step 4: Écrire le test fonctionnel du repository**

`backend/tests/Functional/Media/MediaRepositoryTest.php` — calqué sur les tests fonctionnels existants (`WebTestCase`, conteneur réel, vraie base) :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaRepositoryTest extends KernelTestCase
{
    public function testAMediaSurvivesARoundTrip(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var MediaRepositoryInterface $repository */
        $repository = $container->get(MediaRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $ownerId = $this->anyUserId($connection);
        $mediaId = MediaId::fromString('01JQZ0000000000000000000AB');

        $media = MediaObject::request(
            $mediaId,
            $ownerId,
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);

        $reloaded = $repository->ofId($mediaId);

        self::assertSame(MediaStatus::Pending, $reloaded->status());
        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $reloaded->storageKey()->toString());
        self::assertTrue($reloaded->ownerId()->equals($ownerId));
        self::assertNull($reloaded->mimeType());
    }

    public function testTheDatabaseRefusesAReadyMediaWithoutMeasurements(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $this->expectExceptionMessageMatches('/media_ready_is_measured/');

        // L'invariant « un media pret est un media mesure » vit dans le schema,
        // pas seulement dans l'agregat : aucun chemin de code ne peut
        // l'enfreindre en silence.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO media_objects (id, owner_id, storage_key, status, declared_mime_type, declared_size, created_at)
                VALUES (:id, :owner_id, :storage_key, 'ready', 'image/jpeg', 2000, NOW())
                SQL,
            [
                'id' => '01JQZ0000000000000000000CD',
                'owner_id' => $this->anyUserId($connection)->toString(),
                'storage_key' => 'media/01JQZ0000000000000000000CD.jpg',
            ],
        );
    }

    public function testARejectedMediaKeepsItsReasonThroughPersistence(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var MediaRepositoryInterface $repository */
        $repository = $container->get(MediaRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $mediaId = MediaId::fromString('01JQZ0000000000000000000EF');
        $media = MediaObject::request(
            $mediaId,
            $this->anyUserId($connection),
            StorageKey::forOriginal($mediaId, MediaMimeType::Png),
            MediaMimeType::Png,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $repository->add($media);

        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));
        $media->markRejected(MediaRejectionReason::UnsupportedType, new \DateTimeImmutable('2026-07-26T09:00:20+00:00'));
        $repository->save($media);

        $reloaded = $repository->ofId($mediaId);

        self::assertSame(MediaStatus::Rejected, $reloaded->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $reloaded->rejectionReason());
    }

    private function anyUserId(Connection $connection): UserId
    {
        /** @var string $id */
        $id = $connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return UserId::fromString($id);
    }
}
```

- [ ] **Step 5: Lancer, vérifier l'échec**

```bash
make functional-test ARGS="--filter=MediaRepositoryTest"
```

Expected : FAIL — service `MediaRepositoryInterface` introuvable.

- [ ] **Step 6: Écrire le mapper**

`backend/src/Media/Infrastructure/Persistence/MediaMapper.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/** Frontiere unique ou la ligne brute devient un type precis (PHPStan max). */
final readonly class MediaMapper
{
    /**
     * @param array{id: string, owner_id: string, storage_key: string, thumbnail_key: string|null,
     *              status: string, declared_mime_type: string, declared_size: int,
     *              mime_type: string|null, width: int|null, height: int|null, byte_size: int|null,
     *              rejection_reason: string|null, created_at: string, processed_at: string|null} $row
     */
    public function fromRow(array $row): MediaObject
    {
        return MediaObject::reconstitute(
            MediaId::fromString($row['id']),
            UserId::fromString($row['owner_id']),
            StorageKey::fromString($row['storage_key']),
            null === $row['thumbnail_key'] ? null : StorageKey::fromString($row['thumbnail_key']),
            // `from` et non `tryFrom` : une valeur inconnue en base est une
            // corruption, pas un cas metier. Elle doit exploser bruyamment.
            MediaStatus::from($row['status']),
            MediaMimeType::from($row['declared_mime_type']),
            $row['declared_size'],
            null === $row['mime_type'] ? null : MediaMimeType::from($row['mime_type']),
            $row['width'],
            $row['height'],
            $row['byte_size'],
            null === $row['rejection_reason'] ? null : MediaRejectionReason::from($row['rejection_reason']),
            new \DateTimeImmutable($row['created_at']),
            null === $row['processed_at'] ? null : new \DateTimeImmutable($row['processed_at']),
        );
    }
}
```

- [ ] **Step 7: Écrire le repository**

`backend/src/Media/Infrastructure/Persistence/DbalMediaRepository.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Domain\MediaNotFoundException;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\MediaId;
use Doctrine\DBAL\Connection;

final readonly class DbalMediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private MediaMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function add(MediaObject $media): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO media_objects (id, owner_id, storage_key, status, declared_mime_type, declared_size, created_at)
                VALUES (:id, :owner_id, :storage_key, :status, :declared_mime_type, :declared_size, :created_at)
                SQL,
            [
                'id' => $media->id()->toString(),
                'owner_id' => $media->ownerId()->toString(),
                'storage_key' => $media->storageKey()->toString(),
                'status' => $media->status()->value,
                'declared_mime_type' => $media->declaredMimeType()->value,
                'declared_size' => $media->declaredSize(),
                'created_at' => $media->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        $this->collector->collect(...$media->releaseEvents());
    }

    public function ofId(MediaId $mediaId): MediaObject
    {
        /** @var array{id: string, owner_id: string, storage_key: string, thumbnail_key: string|null, status: string, declared_mime_type: string, declared_size: int, mime_type: string|null, width: int|null, height: int|null, byte_size: int|null, rejection_reason: string|null, created_at: string, processed_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, owner_id, storage_key, thumbnail_key, status, declared_mime_type, declared_size,
                       mime_type, width, height, byte_size, rejection_reason, created_at, processed_at
                FROM media_objects
                WHERE id = :id
                SQL,
            ['id' => $mediaId->toString()],
        );

        if (false === $row) {
            throw MediaNotFoundException::withId($mediaId);
        }

        return $this->mapper->fromRow($row);
    }

    public function save(MediaObject $media): void
    {
        // Seules les colonnes mutables : l'id, le proprietaire, la cle de
        // l'original, le declare et l'instant de creation ne sont pas
        // remplacables. Un media ne change pas de proprietaire.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE media_objects
                SET status = :status,
                    thumbnail_key = :thumbnail_key,
                    mime_type = :mime_type,
                    width = :width,
                    height = :height,
                    byte_size = :byte_size,
                    rejection_reason = :rejection_reason,
                    processed_at = :processed_at
                WHERE id = :id
                SQL,
            [
                'status' => $media->status()->value,
                'thumbnail_key' => $media->thumbnailKey()?->toString(),
                'mime_type' => $media->mimeType()?->value,
                'width' => $media->width(),
                'height' => $media->height(),
                'byte_size' => $media->byteSize(),
                'rejection_reason' => $media->rejectionReason()?->value,
                'processed_at' => $media->processedAt()?->format(\DateTimeInterface::ATOM),
                'id' => $media->id()->toString(),
            ],
        );

        $this->collector->collect(...$media->releaseEvents());
    }
}
```

- [ ] **Step 8: Câbler le port**

Dans `backend/config/services.yaml`, à la suite des autres alias de ports :

```yaml
    App\Media\Domain\MediaRepositoryInterface: '@App\Media\Infrastructure\Persistence\DbalMediaRepository'
```

- [ ] **Step 9: Relancer le test fonctionnel**

```bash
make functional-test ARGS="--filter=MediaRepositoryTest"
```

Expected : PASS, 3 tests.

- [ ] **Step 10: Vérifier que T3 n'a rien perdu**

```bash
make functional-test ARGS="--filter='DeleteMessageTest|EditMessageTest'"
```

Expected : PASS. Le `CHECK` relâché ne doit rien casser des comportements de T3 — c'est le seul risque de cette migration.

- [ ] **Step 11: Les quatre portes, puis commit**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
git add backend/migrations/ backend/src/Media/Infrastructure/Persistence/ backend/config/services.yaml backend/tests/Functional/Media/
git commit -m "feat(medias): persister les objets televerses

Le CHECK media_ready_is_measured porte l'invariant « un media pret est un
media mesure » dans le schema : aucun chemin de code ne peut l'enfreindre
en silence.

Le CHECK des tombstones pose par T3 etait une equivalence, qu'un message
image-seule viole. Il devient une implication : « un tombstone n'a pas de
contenu » reste garanti, l'inverse cesse de l'etre.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4 : Stockage objet et les deux routes d'upload

C'est la tâche où « les octets ne passent jamais par PHP » devient du code. À la fin, un `curl` peut téléverser une image de bout en bout — sans qu'aucun octet ait traversé le backend.

**Files:**
- Create: `backend/src/Media/Application/MediaStorageInterface.php`
- Create: `backend/src/Media/Application/Command/RequestMediaUploadCommand.php`, `RequestMediaUploadCommandHandler.php`, `ConfirmMediaUploadCommand.php`, `ConfirmMediaUploadCommandHandler.php`
- Create: `backend/src/Media/Application/Query/GetUploadTicketQuery.php`, `GetUploadTicketQueryHandler.php`, `UploadTicket.php`, `UploadTicketReaderInterface.php`
- Create: `backend/src/Media/Infrastructure/Storage/S3MediaStorage.php`
- Create: `backend/src/Media/Infrastructure/Persistence/DbalUploadTicketReader.php`
- Create: `backend/src/Media/Infrastructure/Http/RequestMediaUploadController.php`, `ConfirmMediaUploadController.php`, `Payload/PresignUploadPayload.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Functional/Media/UploadFlowTest.php`

**Interfaces:**
- Consumes: tâches 2 et 3.
- Produces, consommé par les tâches 5, 7 et 11 :
  - `MediaStorageInterface::presignUpload(StorageKey, MediaMimeType, \DateTimeImmutable $now): string`
  - `MediaStorageInterface::presignDownload(StorageKey, \DateTimeImmutable $now): string`
  - `MediaStorageInterface::downloadToTemporaryFile(StorageKey): ?string` — `null` si l'objet est absent
  - `MediaStorageInterface::put(StorageKey, string $localPath, MediaMimeType): void`
  - `MediaStorageInterface::delete(StorageKey): void`
  - `UploadTicket` : `readonly` avec `string $mediaId`, `string $uploadUrl`, `string $expiresAt`, `toArray(): array<string, string>`

- [ ] **Step 1: Écrire le test fonctionnel du flux complet**

`backend/tests/Functional/Media/UploadFlowTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Shared\Domain\Identifier\MediaId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UploadFlowTest extends WebTestCase
{
    public function testPresigningReturnsAUsableUrlAndLeavesTheMediaPending(): void
    {
        $client = $this->loggedInAsAlice();

        $client->jsonRequest('POST', '/api/media', [
            'filename' => 'photo.jpg',
            'content_type' => 'image/jpeg',
            'size' => 2_048,
        ]);

        self::assertResponseStatusCodeSame(201);
        /** @var array{media_id: string, upload_url: string, expires_at: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // La signature couvre la methode, la cle ET le Content-Type. L'URL
        // porte donc les parametres AWS SigV4, et vise l'origine unique — pas
        // `minio:9000`, que le navigateur ne sait pas joindre (spec §5.1).
        self::assertStringContainsString('X-Amz-Signature=', $body['upload_url']);
        self::assertStringStartsWith('http://localhost:8080/messaging-media/media/', $body['upload_url']);

        /** @var MediaRepositoryInterface $repository */
        $repository = self::getContainer()->get(MediaRepositoryInterface::class);
        self::assertSame(MediaStatus::Pending, $repository->ofId(MediaId::fromString($body['media_id']))->status());
    }

    public function testATypeOutsideTheAllowlistIsRefusedWithAViolation(): void
    {
        $client = $this->loggedInAsAlice();

        $client->jsonRequest('POST', '/api/media', [
            'filename' => 'contrat.pdf',
            'content_type' => 'application/pdf',
            'size' => 2_048,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        /** @var array{type: string, violations: list<array{field: string, message: string}>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/problems/validation-failed', $body['type']);
        // Le client doit savoir QUEL champ corriger.
        self::assertSame('content_type', $body['violations'][0]['field']);
    }

    public function testASizeAboveTheCapIsRefusedBeforeAnyTransfer(): void
    {
        $client = $this->loggedInAsAlice();

        $client->jsonRequest('POST', '/api/media', [
            'filename' => 'enorme.jpg',
            'content_type' => 'image/jpeg',
            'size' => 11 * 1024 * 1024,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testConfirmingTheUploadIsIdempotent(): void
    {
        $client = $this->loggedInAsAlice();
        $mediaId = $this->presign($client);

        $client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));
        self::assertResponseStatusCodeSame(204);

        // Rejouer ne doit produire NI erreur, NI second traitement.
        $client->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));
        self::assertResponseStatusCodeSame(204);

        /** @var MediaRepositoryInterface $repository */
        $repository = self::getContainer()->get(MediaRepositoryInterface::class);
        self::assertSame(MediaStatus::Processing, $repository->ofId(MediaId::fromString($mediaId))->status());
    }

    public function testConfirmingSomeoneElsesMediaIsForbidden(): void
    {
        $client = $this->loggedInAsAlice();
        $mediaId = $this->presign($client);

        $bob = $this->loggedInAsBob();
        $bob->request('POST', sprintf('/api/media/%s/uploaded', $mediaId));

        self::assertResponseStatusCodeSame(403);
        /** @var array{type: string} $body */
        $body = json_decode((string) $bob->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/problems/media-not-owned', $body['type']);
    }

    public function testAnUnknownMediaIsNotFound(): void
    {
        $client = $this->loggedInAsAlice();

        $client->request('POST', '/api/media/01JQZ0000000000000000000ZZ/uploaded');

        self::assertResponseStatusCodeSame(404);
    }

    private function presign(KernelBrowser $client): string
    {
        $client->jsonRequest('POST', '/api/media', [
            'filename' => 'photo.jpg',
            'content_type' => 'image/jpeg',
            'size' => 2_048,
        ]);
        /** @var array{media_id: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body['media_id'];
    }
}
```

**Les helpers `loggedInAsAlice()` / `loggedInAsBob()` existent déjà** dans les tests fonctionnels de `Message` — reprendre exactement leur implémentation (fixtures `alice` / `bob`), ne pas en inventer une variante.

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
make functional-test ARGS="--filter=UploadFlowTest"
```

Expected : FAIL — 404 sur `/api/media`, la route n'existe pas.

- [ ] **Step 3: Écrire le port de stockage**

`backend/src/Media/Application/MediaStorageInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;

/**
 * Le besoin, exprime sans nommer S3. `Application` ne connait ni `Aws\`, ni
 * la notion de bucket, ni celle d'endpoint : elle sait signer, lire, ecrire,
 * effacer. L'adaptateur decide comment.
 */
interface MediaStorageInterface
{
    /**
     * URL signee pour un PUT. La signature couvre la methode, la cle ET le
     * Content-Type : le client DOIT envoyer exactement ce type, sinon MinIO
     * refuse. Elle ne plafonne pas la taille — une URL pre-signee PUT ne le
     * peut pas (spec §3.2), c'est le worker qui tranche apres transfert.
     *
     * @return non-empty-string
     */
    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): string;

    /** @return non-empty-string URL signee pour un GET */
    public function presignDownload(StorageKey $key, \DateTimeImmutable $now): string;

    /**
     * Rapatrie l'objet dans un fichier temporaire local et rend son chemin.
     * PAS en memoire : 10 Mio passeraient aujourd'hui et ne passeraient plus
     * le jour d'une video. La forme du code ne doit pas dependre du plafond.
     *
     * @return non-empty-string|null `null` si l'objet n'existe pas
     */
    public function downloadToTemporaryFile(StorageKey $key): ?string;

    public function put(StorageKey $key, string $localPath, MediaMimeType $mimeType): void;

    /** Ne leve pas si l'objet est deja absent : effacer est idempotent. */
    public function delete(StorageKey $key): void;
}
```

- [ ] **Step 4: Écrire l'adaptateur S3**

`backend/src/Media/Infrastructure/Storage/S3MediaStorage.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

/**
 * Deux clients S3, et c'est delibere (spec §5.1).
 *
 * Une URL pre-signee signe le `Host`. Le client INTERNE signe ses propres
 * requetes avec l'hote qu'il appelle vraiment (`minio:9000`) : aucun probleme.
 * Le client SIGNEUR, lui, doit signer avec l'hote que le NAVIGATEUR appellera
 * (`localhost:8080`, l'origine unique), sinon `SignatureDoesNotMatch`.
 *
 * Caddy proxifie `/messaging-media/*` vers MinIO en preservant le Host, et le
 * nom du bucket sert de prefixe de chemin — ce que `use_path_style_endpoint`
 * donne deja. Aucune reecriture d'URL, donc aucune signature cassee.
 */
final readonly class S3MediaStorage implements MediaStorageInterface
{
    private const string UPLOAD_TTL = '+5 minutes';
    private const string DOWNLOAD_TTL = '+15 minutes';

    public function __construct(
        private S3Client $internalClient,
        private S3Client $signerClient,
        private string $bucket,
        private LoggerInterface $logger,
    ) {
    }

    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): string
    {
        $command = $this->signerClient->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'ContentType' => $mimeType->value,
        ]);

        $url = (string) $this->signerClient
            ->createPresignedRequest($command, $now->modify(self::UPLOAD_TTL))
            ->getUri();

        return '' === $url ? throw new \RuntimeException('La signature a rendu une URL vide.') : $url;
    }

    public function presignDownload(StorageKey $key, \DateTimeImmutable $now): string
    {
        $command = $this->signerClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
        ]);

        $url = (string) $this->signerClient
            ->createPresignedRequest($command, $now->modify(self::DOWNLOAD_TTL))
            ->getUri();

        return '' === $url ? throw new \RuntimeException('La signature a rendu une URL vide.') : $url;
    }

    public function downloadToTemporaryFile(StorageKey $key): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'media-');

        if (false === $path) {
            throw new \RuntimeException('Impossible de creer un fichier temporaire.');
        }

        try {
            // `SaveAs` fait ecrire le SDK directement dans le fichier : les
            // octets ne transitent jamais par une variable PHP.
            $this->internalClient->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
                'SaveAs' => $path,
            ]);
        } catch (AwsException $exception) {
            @unlink($path);

            if ('NoSuchKey' === $exception->getAwsErrorCode()) {
                return null;
            }

            throw $exception;
        }

        return '' === $path ? null : $path;
    }

    public function put(StorageKey $key, string $localPath, MediaMimeType $mimeType): void
    {
        $this->internalClient->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'SourceFile' => $localPath,
            'ContentType' => $mimeType->value,
        ]);
    }

    public function delete(StorageKey $key): void
    {
        try {
            $this->internalClient->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
            ]);
        } catch (AwsException $exception) {
            // Effacer est idempotent : un objet deja absent n'est pas un echec.
            // On le signale sans interrompre l'appelant — jamais la cle en clair.
            $this->logger->warning('Suppression du media {aws_error_code} sans effet', [
                'aws_error_code' => $exception->getAwsErrorCode() ?? 'unknown',
            ]);
        }
    }
}
```

- [ ] **Step 5: Câbler les deux clients**

Dans `backend/config/services.yaml` :

```yaml
    # Deux clients S3, deux endpoints. Le signeur ne sert QU'A signer : il ne
    # doit jamais emettre de requete, `localhost:8080` n'etant pas joignable
    # depuis le conteneur backend. Le jour d'un vrai S3, seul
    # `use_path_style_endpoint` disparait.
    media.s3.internal:
        class: Aws\S3\S3Client
        arguments:
            - version: 'latest'
              region: 'us-east-1'
              endpoint: '%env(MEDIA_S3_INTERNAL_ENDPOINT)%'
              use_path_style_endpoint: true
              credentials:
                  key: '%env(MEDIA_S3_KEY)%'
                  secret: '%env(MEDIA_S3_SECRET)%'

    media.s3.signer:
        class: Aws\S3\S3Client
        arguments:
            - version: 'latest'
              region: 'us-east-1'
              endpoint: '%env(MEDIA_S3_PUBLIC_ENDPOINT)%'
              use_path_style_endpoint: true
              credentials:
                  key: '%env(MEDIA_S3_KEY)%'
                  secret: '%env(MEDIA_S3_SECRET)%'

    App\Media\Infrastructure\Storage\S3MediaStorage:
        arguments:
            $internalClient: '@media.s3.internal'
            $signerClient: '@media.s3.signer'
            $bucket: '%env(MEDIA_BUCKET)%'

    App\Media\Application\MediaStorageInterface: '@App\Media\Infrastructure\Storage\S3MediaStorage'
```

- [ ] **Step 6: Écrire les deux commandes et leurs handlers**

`RequestMediaUploadCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaMimeType;
use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/** L'identifiant est fourni par l'appelant, comme pour SendMessageCommand. */
final readonly class RequestMediaUploadCommand implements CommandInterface
{
    public function __construct(
        public MediaId $mediaId,
        public UserId $ownerId,
        public MediaMimeType $declaredMimeType,
        public int $declaredSize,
    ) {
    }
}
```

`RequestMediaUploadCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RequestMediaUploadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestMediaUploadCommand $command): void
    {
        $media = MediaObject::request(
            $command->mediaId,
            $command->ownerId,
            StorageKey::forOriginal($command->mediaId, $command->declaredMimeType),
            $command->declaredMimeType,
            $command->declaredSize,
            $this->clock->now(),
        );

        $this->media->add($media);

        // Ni nom de fichier, ni cle de stockage : des identifiants, et le type
        // DECLARE, qui est une donnee de diagnostic — l'ecart avec le type reel
        // se lira plus tard dans les logs du worker.
        $this->logger->info('Upload {media_id} pre-signe pour {owner_id}', [
            'media_id' => $command->mediaId->toString(),
            'owner_id' => $command->ownerId->toString(),
            'declared_mime_type' => $command->declaredMimeType->value,
            'declared_size' => $command->declaredSize,
        ]);
    }
}
```

`ConfirmMediaUploadCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

final readonly class ConfirmMediaUploadCommand implements CommandInterface
{
    public function __construct(
        public MediaId $mediaId,
        public UserId $confirmedBy,
    ) {
    }
}
```

`ConfirmMediaUploadCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaNotOwnedException;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class ConfirmMediaUploadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private CommandDispatcherInterface $commands,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConfirmMediaUploadCommand $command): void
    {
        // `ofId` leve MediaNotFoundException — traduite en 404, indistinguable
        // du media d'un autre qui n'existe pas.
        $media = $this->media->ofId($command->mediaId);

        if (!$media->ownerId()->equals($command->confirmedBy)) {
            $this->logger->warning('Confirmation du media {media_id} par un non-proprietaire', [
                'media_id' => $command->mediaId->toString(),
                'actor_id' => $command->confirmedBy->toString(),
            ]);

            throw MediaNotOwnedException::forMedia($command->mediaId);
        }

        // Le backend ne verifie PAS ici que l'objet existe dans le bucket : ce
        // serait un appel reseau synchrone pour une information que le worker
        // va de toute facon chercher. Un objet absent devient un Rejected avec
        // la raison `missing_object` (spec §3.3).
        $wasPending = MediaStatus::Pending === $media->status();
        $media->markUploaded($this->clock->now());
        $this->media->save($media);

        // Ne publier le traitement QUE si la transition a eu lieu : sans cette
        // garde, un rejeu ferait retraiter le meme media. L'agregat est
        // idempotent, le dispatch ne l'est pas.
        if (!$wasPending) {
            return;
        }

        $this->commands->dispatch(new ProcessMediaCommand($command->mediaId));

        $this->logger->info('Traitement du media {media_id} demande', [
            'media_id' => $command->mediaId->toString(),
        ]);
    }
}
```

> `ProcessMediaCommand` est écrite à la tâche 5. Pour que cette tâche-ci reste verte, **la créer maintenant** comme une commande vide de comportement (fichier `ProcessMediaCommand.php` seul, sans handler) : le transport `media` est déclaré, un message sans handler côté worker est simplement consommé et jeté. Le handler arrive à la tâche suivante.

- [ ] **Step 7: Écrire la query du ticket**

`UploadTicket.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

/** DTO de lecture. Modifier cette forme est un changement cassant pour le front. */
final readonly class UploadTicket
{
    public function __construct(
        public string $mediaId,
        public string $uploadUrl,
        public string $expiresAt,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'media_id' => $this->mediaId,
            'upload_url' => $this->uploadUrl,
            'expires_at' => $this->expiresAt,
        ];
    }
}
```

`GetUploadTicketQuery.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\MediaId;

/** @implements QueryInterface<UploadTicket> */
final readonly class GetUploadTicketQuery implements QueryInterface
{
    public function __construct(public MediaId $mediaId)
    {
    }
}
```

`UploadTicketReaderInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;

/**
 * Le handler de query declare son besoin par un port ; `Dbal…Reader` le
 * realise. Jamais de SQL dans Application, y compris cote lecture.
 */
interface UploadTicketReaderInterface
{
    /** @return array{key: StorageKey, mimeType: MediaMimeType}|null */
    public function keyAndTypeOf(MediaId $mediaId): ?array;
}
```

`GetUploadTicketQueryHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaNotFoundException;
use App\Shared\Application\Bus\QueryHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Signer n'est pas lire du SQL : le handler a donc le droit d'appeler le port
 * de stockage. Ce qu'il n'a pas le droit de faire, c'est ecrire une requete —
 * d'ou le `UploadTicketReaderInterface`.
 */
final readonly class GetUploadTicketQueryHandler implements QueryHandlerInterface
{
    private const string TTL = '+5 minutes';

    public function __construct(
        private UploadTicketReaderInterface $reader,
        private MediaStorageInterface $storage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetUploadTicketQuery $query): UploadTicket
    {
        $found = $this->reader->keyAndTypeOf($query->mediaId);

        if (null === $found) {
            throw MediaNotFoundException::withId($query->mediaId);
        }

        $now = $this->clock->now();

        return new UploadTicket(
            $query->mediaId->toString(),
            $this->storage->presignUpload($found['key'], $found['mimeType'], $now),
            $now->modify(self::TTL)->format(\DateTimeInterface::ATOM),
        );
    }
}
```

`backend/src/Media/Infrastructure/Persistence/DbalUploadTicketReader.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Persistence;

use App\Media\Application\Query\UploadTicketReaderInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use Doctrine\DBAL\Connection;

final readonly class DbalUploadTicketReader implements UploadTicketReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{key: StorageKey, mimeType: MediaMimeType}|null */
    public function keyAndTypeOf(MediaId $mediaId): ?array
    {
        /** @var array{storage_key: string, declared_mime_type: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT storage_key, declared_mime_type
                FROM media_objects
                WHERE id = :id
                SQL,
            ['id' => $mediaId->toString()],
        );

        if (false === $row) {
            return null;
        }

        return [
            'key' => StorageKey::fromString($row['storage_key']),
            'mimeType' => MediaMimeType::from($row['declared_mime_type']),
        ];
    }
}
```

- [ ] **Step 8: Écrire le payload et les deux contrôleurs**

`Payload/PresignUploadPayload.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http\Payload;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le `filename` sert UNIQUEMENT a l'ergonomie cote client : il n'entre ni dans
 * la cle de stockage — construite depuis l'ULID — ni dans une reponse. Un nom
 * de fichier controle par l'utilisateur ne doit jamais devenir un chemin.
 */
final readonly class PresignUploadPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom du fichier est requis.')]
        #[Assert\Length(max: 255, maxMessage: 'Ce nom de fichier est trop long.')]
        public string $filename = '',

        // La contrainte REFERENCE l'enum : elle ne redeclare jamais la liste.
        #[Assert\Choice(
            callback: [MediaMimeType::class, 'values'],
            message: 'Ce type de fichier n\'est pas accepte.',
        )]
        public string $contentType = '',

        #[Assert\Positive(message: 'La taille doit etre un entier positif.')]
        #[Assert\LessThanOrEqual(
            value: MediaObject::MAX_BYTES,
            message: 'Un fichier ne peut pas depasser {{ compared_value }} octets.',
        )]
        public int $size = 0,
    ) {
    }
}
```

`RequestMediaUploadController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http;

use App\Media\Application\Command\RequestMediaUploadCommand;
use App\Media\Application\Query\GetUploadTicketQuery;
use App\Media\Domain\MediaMimeType;
use App\Media\Infrastructure\Http\Payload\PresignUploadPayload;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class RequestMediaUploadController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    #[Route('/api/media', name: 'media_presign', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] PresignUploadPayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $mediaId = MediaId::fromString($this->idGenerator->generate());

        // La contrainte Choice a deja garanti l'appartenance a l'allowlist :
        // `from` ne peut pas echouer ici, et `tryFrom` masquerait un bug.
        $this->commands->dispatch(new RequestMediaUploadCommand(
            $mediaId,
            $securityUser->userId(),
            MediaMimeType::from($payload->contentType),
            $payload->size,
        ));

        // CQS : la commande ne rend rien. L'URL signee s'obtient par une query,
        // y compris pour un identifiant qu'on vient de creer.
        $ticket = $this->queries->ask(new GetUploadTicketQuery($mediaId));

        return new JsonResponse($ticket->toArray(), Response::HTTP_CREATED);
    }
}
```

`ConfirmMediaUploadController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http;

use App\Media\Application\Command\ConfirmMediaUploadCommand;
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ConfirmMediaUploadController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route(
        '/api/media/{mediaId}/uploaded',
        name: 'media_confirm_upload',
        requirements: ['mediaId' => AbstractUlidIdentifier::ROUTE_PATTERN],
        methods: ['POST'],
    )]
    public function __invoke(MediaId $mediaId, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        // Idempotente par l'agregat : aucune condition ici, aucun statut a lire.
        $this->commands->dispatch(new ConfirmMediaUploadCommand($mediaId, $securityUser->userId()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 9: Câbler le reader**

```yaml
    App\Media\Application\Query\UploadTicketReaderInterface: '@App\Media\Infrastructure\Persistence\DbalUploadTicketReader'
```

- [ ] **Step 10: Relancer le test fonctionnel**

```bash
make functional-test ARGS="--filter=UploadFlowTest"
```

Expected : PASS, 6 tests.

- [ ] **Step 11: Vérifier le flux réel, hors tests**

C'est la vérification qui compte : un vrai `PUT` de bout en bout.

```bash
make up
# Récupérer un cookie de session (adapter au flux de login des fixtures)
curl -s -c /tmp/claude-cookies.txt -X POST http://localhost:8080/api/login \
     -H 'Content-Type: application/json' -d '{"username":"alice","password":"alice"}'

# 1. Pré-signature
curl -s -b /tmp/claude-cookies.txt -X POST http://localhost:8080/api/media \
     -H 'Content-Type: application/json' \
     -d '{"filename":"photo.jpg","content_type":"image/jpeg","size":2048}'
```

Copier `upload_url`, puis :

```bash
# 2. Le PUT des octets, EN DIRECT — sans cookie, sans passer par /api
curl -s -o /dev/null -w '%{http_code}\n' -X PUT "<upload_url>" \
     -H 'Content-Type: image/jpeg' --data-binary @/chemin/vers/une/photo.jpg
```

Expected : `200`. Puis ouvrir `http://localhost:9001` (console MinIO, `minioadmin`/`minioadmin`) et **voir l'objet dans le bucket `messaging-media`**. C'est la preuve visuelle que les octets n'ont pas traversé PHP.

Si `403 SignatureDoesNotMatch` : le `Content-Type` envoyé ne correspond pas à celui signé, ou Caddy ne préserve pas le `Host`.

- [ ] **Step 12: Vérifier que l'expiration marche vraiment**

Le comportement qu'on cherche à observer, donc à provoquer. Passer temporairement `S3MediaStorage::UPLOAD_TTL` à `'+30 seconds'`, rejouer les étapes 1 et 2 après avoir attendu, constater un `403` avec `AccessDenied` et la mention d'expiration dans le XML, **puis remettre `'+5 minutes'`**.

- [ ] **Step 13: Les quatre portes, puis commit**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
git add backend/src/Media/ backend/config/services.yaml backend/tests/Functional/Media/
git commit -m "feat(medias): pre-signer les uploads et confirmer les transferts

Les octets vont du navigateur au stockage sans traverser PHP. La signature
couvre la methode, la cle et le Content-Type ; elle ne peut pas plafonner la
taille — c'est le worker qui tranchera apres transfert.

La confirmation est idempotente par l'agregat, mais le dispatch du
traitement est garde : rejouer ne retraite pas.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5 : Le worker — inspection des octets et miniature

La tâche de sécurité de la tranche : **le type déclaré par le client n'est jamais cru.**

**Files:**
- Create: `backend/src/Media/Application/ImageInspectorInterface.php`, `Command/ProcessMediaCommandHandler.php`
- Create: `backend/src/Media/Infrastructure/Image/GdImageInspector.php`, `InspectedImage.php`
- Modify: `backend/src/Media/Application/Command/ProcessMediaCommand.php` (créée vide en tâche 4)
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Unit/Media/Infrastructure/GdImageInspectorTest.php`, `backend/tests/Functional/Media/MediaProcessingTest.php`
- Create: `backend/tests/Fixtures/media/` — quatre fichiers d'exemple

**Interfaces:**
- Consumes: tâches 2, 3, 4.
- Produces : `ImageInspectorInterface::inspect(string $localPath): ?InspectedImage` et `::thumbnail(string $localPath, string $targetPath): void`. `InspectedImage` : `readonly` avec `MediaMimeType $mimeType`, `int $width`, `int $height`, `int $byteSize`.

- [ ] **Step 1: Fabriquer les fichiers d'exemple**

```bash
docker compose run --rm --no-deps backend php -r '
  $im = imagecreatetruecolor(1600, 900);
  imagejpeg($im, "tests/Fixtures/media/valide.jpg");
  imagepng($im, "tests/Fixtures/media/valide.png");
  file_put_contents("tests/Fixtures/media/piege.jpg", "<?php echo \"pwn\"; ?>");
  file_put_contents("tests/Fixtures/media/tronque.gif", substr(file_get_contents("tests/Fixtures/media/valide.png"), 0, 20));
'
```

Créer le dossier au préalable, et **versionner les quatre fichiers** : un test qui fabrique ses fixtures à l'exécution teste `gd` autant que le code.

- [ ] **Step 2: Écrire le test de l'inspecteur**

`backend/tests/Unit/Media/Infrastructure/GdImageInspectorTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Domain\MediaMimeType;
use App\Media\Infrastructure\Image\GdImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdImageInspector::class)]
final class GdImageInspectorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/media/';

    public function testAValidJpegIsMeasured(): void
    {
        $inspected = (new GdImageInspector())->inspect(self::FIXTURES . 'valide.jpg');

        self::assertNotNull($inspected);
        self::assertSame(MediaMimeType::Jpeg, $inspected->mimeType);
        self::assertSame(1600, $inspected->width);
        self::assertSame(900, $inspected->height);
        self::assertGreaterThan(0, $inspected->byteSize);
    }

    public function testAPhpFileRenamedJpgIsRefused(): void
    {
        // LE test de la tranche. Le nom et le Content-Type declare disent
        // « image/jpeg » ; les octets disent « text/x-php ». Seuls les octets
        // font foi.
        self::assertNull((new GdImageInspector())->inspect(self::FIXTURES . 'piege.jpg'));
    }

    public function testATruncatedFileIsRefused(): void
    {
        self::assertNull((new GdImageInspector())->inspect(self::FIXTURES . 'tronque.gif'));
    }

    public function testTheThumbnailFitsInsideTheMaximumSide(): void
    {
        $target = sys_get_temp_dir() . '/thumb-test.jpg';

        (new GdImageInspector())->thumbnail(self::FIXTURES . 'valide.jpg', $target);

        $size = getimagesize($target);
        self::assertIsArray($size);
        // 1600x900 tient dans un carre de 400 : le cote long devient 400, et le
        // ratio est preserve — sans quoi l'apercu deforme l'image.
        self::assertSame(400, $size[0]);
        self::assertSame(225, $size[1]);

        unlink($target);
    }
}
```

- [ ] **Step 3: Lancer, vérifier l'échec**

```bash
make unit-test ARGS="--filter=GdImageInspectorTest"
```

Expected : FAIL — classe introuvable.

- [ ] **Step 4: Écrire le port et son DTO**

`backend/src/Media/Application/ImageInspectorInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Infrastructure\Image\InspectedImage;

interface ImageInspectorInterface
{
    /**
     * Lit le type REEL des octets et mesure l'image. Rend `null` si les octets
     * ne sont pas une image de l'allowlist, ou si le decodage echoue.
     *
     * Le type declare par le client n'entre jamais ici : c'est le point unique
     * ou l'on decide ce qu'est vraiment le fichier.
     */
    public function inspect(string $localPath): ?InspectedImage;

    /** Ecrit une miniature JPEG dans `$targetPath`, ratio preserve. */
    public function thumbnail(string $localPath, string $targetPath): void;
}
```

> `InspectedImage` vit dans `Infrastructure/Image/` et non dans `Application/` : c'est un DTO de retour d'adaptateur. **Si `deptrac` refuse ce sens de dépendance** (`Application` → `Infrastructure`), le déplacer dans `Media/Application/InspectedImage.php` et corriger le `use` ci-dessus. Vérifier avec `make deptrac` avant d'aller plus loin — c'est le seul point du plan où le placement dépend d'un contrôle.

`backend/src/Media/Infrastructure/Image/InspectedImage.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Image;

use App\Media\Domain\MediaMimeType;

final readonly class InspectedImage
{
    public function __construct(
        public MediaMimeType $mimeType,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {
    }
}
```

- [ ] **Step 5: Écrire l'inspecteur**

`backend/src/Media/Infrastructure/Image/GdImageInspector.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Image;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Domain\MediaMimeType;

final readonly class GdImageInspector implements ImageInspectorInterface
{
    /** Cote long de la miniature. 400 px suffit a un apercu dans un fil. */
    private const int THUMBNAIL_MAX_SIDE = 400;

    private const int THUMBNAIL_QUALITY = 82;

    public function inspect(string $localPath): ?InspectedImage
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return null;
        }

        $mimeType = MediaMimeType::tryFrom($detected);

        if (null === $mimeType) {
            return null;
        }

        // Le type est bon, mais un fichier tronque le porte encore : seul le
        // decodage tranche vraiment. `@` parce que getimagesize() emet un
        // warning PHP sur un fichier corrompu, et `failOnWarning` est actif.
        $size = @getimagesize($localPath);

        if (false === $size) {
            return null;
        }

        $bytes = filesize($localPath);

        if (false === $bytes) {
            return null;
        }

        return new InspectedImage($mimeType, $size[0], $size[1], $bytes);
    }

    public function thumbnail(string $localPath, string $targetPath): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($localPath));

        if (false === $source) {
            throw new \RuntimeException('La miniature ne peut pas etre produite : image indecodable.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(self::THUMBNAIL_MAX_SIDE / $width, self::THUMBNAIL_MAX_SIDE / $height, 1.0);

        $thumbnail = imagescale($source, (int) round($width * $ratio), (int) round($height * $ratio));

        if (false === $thumbnail) {
            throw new \RuntimeException('La mise a l\'echelle de la miniature a echoue.');
        }

        imagejpeg($thumbnail, $targetPath, self::THUMBNAIL_QUALITY);
    }
}
```

- [ ] **Step 6: Relancer, vérifier PASS**

```bash
make unit-test ARGS="--filter=GdImageInspectorTest"
```

Expected : PASS, 4 tests.

- [ ] **Step 7: Écrire le handler du worker**

`backend/src/Media/Application/Command/ProcessMediaCommand.php` (compléter la classe vide de la tâche 4) :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\MediaId;

/** Routee vers le transport `media` : consommee par le conteneur `worker`. */
final readonly class ProcessMediaCommand implements CommandInterface
{
    public function __construct(public MediaId $mediaId)
    {
    }
}
```

`ProcessMediaCommandHandler.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class ProcessMediaCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private MediaStorageInterface $storage,
        private ImageInspectorInterface $inspector,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessMediaCommand $command): void
    {
        $media = $this->media->ofId($command->mediaId);
        $now = $this->clock->now();

        $localPath = $this->storage->downloadToTemporaryFile($media->storageKey());

        if (null === $localPath) {
            $this->reject($media, MediaRejectionReason::MissingObject, $now);

            return;
        }

        try {
            $inspected = $this->inspector->inspect($localPath);

            if (null === $inspected) {
                // Un `.jpg` qui contient du PHP meurt ICI, pas a l'affichage.
                $this->reject($media, MediaRejectionReason::UnsupportedType, $now);

                return;
            }

            if ($inspected->byteSize > MediaObject::MAX_BYTES) {
                // Le plafond ne peut pas etre applique au transfert par une URL
                // pre-signee PUT (spec §3.2) : il l'est ici.
                $this->reject($media, MediaRejectionReason::TooLarge, $now);

                return;
            }

            $thumbnailPath = sprintf('%s-thumb', $localPath);
            $thumbnailKey = StorageKey::forThumbnail($media->id());
            $this->inspector->thumbnail($localPath, $thumbnailPath);
            $this->storage->put($thumbnailKey, $thumbnailPath, \App\Media\Domain\MediaMimeType::Jpeg);
            @unlink($thumbnailPath);

            $media->markReady(
                $inspected->mimeType,
                $inspected->width,
                $inspected->height,
                $inspected->byteSize,
                $thumbnailKey,
                $now,
            );
            $this->media->save($media);

            if ($inspected->mimeType !== $media->declaredMimeType()) {
                // Signal actionnable : un client qui declare autre chose que ce
                // qu'il envoie est un bug, ou pire.
                $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
                    'media_id' => $media->id()->toString(),
                    'declared_mime_type' => $media->declaredMimeType()->value,
                    'actual_mime_type' => $inspected->mimeType->value,
                ]);
            }

            $this->logger->info('Media {media_id} pret', [
                'media_id' => $media->id()->toString(),
                'width' => $inspected->width,
                'height' => $inspected->height,
                'byte_size' => $inspected->byteSize,
            ]);
        } finally {
            // Le fichier temporaire part quoi qu'il arrive : un rejeu apres
            // echec ne doit pas remplir le disque du worker.
            @unlink($localPath);
        }
    }

    private function reject(MediaObject $media, MediaRejectionReason $reason, \DateTimeImmutable $now): void
    {
        $media->markRejected($reason, $now);
        $this->media->save($media);

        // On ne conserve pas les octets d'un fichier qu'on a decide de ne
        // jamais servir (spec §7.1).
        $this->storage->delete($media->storageKey());

        $this->logger->warning('Media {media_id} refuse : {rejection_reason}', [
            'media_id' => $media->id()->toString(),
            'rejection_reason' => $reason->value,
            'declared_mime_type' => $media->declaredMimeType()->value,
        ]);
    }
}
```

Corriger l'import : ajouter `use App\Media\Domain\MediaMimeType;` en tête et remplacer `\App\Media\Domain\MediaMimeType::Jpeg` par `MediaMimeType::Jpeg`.

- [ ] **Step 8: Câbler l'inspecteur**

```yaml
    App\Media\Application\ImageInspectorInterface: '@App\Media\Infrastructure\Image\GdImageInspector'
```

- [ ] **Step 9: Écrire le test fonctionnel du traitement**

`backend/tests/Functional/Media/MediaProcessingTest.php` — il exerce le vrai MinIO et le vrai handler, en le déclenchant à la main (le transport est `in-memory` en test) :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaProcessingTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/media/';

    public function testAValidImageBecomesReadyWithAThumbnail(): void
    {
        $mediaId = $this->uploaded('valide.jpg', MediaMimeType::Jpeg);

        $this->dispatcher()->dispatch(new ProcessMediaCommand($mediaId));

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(MediaMimeType::Jpeg, $media->mimeType());
        self::assertSame(1600, $media->width());
        self::assertNotNull($media->thumbnailKey());
        // La miniature existe REELLEMENT dans le bucket, elle n'est pas
        // seulement enregistree en base.
        self::assertNotNull($this->storage()->downloadToTemporaryFile($media->thumbnailKey()));
    }

    public function testAPhpFileRenamedJpgIsRejectedAndItsBytesAreDestroyed(): void
    {
        $mediaId = $this->uploaded('piege.jpg', MediaMimeType::Jpeg);

        $this->dispatcher()->dispatch(new ProcessMediaCommand($mediaId));

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());
        // On ne conserve pas les octets d'un fichier qu'on ne servira jamais.
        self::assertNull($this->storage()->downloadToTemporaryFile($media->storageKey()));
    }

    public function testAMissingObjectIsRejectedRatherThanRetriedForever(): void
    {
        $mediaId = MediaId::fromString('01JQZ00000000000000000MISS');
        $this->repository()->add(MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        ));

        $this->dispatcher()->dispatch(new ProcessMediaCommand($mediaId));

        self::assertSame(MediaRejectionReason::MissingObject, $this->repository()->ofId($mediaId)->rejectionReason());
    }

    /** Depose reellement le fichier dans MinIO, puis rend l'identifiant. */
    private function uploaded(string $fixture, MediaMimeType $declared): MediaId
    {
        self::bootKernel();
        $mediaId = MediaId::fromString(sprintf('01JQZ0000000000000000%05d', crc32($fixture) % 100_000));
        $key = StorageKey::forOriginal($mediaId, $declared);

        $media = MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            $key,
            $declared,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));
        $this->repository()->add($media);
        $this->repository()->save($media);

        $this->storage()->put($key, self::FIXTURES . $fixture, $declared);

        return $mediaId;
    }

    private function repository(): MediaRepositoryInterface
    {
        self::bootKernel();

        /** @var MediaRepositoryInterface */
        return self::getContainer()->get(MediaRepositoryInterface::class);
    }

    private function storage(): MediaStorageInterface
    {
        self::bootKernel();

        /** @var MediaStorageInterface */
        return self::getContainer()->get(MediaStorageInterface::class);
    }

    private function dispatcher(): CommandDispatcher
    {
        self::bootKernel();

        /** @var CommandDispatcher */
        return self::getContainer()->get(CommandDispatcher::class);
    }

    private function anyUserId(): UserId
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var string $id */
        $id = $connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return UserId::fromString($id);
    }
}
```

L'identifiant dérivé de `crc32` garantit un ULID valide **et** distinct par fixture. Si le format rendu n'est pas un ULID valide (caractères hors base32 Crockford), fixer trois constantes littérales à la place — un ULID par fixture.

- [ ] **Step 10: Lancer et vérifier**

```bash
make functional-test ARGS="--filter=MediaProcessingTest"
```

Expected : PASS, 3 tests.

- [ ] **Step 11: Vérifier le worker en vrai**

```bash
make up
docker compose logs -f worker
```

Rejouer le flux `curl` de la tâche 4 (pré-signature, `PUT`, confirmation). Expected dans les logs : `Traitement du media … demande` puis `Media … pret`. La miniature apparaît dans la console MinIO à côté de l'original.

- [ ] **Step 12: Les quatre portes, puis commit**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
git add backend/src/Media/ backend/config/services.yaml backend/tests/
git commit -m "feat(medias): inspecter les octets et produire la miniature

Le type declare par le client n'est jamais cru : finfo sur les octets reels,
puis decodage. Un fichier PHP renomme .jpg est refuse ici, pas a
l'affichage. Le rejet efface les octets du bucket.

L'objet est rapatrie dans un fichier temporaire, jamais en memoire : la
forme du code ne doit pas dependre du plafond courant.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6 : Attacher des médias à un message

**Files:**
- Create: `backend/src/Media/Application/Contract/MediaOwnershipInterface.php`, `backend/src/Media/Infrastructure/Contract/DbalMediaOwnership.php`
- Create: `backend/src/Message/Domain/Port/MediaOwnershipPortInterface.php`, `backend/src/Message/Infrastructure/Contract/MediaOwnershipAdapter.php`
- Create: `backend/src/Message/Domain/EmptyMessageException.php`, `MediaAlreadyAttachedException.php`
- Modify: `backend/src/Message/Domain/Message.php`, `backend/src/Message/Application/Command/SendMessageCommand.php` + `…Handler.php`
- Modify: `backend/src/Message/Infrastructure/Persistence/DbalMessageRepository.php`, `backend/src/Message/Infrastructure/Http/SendMessageController.php`, `Payload/SendMessagePayload.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Unit/Message/Domain/MessageTest.php` (existant), `backend/tests/Functional/Message/SendMessageWithMediaTest.php`

**Interfaces:**
- Produces :
  - `MediaOwnershipInterface::assertUsableBy(list<MediaId> $mediaIds, UserId $ownerId): void` — lève `MediaNotOwnedException` (403) ou `MediaNotFoundException` (404)
  - `Message::send(MessageId, ConversationId, UserId, ?MessageContent, list<MediaId>, ClientMessageId, \DateTimeImmutable)` — **signature modifiée**
  - `Message::mediaIds(): list<MediaId>`

- [ ] **Step 1: Écrire les tests du domaine**

Ajouter à `backend/tests/Unit/Message/Domain/MessageTest.php` :

```php
    public function testAMessageMayCarryImagesInsteadOfText(): void
    {
        $message = Message::send(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::SENDER_ID),
            null,
            [MediaId::fromString(self::MEDIA_ID)],
            ClientMessageId::fromString(self::CLIENT_KEY),
            new \DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );

        self::assertNull($message->content());
        self::assertCount(1, $message->mediaIds());
    }

    public function testAMessageWithNeitherTextNorMediaIsRefused(): void
    {
        // L'invariant croise deux tables : il ne peut pas etre un CHECK
        // (spec §2.3). Il vit donc ICI, et nulle part ailleurs.
        $this->expectException(EmptyMessageException::class);

        Message::send(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::SENDER_ID),
            null,
            [],
            ClientMessageId::fromString(self::CLIENT_KEY),
            new \DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );
    }

    public function testDeletingForEveryoneDetachesTheMedia(): void
    {
        $message = Message::send(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::SENDER_ID),
            null,
            [MediaId::fromString(self::MEDIA_ID)],
            ClientMessageId::fromString(self::CLIENT_KEY),
            new \DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );

        $message->deleteForEveryone(
            UserId::fromString(self::SENDER_ID),
            new \DateTimeImmutable('2026-07-26T10:05:00+00:00'),
        );

        // Sans ce detachement, supprimer un message laisserait ses images
        // integralement accessibles : le pire des deux mondes, une suppression
        // qui a l'air d'avoir eu lieu (spec §7.2).
        self::assertSame([], $message->mediaIds());
    }
```

Ajouter `self::MEDIA_ID = '01JQZ0000000000000000000AB'` et les `use` correspondants.

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
make unit-test ARGS="--filter=MessageTest"
```

Expected : FAIL — `Message::send()` n'accepte pas ces arguments.

- [ ] **Step 3: Modifier l'agrégat**

Dans `Message.php` : `content` devient `?MessageContent` déjà (T3), ajouter le champ `private array $mediaIds` (annoté `list<MediaId>`), modifier `send()` et `reconstitute()` pour le recevoir, ajouter `mediaIds()`, et dans `send()` :

```php
        if (null === $content && [] === $mediaIds) {
            throw EmptyMessageException::create();
        }
```

Dans `deleteForEveryone()`, après `$this->content = null;` :

```php
        // Les images partent avec le texte. Un media detache devient orphelin
        // et sera ramasse par la purge : les octets sont detruits, comme le
        // texte l'etait (spec §7.2).
        $this->mediaIds = [];
```

`MessageWasSent` n'est **pas** modifié : la charge utile temps réel du média arrive par la tâche 8. Le noter dans le code par un commentaire, sinon quelqu'un l'y ajoutera « par symétrie » — et casserait un contrat publié.

`EmptyMessageException` implémente `InvalidInputExceptionInterface` (→ 422), message : `'Un message doit porter du texte ou au moins une image.'`.

- [ ] **Step 4: Écrire le contrat de `Media` et le port de `Message`**

`Media/Application/Contract/MediaOwnershipInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application\Contract;

use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Media : « ces medias sont-ils a cette personne, et
 * libres ? ». Ne rend rien — elle leve, ou elle se tait.
 *
 * Elle n'expose ni l'agregat, ni le proprietaire, ni le statut : un
 * consommateur ne doit rien pouvoir deduire de plus que ce qu'il a demande.
 * Modifier cette signature est un changement cassant.
 */
interface MediaOwnershipInterface
{
    /**
     * @param list<MediaId> $mediaIds
     *
     * @throws \App\Media\Domain\MediaNotFoundException       un media inconnu — 404, indistinguable du media d'un autre
     * @throws \App\Media\Domain\MediaNotOwnedException        le media appartient a quelqu'un d'autre — 403
     * @throws \App\Media\Domain\MediaAlreadyAttachedException le media est deja porte par un message — 409
     */
    public function assertUsableBy(array $mediaIds, UserId $ownerId): void;
}
```

`Message/Domain/Port/MediaOwnershipPortInterface.php` : même signature, dans le langage du consommateur. `Message/Infrastructure/Contract/MediaOwnershipAdapter.php` délègue au contrat — c'est le motif déjà employé par `UnreadCounterAdapter`, le reprendre à l'identique.

`Media/Infrastructure/Contract/DbalMediaOwnership.php` : une requête, `ArrayParameterType::STRING` pour le `IN`, et un `UNIQUE (media_id)` en base qui garantit déjà l'unicité d'attachement :

```php
        /** @var list<array{id: string, owner_id: string, attached: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT m.id,
                       m.owner_id,
                       EXISTS (SELECT 1 FROM message_media mm WHERE mm.media_id = m.id) AS attached
                FROM media_objects m
                WHERE m.id IN (:ids)
                SQL,
            ['ids' => array_map(static fn (MediaId $id): string => $id->toString(), $mediaIds)],
            ['ids' => ArrayParameterType::STRING],
        );
```

Puis : un id absent des lignes → `MediaNotFoundException` ; `owner_id` différent → `MediaNotOwnedException` ; `attached` vrai → `MediaAlreadyAttachedException` (409, `ConflictExceptionInterface`, slug `media-already-attached`, titre `Media deja attache`).

> `MediaAlreadyAttachedException` vit dans `Media/Domain/` : c'est `Media` qui possède la règle « un média ne s'attache qu'une fois ».

- [ ] **Step 5: Écrire le test fonctionnel**

`backend/tests/Functional/Message/SendMessageWithMediaTest.php`, quatre cas :

| Cas | Attendu |
|---|---|
| message sans texte avec un média | **201**, et `SELECT content` rend `NULL` |
| message sans texte ni média | **422** `/problems/validation-failed` |
| attacher le média de Bob depuis Alice | **403** `/problems/media-not-owned` |
| attacher deux fois le même média | **409** `/problems/media-already-attached` |
| `media_ids` contenant une chaîne qui n'est pas un ULID | **422**, `violations[0].field === 'media_ids[0]'` |

Le dernier cas vérifie que le convertisseur snake_case s'applique aussi aux chemins indexés — c'est exactement l'exemple donné par `CLAUDE.md`.

- [ ] **Step 6: Modifier commande, handler, repository, contrôleur, payload**

- `SendMessageCommand` : `?MessageContent $content` et `list<MediaId> $mediaIds`.
- `SendMessageCommandHandler` : appeler `$this->mediaOwnership->assertUsableBy($command->mediaIds, $command->senderId)` **avant** `Message::send()`, dans la transaction — même raison que le contrôle d'appartenance déjà présent : une vérification hors transaction serait devançable. Adapter le log `content_length` en `null === $content ? 0 : mb_strlen(...)`, et ajouter `'media_count' => count($command->mediaIds)`.
- `DbalMessageRepository::insertIfAbsent()` : retirer le `throw new \LogicException` sur contenu nul (un message image-seule est légitime), insérer les liaisons **dans la même transaction** après l'INSERT du message :

```php
        foreach ($message->mediaIds() as $position => $mediaId) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO message_media (message_id, media_id, position)
                    VALUES (:message_id, :media_id, :position)
                    SQL,
                [
                    'message_id' => $message->id()->toString(),
                    'media_id' => $mediaId->toString(),
                    'position' => $position,
                ],
            );
        }
```

- `DbalMessageRepository::save()` : si `[] === $message->mediaIds()`, un `DELETE FROM message_media WHERE message_id = :id` — c'est la traduction du détachement décidé par l'agrégat.
- `SendMessagePayload` : `content` perd `NotBlank`, devient `?string $content = null` ; ajouter :

```php
        /** @var list<string> */
        #[Assert\Count(max: 10, maxMessage: 'Un message ne peut pas porter plus de {{ limit }} images.')]
        #[Assert\All([
            new Assert\Regex(
                pattern: AbstractUlidIdentifier::PATTERN,
                message: 'Cet identifiant n\'est pas un ULID valide.',
            ),
        ])]
        public array $mediaIds = [],
```

- `SendMessageController` : construire `?MessageContent` et la liste de `MediaId`, puis — la règle croisée, qui ne peut pas être une contrainte de champ :

```php
        // Regle qui depend de DEUX champs : elle ne peut pas s'exprimer en
        // contrainte. Meme traitement que « un groupe requiert un titre ».
        if (null === $content && [] === $payload->mediaIds) {
            throw EmptyMessageException::create();
        }
```

- [ ] **Step 7: Câbler les deux alias**

```yaml
    App\Media\Application\Contract\MediaOwnershipInterface: '@App\Media\Infrastructure\Contract\DbalMediaOwnership'
    App\Message\Domain\Port\MediaOwnershipPortInterface: '@App\Message\Infrastructure\Contract\MediaOwnershipAdapter'
```

- [ ] **Step 8: Lancer tous les tests de `Message`**

```bash
make functional-test ARGS="--filter='SendMessage|DeleteMessage|EditMessage'"
make unit-test ARGS="--filter=MessageTest"
```

Expected : PASS. Les tests de T1 et T3 doivent passer **sans modification de leurs assertions** — seuls leurs appels à `Message::send()` gagnent les deux nouveaux arguments.

- [ ] **Step 9: Les quatre portes, puis commit**

`make deptrac` est le contrôle qui compte : `Message` ne doit citer que `Media\Application\Contract\` — jamais `Media\Domain\` ni `Media\Infrastructure\`. Une violation ici signifie que l'adaptateur est mal placé.

```bash
git commit -m "feat(message): attacher des images a un message

Le texte devient optionnel : un message porte du texte OU des images.
L'invariant croise deux tables, il ne peut pas etre un CHECK — il vit dans
Message::send() et dans un test fonctionnel.

Supprimer pour tous detache les medias. Sans cela, une suppression
laisserait les images accessibles : une suppression qui a l'air d'avoir eu
lieu.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7 : Les `MediaView` signés dans les lectures de messages

**Files:**
- Create: `backend/src/Media/Application/Contract/MediaView.php`, `MediaFinderInterface.php`, `backend/src/Media/Infrastructure/Contract/DbalMediaFinder.php`
- ~~Create: `backend/src/Message/Domain/Port/MediaFinderPortInterface.php`, `backend/src/Message/Infrastructure/Contract/MediaFinderAdapter.php`~~ — **abandonné à l'implémentation.** Les deux seuls consommateurs, `DbalMessagePageReader` et `DbalMessageReader`, sont en `Infrastructure` : ils nomment `MediaFinderInterface` directement. Le port et l'adaptateur n'auraient fait que déléguer un appel identique, et le port aurait mis un `MediaView` — donc le contrat d'un contexte voisin — dans `Message/Domain/`. Le port d'écriture `MediaOwnershipPortInterface` reste justifié, lui : son consommateur est `SendMessageCommandHandler`, en `Application`.
- Modify: `backend/src/Message/Application/Query/MessageView.php`, `backend/src/Message/Infrastructure/Persistence/DbalMessagePageReader.php`, `DbalMessageReader.php`
- Test: `backend/tests/Functional/Message/MessageMediaReadTest.php`
- Create (non prévu) : `backend/tests/Support/QueryRecorder/` — compteur de requêtes DBAL. Le profileur de doctrine-bundle ne convient pas, `doctrine.debug_data_holder` portant `kernel.reset` : le `ServicesResetter` le vide après chaque requête HTTP, donc une assertion posée après la réponse ne trouverait rien. Même raison que `MediaCommandSpy`.

**Interfaces:**
- Produces : `MediaFinderInterface::viewsFor(list<MediaId>): array<string, MediaView>` — indexé par ULID. `MediaView` : `readonly` avec `string $id`, `string $status`, `?string $mimeType`, `?int $width`, `?int $height`, `?string $url`, `?string $thumbnailUrl`, et `toArray(): array<string, scalar|null>`.

- [ ] **Step 1: Écrire le test**

`backend/tests/Functional/Message/MessageMediaReadTest.php` :

| Cas | Attendu |
|---|---|
| message avec média `ready` | `media[0].url` et `thumbnail_url` portent `X-Amz-Signature=`, `width`/`height` remplis |
| message avec média `processing` | `media[0].status === 'processing'`, `url` et `thumbnail_url` **`null`** |
| message avec média `rejected` | `status === 'rejected'`, aucune URL |
| pagination sur 30 messages dont 5 avec images | **une seule** requête de médias, pas 30 — assertion par compteur de requêtes DBAL |

Le dernier cas est le seul qui protège contre un N+1 : sans lui, la solution évidente (une requête par message) passerait tous les autres tests.

- [ ] **Step 2: Écrire le contrat**

`MediaView.php` — **forme figée, changement cassant** :

```php
final readonly class MediaView
{
    public function __construct(
        public string $id,
        public string $status,
        /** Renseignes UNIQUEMENT quand `status` vaut `ready` : on ne signe pas
         *  l'acces a des octets qu'on n'a pas encore valides (spec §4.3). */
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public ?string $url,
        public ?string $thumbnailUrl,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
```

- [ ] **Step 3: Écrire `DbalMediaFinder`**

Une requête `IN (:ids)` avec `ArrayParameterType::STRING`, puis signature des URLs via `MediaStorageInterface` **uniquement** pour les lignes `ready`. Signer une ligne `processing` ferait une URL vers un objet dont on ne sait pas encore s'il est servable.

- [ ] **Step 4: Hydrater les lectures de messages**

Dans `DbalMessagePageReader::page()` : après avoir récupéré les lignes de messages, **une seule** requête sur `message_media` pour tous les ids de la page, puis **un seul** appel à `MediaFinderInterface::viewsFor()`.

```php
        // UNE requete pour toute la page, jamais une par message : le N+1 est
        // le piege evident ici, et le test de pagination le verrouille.
        /** @var list<array{message_id: string, media_id: string}> $links */
        $links = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT message_id, media_id
                FROM message_media
                WHERE message_id IN (:message_ids)
                ORDER BY message_id, position
                SQL,
            ['message_ids' => $messageIds],
            ['message_ids' => ArrayParameterType::STRING],
        );
```

Même traitement dans `DbalMessageReader` (un seul message : la requête reste identique, avec une liste d'un élément).

`MessageView` gagne `public array $media` annoté `list<MediaView>`, et `toArray()` gagne :

```php
            'media' => array_map(static fn (MediaView $view): array => $view->toArray(), $this->media),
```

Ce qui **change le type de retour** de `toArray()` : il passe de `array<string, string|null>` à `array<string, mixed>`. Corriger l'annotation, PHPStan `max` la vérifie.

- [ ] **Step 5: Vérifier, quatre portes, commit**

```bash
make functional-test ARGS="--filter='MessageMediaReadTest|MessagePagination'"
make static-code-analysis && make check-cs && make deptrac && make test
git commit -m "feat(message): rendre les medias signes dans les lectures

Une URL GET pre-signee a TTL 15 min par MediaView, emise seulement dans une
reponse dont l'appartenance a deja ete verifiee : il n'y a donc pas de
surface « donne-moi une URL » a proteger separement.

Une seule requete de medias par page, jamais une par message.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8 : La chorégraphie — de `MediaWasProcessed` à `message.media_ready`

Trois sauts, aucun contexte ne pilote l'autre (spec §6.1).

**Files:**
- Create: `backend/src/Shared/Domain/Event/MessageMediaBecameReady.php`
- Create: `backend/src/Message/Application/EventListener/PropagateMediaReadyListener.php`
- Create: `backend/src/Message/Application/Query/MessagesCarryingMediaReaderInterface.php`, `backend/src/Message/Infrastructure/Persistence/DbalMessagesCarryingMediaReader.php`

**Interfaces:**
- Produces : `MessagesCarryingMediaReaderInterface::carrying(MediaId): array` annotée `@return list<array{messageId: MessageId, conversationId: ConversationId}>`. La requête est un `SELECT mm.message_id, m.conversation_id FROM message_media mm JOIN messages m ON m.id = mm.message_id WHERE mm.media_id = :media_id` — le `UNIQUE (media_id)` en base garantit au plus une ligne, mais la signature reste une liste : c'est le schéma qui borne, pas le type de retour.
- Create: `backend/src/Realtime/Application/EventListener/PublishMessageMediaBecameReadyListener.php`
- Test: `backend/tests/Functional/Media/MediaReadyPublicationTest.php`
- Create (non prévu) : `backend/src/Shared/Application/Bus/DomainEventDispatcherInterface.php` + `backend/src/Shared/Infrastructure/Bus/DomainEventDispatcher.php`. Le Step 3 ci-dessous affirmait qu'un tel port existait déjà — **c'était faux** : les listeners existants réagissent avec une *commande* (`CommandDispatcherInterface`), aucun n'émet un second domain event. Le collecteur ne convenait pas : un listener tourne **après** le commit, donc hors de la boucle de `TransactionalMiddleware` qui vide le collecteur.
- Modify (non prévu) : `backend/config/packages/messenger.yaml` — le transport `media` passe de `in-memory://` à `test://` en test. C'est le DSN de `zenstruck/messenger-test`, le seul qui offre `->process()`. Sans lui, un test ne peut asserter que ce qui **part** vers la file, jamais ce que le worker en fait — or toute la chorégraphie démarre côté consommation, dans `TransactionalMiddleware`.

- [ ] **Step 1: Écrire le test**

`backend/tests/Functional/Media/MediaReadyPublicationTest.php`, contre l'espion `InMemoryEventPublisher` :

| Cas | Attendu |
|---|---|
| média traité **après** l'envoi | un `message.media_ready` sur `/conversations/{id}`, `payload['media']['status'] === 'ready'` |
| média traité **avant** tout envoi | **aucune** publication — le listener ne trouve aucun message porteur |
| média **rejeté** après l'envoi | un `message.media_ready` avec `status === 'rejected'` et aucune URL |
| charge utile | **aucune** clé de stockage nulle part dans `payload` |
| identifiant SSE | `id === null` |

Les cas 1 et 2 sont le cœur : le second ne doit passer par **aucun `if`** dans le code — c'est la requête qui ne trouve rien.

- [ ] **Step 2: L'événement partagé**

`MessageMediaBecameReady` : `MessageId $messageId`, `ConversationId $conversationId`, `MediaId $mediaId`. Rien d'autre — le `MediaView` complet est resigné à la publication, une URL n'ayant pas sa place dans un événement.

- [ ] **Step 3: Le listener de `Message`**

```php
final readonly class PropagateMediaReadyListener implements DomainEventListenerInterface
{
    public function __construct(
        private MessagesCarryingMediaReaderInterface $reader,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(MediaWasProcessed $event): void
    {
        // Si aucun message ne porte encore ce media — traitement termine avant
        // l'envoi — la liste est vide et rien n'est publie. AUCUN `if` : le
        // comportement correct tombe de la requete (spec §3.5).
        foreach ($this->reader->carrying($event->mediaId) as $carrier) {
            $this->events->dispatch(new MessageMediaBecameReady(
                $carrier['messageId'],
                $carrier['conversationId'],
                $event->mediaId,
            ));
        }
    }
}
```

~~`EventDispatcherInterface` ici est le port de `Shared/Application/` déjà utilisé par les listeners existants~~ — **ce port n'existait pas.** Il a été créé pour l'occasion sous le nom `DomainEventDispatcherInterface` (voir la liste de fichiers ci-dessus), aligné sur `DomainEventListenerInterface` / `DomainEventCollectorInterface` et distinct de l'`EventDispatcherInterface` de Symfony. La consigne de fond tient : ne pas injecter `MessageBusInterface` dans `Application`.

- [ ] **Step 4: Le listener de `Realtime`**

Calqué sur `PublishMessageWasSentListener`. Il consulte `MediaFinderInterface` — le contrat publié de Media, nommé directement, `MediaFinderPortInterface` ayant été abandonné en tâche 7 — pour obtenir le `MediaView` frais et signé, puis :

```php
        $this->publisher->publish(
            Topic::conversation($event->conversationId),
            'message.media_ready',
            [
                'message_id' => $event->messageId->toString(),
                'conversation_id' => $event->conversationId->toString(),
                'media' => $view->toArray(),
            ],
            // AUCUN id fourni par le publieur : l'ULID du message est deja
            // celui de `message.created`. Le reutiliser ferait deux evenements
            // distincts sous un meme Last-Event-ID (spec §6.2, decision de T3).
            null,
        );
```

> `Realtime` consomme `MediaContract` : ajouter `MediaContract` à sa ligne d'allowlist dans `deptrac-contexts.yaml`. C'est un élargissement délibéré, à mentionner dans le message de commit.

- [ ] **Step 5: Vérifier de bout en bout, en vrai**

```bash
make up
```

Ouvrir deux navigateurs (Alice et Bob), envoyer une image depuis Alice, et **constater chez Bob** le passage du placeholder à l'image **sans rafraîchir**. C'est le critère d'acceptation n°2 de la spec ; aucun test automatisé ne le remplace.

> **Reporté après la tâche 10.** Cette vérification est infaisable ici : le front ne sait ni envoyer une image (tâche 9) ni afficher les trois états (tâche 10). Il n'existe donc aucun placeholder à regarder passer. À faire dès la tâche 10 terminée — la chaîne backend est couverte par `MediaReadyPublicationTest`, mais **rien ne prouve encore que le front consomme `message.media_ready`**.

- [ ] **Step 6: Quatre portes, commit**

```bash
git commit -m "feat(realtime): pousser la mise a jour d'un media traite

Trois sauts, aucun contexte ne pilote l'autre : Media publie un fait,
Message le traduit en fait metier — lui seul sait quels messages portent le
media et dans quel fil — et Realtime publie.

Si le traitement finit avant l'envoi, aucun message ne porte encore le
media : la requete ne rend rien et rien n'est publie. Aucun `if`.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9 : Front — envoyer une image

Nicolas est novice côté front : **commenter généreusement**, et expliquer le *pourquoi*, pas le *quoi*.

**Files:**
- Modify: `frontend/src/api/types.ts`, `frontend/src/api/client.ts`
- Create: `frontend/src/api/upload.ts`, `frontend/src/hooks/useMediaUpload.ts`
- Modify: `frontend/src/ui/Composer.tsx`, `frontend/src/hooks/useAppState.ts`
- Test: `frontend/src/hooks/useMediaUpload.test.ts`

**Interfaces:**
- Produces : `ApiMedia = { id: string; status: 'pending' | 'processing' | 'ready' | 'rejected'; mime_type: string | null; width: number | null; height: number | null; url: string | null; thumbnail_url: string | null }`, et `media: ApiMedia[]` sur `ApiMessage`. `useMediaUpload()` rend `{ pending: PendingUpload[]; add(file: File): Promise<void>; remove(localId: string): void; takeMediaIds(): string[] }`.

- [ ] **Step 1: Types et client**

Dans `types.ts`, ajouter `ApiMedia` et `media: ApiMedia[]` sur `ApiMessage`. Dans `client.ts` :

```ts
  presignUpload: (filename: string, contentType: string, size: number) =>
    request<{ media_id: string; upload_url: string; expires_at: string }>('/api/media', {
      method: 'POST',
      body: JSON.stringify({ filename, content_type: contentType, size }),
    }),

  confirmUpload: (mediaId: string) =>
    request<void>(`/api/media/${mediaId}/uploaded`, { method: 'POST' }),
```

- [ ] **Step 2: Le `PUT` brut, à part**

`frontend/src/api/upload.ts` :

```ts
/**
 * Le PUT des octets vit HORS du client HTTP typé, et ce n'est pas un oubli.
 *
 * Cette requête ne vise pas notre API : elle vise le stockage objet, par une
 * URL signée. Trois différences qui interdisent de la faire passer par
 * `client.ts` :
 *  - elle ne doit PAS porter nos cookies de session (`credentials: 'omit'`) —
 *    les envoyer à un tiers serait une fuite ;
 *  - elle ne rend pas de Problem Details : en cas d'échec, il n'y a qu'un
 *    statut et du XML S3 ;
 *  - son `Content-Type` est SIGNÉ. Envoyer autre chose que ce que le serveur a
 *    signé fait échouer la requête avec `SignatureDoesNotMatch`, pas avec une
 *    erreur de validation.
 *
 * Les octets ne passent jamais par notre backend : c'est tout l'intérêt.
 */
export async function putBytes(uploadUrl: string, file: File): Promise<void> {
  const response = await fetch(uploadUrl, {
    method: 'PUT',
    credentials: 'omit',
    // Doit être EXACTEMENT le type déclaré à la pré-signature.
    headers: { 'Content-Type': file.type },
    body: file,
  });

  if (!response.ok) {
    throw new Error(`Le transfert a échoué (${response.status}).`);
  }
}
```

- [ ] **Step 3: Écrire le test du hook**

`frontend/src/hooks/useMediaUpload.test.ts` — sur le cycle, avec `fetch` et `URL.createObjectURL` doublés :

| Cas | Assertion |
|---|---|
| ajout d'un fichier | `pending` contient une entrée avec un `previewUrl` |
| upload réussi | l'entrée passe à `uploaded`, `takeMediaIds()` rend l'id serveur |
| upload échoué | l'entrée passe à `failed`, `takeMediaIds()` **ne la rend pas** |
| `remove()` | `URL.revokeObjectURL` est appelé **une fois** avec le `previewUrl` |
| démontage avec des entrées en attente | `revokeObjectURL` appelé pour **chacune** |

Les deux derniers cas sont le vrai sujet de ce hook, pas un détail : voir l'étape suivante.

- [ ] **Step 4: Écrire le hook**

`frontend/src/hooks/useMediaUpload.ts`. Le commentaire d'en-tête doit expliquer la révocation :

```ts
/**
 * Cycle complet d'un envoi d'image : pré-signature → PUT direct → confirmation.
 *
 * ## Pourquoi un aperçu local
 *
 * Entre le moment où l'utilisateur choisit son fichier et celui où le serveur
 * a une miniature à servir, il s'écoule plusieurs secondes. Pendant ce temps,
 * le navigateur possède déjà les octets : `URL.createObjectURL(file)` fabrique
 * une URL `blob:` qui pointe vers eux, en mémoire, sans aucun réseau.
 *
 * ## Pourquoi il FAUT révoquer cette URL
 *
 * Tant qu'une `blob:` URL existe, le navigateur garde le fichier ENTIER en
 * mémoire — il ne peut pas savoir que plus personne ne l'affichera. Une photo
 * de 8 Mo oubliée ainsi reste en mémoire tant que l'onglet vit. Envoyer vingt
 * images sans révoquer, c'est 160 Mo retenus pour rien.
 *
 * `URL.revokeObjectURL(url)` rend cette mémoire. On le fait à deux moments :
 *  - quand l'utilisateur retire une image avant de l'envoyer ;
 *  - au démontage du composant, pour tout ce qui reste en attente.
 *
 * C'est la fuite classique de ce motif, et elle est invisible : rien ne casse,
 * l'onglet grossit simplement jusqu'à ramer.
 */
```

Le corps : un `useState<PendingUpload[]>`, un `useRef` sur la liste courante pour que le `useEffect` de nettoyage lise l'état au démontage sans se relancer à chaque changement, et un `useEffect(() => () => { … revoke all … }, [])`.

- [ ] **Step 5: Brancher le compositeur**

`Composer.tsx` reste bête : un `<input type="file" accept="image/*" multiple>`, une rangée de vignettes avec une croix par vignette, et `onSend(content, mediaIds)`. Le composant ne connaît toujours ni le réseau ni le `client_message_id`.

`useAppState.ts` : l'envoi optimiste porte désormais `media` — construit depuis les aperçus locaux, avec `status: 'processing'` — pour que le message apparaisse immédiatement avec ses images chez l'expéditeur.

- [ ] **Step 6: Vérifier**

```bash
make front-test
make front-typecheck
```

Puis, dans un vrai navigateur (`make up`, `http://localhost:8080`) : choisir une image, la voir apparaître en vignette, l'envoyer, la voir passer de « en cours » à l'image.

> Si Vite sert du code périmé après un `git checkout`, redémarrer le conteneur : `make restart SERVICE=frontend`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/
git commit -m "feat(front): televerser une image depuis le compositeur

Le PUT des octets vit hors du client HTTP type : il ne vise pas notre API,
ne doit pas porter nos cookies, et son Content-Type est signe.

L'apercu local passe par une blob: URL, revoquee au retrait et au
demontage — sans quoi le navigateur retient chaque fichier entier en
memoire tant que l'onglet vit.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 10 : Front — afficher les trois états

**Files:**
- Modify: `frontend/src/store/messagesReducer.ts`, `frontend/src/realtime/RealtimeClient.ts` (ou le point de branchement des événements dans `useAppState`)
- Create: `frontend/src/ui/MessageMedia.tsx`
- Modify: `frontend/src/ui/MessageList.tsx`
- Test: `frontend/src/store/messagesReducer.test.ts`, `frontend/src/ui/MessageMedia.test.tsx`

- [ ] **Step 1: Écrire les tests du reducer**

Ajouter à `messagesReducer.test.ts` :

| Cas | Assertion |
|---|---|
| `media/ready` sur un message présent | le média correspondant est remplacé, les autres sont intacts |
| `media/ready` sur un message absent du thread | l'état est rendu **inchangé**, sans lever |
| `media/ready` sur un média déjà `ready` | idempotent, même état |
| `media/ready` avec `status: 'rejected'` | le média passe à `rejected` |

Le deuxième cas compte : l'événement SSE arrive pour toutes les conversations auxquelles on est abonné, y compris celles dont le fil n'est pas chargé.

- [ ] **Step 2: Étendre le reducer**

```ts
  | { type: 'media/ready'; conversationId: string; messageId: string; media: ApiMedia }
```

et la branche correspondante : trouver le message par `id` serveur, remplacer l'entrée de `media` dont l'`id` correspond, laisser le reste tel quel. Un `messageId` inconnu rend `state` — **la même référence**, pour que React ne re-rende pas.

- [ ] **Step 3: Brancher l'événement SSE**

Là où `message.created` / `message.edited` / `message.deleted` sont déjà traités, ajouter `message.media_ready` → `dispatch({ type: 'media/ready', … })`.

- [ ] **Step 4: Écrire `MessageMedia.tsx`**

```tsx
/**
 * Les trois états d'une image dans un fil.
 *
 * `processing` affiche un placeholder AUX PROPORTIONS de l'image quand on les
 * connaît. Ce n'est pas de la coquetterie : sans hauteur réservée, la liste
 * saute au moment où l'image arrive, et le lecteur perd sa ligne. C'est le
 * même problème que le décalage de mise en page sur une page web lente.
 *
 * `onError` recharge la page de messages. L'erreur attendue n'est PAS une
 * image cassée : c'est une URL signée EXPIRÉE, dans un onglet resté ouvert
 * plus de quinze minutes. Recharger en obtient une fraîche.
 */
```

Trois branches : `rejected` → un bloc « Fichier refusé » ; `ready` → `<img src={media.thumbnail_url}>` dans un `<a href={media.url}>` ; tout le reste (`pending`, `processing`) → le placeholder.

- [ ] **Step 5: Vérifier**

```bash
make front-test && make front-typecheck
```

Puis les deux navigateurs : Alice envoie, Bob voit le placeholder puis l'image, **sans rafraîchir**.

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(front): afficher les trois etats d'un media

Le placeholder reserve les proportions connues : sans hauteur reservee, la
liste saute a l'arrivee de l'image et le lecteur perd sa ligne.

onError recharge la page : l'erreur attendue est une URL signee expiree
dans un onglet reste ouvert, pas une image cassee.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 11 : La purge des orphelins

**Files:**
- Create: `backend/src/Media/Application/Command/PurgeOrphanMediaCommand.php` + `…Handler.php`, `Query/OrphanMediaReaderInterface.php`
- Create: `backend/src/Media/Infrastructure/Persistence/DbalOrphanMediaReader.php`, `backend/src/Media/Infrastructure/Console/PurgeOrphanMediaConsoleCommand.php`
- Test: `backend/tests/Functional/Media/PurgeOrphanMediaTest.php`

- [ ] **Step 1: Écrire le test**

| Cas | Attendu |
|---|---|
| média `processing` de 30 h, non attaché | supprimé (ligne **et** objet) |
| média `ready` de 30 h, non attaché | supprimé |
| média `ready` de 30 h, **attaché** à un message | **conservé** |
| média `pending` de 2 h | **conservé** — l'upload est peut-être en cours |
| média dont le message a été supprimé pour tous (T4 §7.2) | supprimé — c'est le cas qui rend la suppression effective |

Le dernier cas est le plus important : il ferme la boucle ouverte par la tâche 6.

- [ ] **Step 2: Le reader**

```sql
SELECT m.id, m.storage_key, m.thumbnail_key
FROM media_objects m
WHERE m.created_at < :threshold
  AND NOT EXISTS (SELECT 1 FROM message_media mm WHERE mm.media_id = m.id)
ORDER BY m.created_at
LIMIT :limit
```

`LIMIT` borné (500) pour qu'une première exécution sur un historique chargé ne tienne pas la base. Le handler **loggue** ce qu'il a laissé : un plafond silencieux se lirait comme « tout a été purgé ».

- [ ] **Step 3: Le handler et la commande console**

Pour chaque orphelin : `delete()` sur l'original et sur la miniature si elle existe, puis `DELETE FROM media_objects`. Dans cet ordre — si la suppression d'objet échoue, la ligne reste et la purge suivante réessaiera. L'inverse laisserait des octets sans référence, donc invisibles pour toujours.

`media:purge-orphans` dispatche la commande et affiche le compte. Elle se lance à la main :

```bash
docker compose run --rm backend bin/console media:purge-orphans
```

Pas de planificateur : il n'y en a pas dans le projet, et en ajouter un pour une seule commande serait de l'infrastructure non justifiée (spec §7.3).

- [ ] **Step 4: Quatre portes, commit**

```bash
git commit -m "chore(medias): ramasser les medias orphelins

Les octets partent AVANT la ligne : si la suppression d'objet echoue, la
ligne reste et la purge suivante reessaiera. L'inverse laisserait des
octets sans reference, donc invisibles pour toujours.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 12 : Documentation

**Files:**
- Modify: `CLAUDE.md`, `README.md`
- Modify: `docs/superpowers/plans/2026-07-26-tranche-4-medias.md` (cocher les cases)

- [ ] **Step 1: `CLAUDE.md`**

Trois endroits, et trois seulement :

- **Infrastructure** : « 5 conteneurs » devient « 8 conteneurs », avec `minio`, `rabbitmq` et `worker` décrits en une ligne chacun. Ajouter la phrase sur le routage `/messaging-media/*` — c'est un choix qu'on ne veut pas voir défait par inadvertance.
- **Architecture** : `Media` rejoint la liste des contextes.
- **Périmètre** : « Tranche 4 en cours » → « Tranche 4 livrée. Tranche 5 : recherche & modération ». Les aperçus de liens y sont mentionnés comme tranche à part.

**Ne pas** ajouter de section « médias » : `CLAUDE.md` documente les règles, pas les fonctionnalités.

- [ ] **Step 2: `README.md`**

La section d'installation gagne : le port `9001` (console MinIO), le port `15672` (console RabbitMQ), et la commande `media:purge-orphans`. Rien de plus.

- [ ] **Step 3: Vérifier la tranche entière**

Dérouler les **dix critères d'acceptation** de la spec, un par un, dans un vrai navigateur. Ils sont écrits pour être exécutables ; aucun ne doit être coché sans avoir été observé.

- [ ] **Step 4: Commit final et pull request**

```bash
make static-code-analysis && make check-cs && make deptrac && make test && make front-test && make front-typecheck
git commit -m "docs(medias): consigner la tranche 4

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
git push -u origin feat/tranche-4-medias
gh pr create --title "feat: tranche 4 — pieces jointes" --body "$(cat <<'BODY'
Un message peut porter des images. Les octets vont du navigateur au stockage
objet et en reviennent sans jamais traverser PHP ni le hub.

- 6e contexte borne `Media` : pre-signature, inspection asynchrone, cycle de
  vie propre. Il ignore l'existence des messages et des conversations.
- Le type declare par le client n'est jamais cru : `finfo` sur les octets
  reels, puis decodage. Un fichier PHP renomme `.jpg` est refuse au
  traitement, pas a l'affichage.
- Le message part immediatement en `processing` et se met a jour par
  `message.media_ready`, via une choregraphie a trois sauts.
- MinIO derriere l'origine unique : le PUT navigateur est same-origin, donc
  ni CORS sur le bucket, ni entree dans `/etc/hosts`.

Spec : `docs/superpowers/specs/2026-07-26-instant-messaging-tranche-4-design.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

---

## Ce que ce plan ne fait délibérément pas

Repris de la section 12 de la spec, pour qu'aucune tâche ne dérive : aperçus de liens et défense SSRF · vidéo, audio, documents · plusieurs résolutions · CDN · antivirus · quotas par utilisateur · *resumable uploads* · chiffrement au repos · *POST policy* pour plafonner au transfert · réordonnancement des médias après envoi · téléchargement sous le nom d'origine · planificateur pour la purge · rejeu manuel d'un traitement échoué.
