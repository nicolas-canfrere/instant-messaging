# Instant Messaging — Tranche 3 : cycle de vie des messages

> Sources conceptuelles : [[Suppression et édition des messages]] et [[Gestion du temps (dates et fuseaux)]]
> du vault Obsidian `tech/InstantMessaging`. Ce document ne réexplique pas les concepts — il fige les
> **décisions d'implémentation** et renvoie aux notes.
>
> Décisions transverses : [ADR 0001](../../adr/0001-cross-context-communication.md). Elles priment
> sur cette spec en cas de divergence.

## Contexte

Les tranches 1 (noyau temps réel + conversations) et 2 (accusés de réception et présence) sont
livrées. Un message, aujourd'hui, est **immuable** : envoyé, il ne peut être ni corrigé ni retiré.
La tranche 3 lui donne un cycle de vie.

### La thèse de la tranche

**Il n'y a pas *un* « supprimer ».** La note du vault en distingue cinq sémantiques, et les confondre
est l'erreur classique. Cette tranche en implémente **une seule**, complètement, et écrit pourquoi les
quatre autres sont hors périmètre — c'est ce tri qui est intéressant, pas l'accumulation.

La règle qui structure tout le reste tient en cinq mots : **record soft, payload hard.** On garde
l'enregistrement (id, expéditeur, instant, donc l'ordre) et on **efface réellement le contenu côté
serveur**. Masquer à l'affichage ne suffirait pas : un client modifié lirait encore la charge utile.

### Ce que la tranche ajoute, en une phrase par élément

| Élément | Une phrase |
|---|---|
| **Supprimer pour tous** | `content` mis à `NULL` en base, tombstone conservé, `message.deleted` propagé |
| **Éditer** | contenu remplacé dans une fenêtre de 15 min, `edited_at` stampé, `message.edited` propagé |
| **Aperçu de conversation** | rafraîchi par chorégraphie quand le message édité ou supprimé est le dernier |
| **Fuseaux horaires** | séparateurs de date et temps relatif calculés dans le fuseau du **viewer** |

### Décisions prises pendant le design

| Question | Décision | Section |
|---|---|---|
| Quelles sémantiques de suppression ? | **« pour tous » seule** — pas de `message_hidden`, pas de modération, pas de RGPD | [§1](#section-1--une-seule-sémantique-de-suppression) |
| Historique des versions ? | **non** — `edited_at` seul | [§1.2](#12-édition--pas-dhistorique-des-versions) |
| Fenêtre temporelle ? | **édition ≤ 15 min, suppression sans limite** | [§3.2](#32-la-fenêtre-dédition-est-un-invariant-de-lagrégat) |
| Forme du tombstone dans le contrat | **`content: null` + `deleted_at`** | [§6.1](#61-messageview-change-de-forme) |
| Routes | **imbriquées sous la conversation** | [§5.1](#51-les-deux-routes) |
| `id` d'événement Mercure sur les nouveaux événements | **aucun** | [§4.2](#42-aucun-id-dévénement-mercure-et-cest-une-décision) |
| Un message supprimé reste-t-il non lu ? | **oui** | [§6.3](#63-ce-que-la-tranche-2-ne-remarque-même-pas) |
| Fuseau utilisateur persisté ? | **non** | [§8.4](#84-le-fuseau-nest-pas-persisté) |

---

## Section 1 — Une seule sémantique de suppression

La note du vault liste cinq « supprimer ». Voici lesquels sont retenus, et le coût de chaque exclusion.

| Sémantique | Retenue ? | Motif |
|---|---|---|
| **Supprimer pour tous** (unsend) | **oui** | c'est le cœur de la note : soft-delete de l'enregistrement, hard-delete de la charge utile, événement de rétractation propagé |
| Supprimer pour moi | non | table `message_hidden(user_id, message_id)`, filtre supplémentaire sur la requête keyset, état front distinct. Le pattern est compris ; le coder n'apprend rien de plus que le unsend |
| Retrait par un modérateur | non | la tranche 5 porte la modération. Le mécanisme est identique au unsend avec un acteur différent — l'ajouter ici, c'est déborder d'une tranche sur la suivante |
| Effacement RGPD | non | hard delete réel et crypto-shredding ; le second suppose le chiffrement de bout en bout, qui n'est dans aucune tranche |
| Messages éphémères | non | purge par TTL, donc une tâche planifiée et une infrastructure d'ordonnancement absentes du projet |

### 1.1 Ce que « pour tous » veut dire, précisément

> [!important] Record soft, payload hard
> La ligne `messages` **reste**, avec son `id`, son `sender_id`, son `created_at` et sa place dans
> l'ordre. La colonne `content` passe à **`NULL`**. Ce n'est pas un drapeau d'affichage : le contenu
> n'est plus récupérable par aucune requête.

Trois raisons rendent le tombstone obligatoire plutôt que le hard delete, et toutes les trois sont
vérifiables dans ce dépôt :

1. **Réalité distribuée.** Le message est déjà parti sur le hub et affiché sur N appareils. On ne
   « dé-envoie » pas des octets — « supprimer pour tous » est un **événement de rétractation** qui se
   propage, donc soft par nature.
2. **Références pendantes.** Les watermarks `last_read_message_id` et `last_delivered_message_id` de
   la tranche 2 pointent des identifiants de messages. Un hard delete les casserait ; le tombstone
   préserve **id et ordre**, donc la tranche 2 n'a rien à savoir de la tranche 3 (§6.3).
3. **Pagination keyset.** Retirer la ligne creuserait un trou dans `WHERE id < :before ORDER BY id
   DESC` et réveillerait la classe de bugs que la tranche 1 a fermée.

### 1.2 Édition : pas d'historique des versions

`edited_at` est stampé, `content` est remplacé, l'ancien contenu est perdu. Pas de table
`message_revisions`.

**Ce que coûterait l'historique** : une table, une migration, une query, un écran front — et surtout
une contradiction à résoudre. L'historique des versions conserverait le contenu qu'un unsend ultérieur
est censé effacer réellement ; il faudrait donc le purger au unsend, c'est-à-dire écrire du code dont
le seul rôle est d'annuler la fonctionnalité qu'on vient d'ajouter. Le versioning est un sujet
d'**audit**, il appartient à la tranche 5 avec la modération, où il aura un consommateur.

---

## Section 2 — Modèle de données

### 2.1 La migration

Une seule migration, en SQL explicite comme toutes les autres.

```sql
ALTER TABLE messages
    ALTER COLUMN content DROP NOT NULL,
    ADD COLUMN edited_at  TIMESTAMPTZ DEFAULT NULL,
    ADD COLUMN deleted_at TIMESTAMPTZ DEFAULT NULL,
    ADD CONSTRAINT messages_tombstone_has_no_payload
        CHECK ((deleted_at IS NULL) = (content IS NOT NULL));
```

Avec les commentaires de colonnes que le projet pose systématiquement :

| Colonne | Commentaire |
|---|---|
| `messages.content` | `NULL` uniquement sur un message supprimé pour tous : la charge utile est réellement effacée. |
| `messages.edited_at` | Instant de la dernière édition, en UTC. `NULL` si jamais édité. |
| `messages.deleted_at` | Instant de la suppression pour tous, en UTC. `NULL` si vivant. |

### 2.2 Le `CHECK` porte l'invariant

`(deleted_at IS NULL) = (content IS NOT NULL)` se lit « un message est vivant si et seulement si il a
une charge utile ». C'est l'invariant central de la tranche, écrit à l'endroit où la base peut le
tenir elle-même.

Ce n'est pas une redondance avec le domaine : l'agrégat garantit l'invariant pour le code qui passe
par lui, le `CHECK` le garantit pour une migration future, une correction manuelle en `psql` ou une
fixture bâclée. La contrainte échoue bruyamment plutôt que de laisser un tombstone bavard vivre en
base.

### 2.3 Deux colonnes de la note sont écartées

La note propose `deleted_by` et `deletion_scope`. Aucune des deux n'est retenue.

| Colonne écartée | Motif |
|---|---|
| `deleted_by` | seul l'auteur supprime (§3.1). La colonne vaudrait donc toujours `sender_id` — une donnée dérivable n'est pas une donnée |
| `deletion_scope` | une seule valeur possible, `'everyone'`. Une colonne à valeur unique n'informe rien ; elle documente une intention future, ce que fait déjà cette spec |

Le jour où la tranche 5 introduit le retrait par un modérateur, `deleted_by` devient une migration
`ADD COLUMN` triviale — et à ce moment-là elle aura un lecteur. Les poser maintenant, ce sont deux
colonnes dont aucune requête ne lit la valeur, c'est-à-dire du schéma mort.

### 2.4 Ce qui ne change pas

Aucune table nouvelle. Aucun index nouveau : la requête dominante reste
`(conversation_id, id DESC)`, et rien ne filtre sur `deleted_at` — les tombstones sont **rendus**,
pas masqués (§6.2).

---

## Section 3 — Domaine : l'agrégat `Message` cesse d'être immuable

Jusqu'ici `Message` n'avait que deux constructeurs nommés et des accesseurs. La tranche 3 lui donne
deux méthodes qui mutent.

```php
final class Message
{
    public const int EDIT_WINDOW_SECONDS = 900;

    public function edit(UserId $editor, MessageContent $content, \DateTimeImmutable $now): void;
    public function deleteForEveryone(UserId $actor, \DateTimeImmutable $now): void;
}
```

L'état interne gagne `?MessageContent $content` (`null` ⇔ supprimé), `?\DateTimeImmutable $editedAt`,
`?\DateTimeImmutable $deletedAt`.

### 3.1 Table de décision

| Situation | Effet | Statut HTTP |
|---|---|---|
| `$editor`/`$actor` ≠ `senderId` | `NotTheAuthorException` | **403** |
| édition plus de 15 min après `createdAt` | `EditWindowExpiredException` | **403** |
| édition d'un message déjà supprimé | `MessageAlreadyDeletedException` | **409** |
| **édition avec un contenu identique à l'actuel** | **no-op, aucun événement enregistré** | 200 |
| **suppression d'un message déjà supprimé** | **no-op, aucun événement enregistré** | 204 |

Les exceptions vivent dans `Message/Domain/` et **ignorent totalement HTTP** : la traduction en statut
est la responsabilité exclusive du listener de `Shared/Infrastructure` (§5.3).

**Seul l'auteur agit.** Pas de rôle, pas de délégation, pas d'admin de groupe — la modération est en
tranche 5. Conséquence directe : l'invariant vit dans l'agrégat, pas dans un voter, et se teste sans
conteneur de services.

### 3.2 La fenêtre d'édition est un invariant de l'agrégat

**Édition ≤ 15 minutes après l'envoi ; suppression sans limite de temps.**

L'asymétrie est intentionnelle et vaut d'être écrite. Supprimer tardivement reste légitime — le regret
n'a pas de date de péremption, et le résultat est un tombstone visible de tous, donc honnête. Éditer
tardivement **réécrit l'histoire d'une conversation déjà lue** : les destinataires ont vu le message
d'origine, et rien dans l'interface ne leur dira ce qu'il disait. Une fenêtre courte cantonne l'édition
à ce qu'elle sert vraiment, la correction d'une faute de frappe.

Ce que ça apporte techniquement : un invariant **temporel** porté par l'agrégat, testable en gelant
l'horloge, sans base de données ni requête HTTP. C'est exactement la justification donnée en tranche 1
pour faire de l'horloge un port — elle trouve ici son second usage.

> [!note] L'horloge substituable est **posée par cette tranche**, elle n'existait pas avant
> Les handlers reçoivent bien un `Psr\Clock\ClockInterface` depuis la tranche 1, mais aucune
> substitution n'était configurée : l'environnement de test utilisait l'horloge réelle, et un test
> ne pouvait donc pas faire vieillir un message. `config/services_test.yaml` alias désormais le port
> vers la `MockClock` de `symfony/clock`, gelée et publique. Sans elle, la fenêtre d'édition n'aurait
> de couverture qu'unitaire, et sa **traduction** en 403 `/problems/edit-window-expired` — le chemin
> qu'emprunte un appel forgé, donc le critère d'acceptation n°3 — ne serait exercée par aucun test.

`EDIT_WINDOW_SECONDS = 900` est une constante de l'agrégat, en `SCREAMING_SNAKE_CASE`, et non un
paramètre de configuration : c'est une règle métier, pas un réglage d'exploitation.

### 3.3 Les deux no-op sont le mécanisme d'idempotence, pas une optimisation

> [!important]
> Éditer avec le contenu actuel, ou supprimer un message déjà supprimé, **n'enregistre aucun domain
> event**. La publication Mercure et le rafraîchissement de l'aperçu en découlent — ou plutôt n'en
> découlent pas.

C'est la transposition exacte du mécanisme de la tranche 1 : `Message::reconstitute()` n'enregistre
rien, donc un rejeu idempotent ne republie rien, **sans un seul `if` dans le handler**. Ici, la
condition est dans l'agrégat, à l'endroit où l'état est connu, et le reste de la chaîne — middleware
transactionnel, listeners, publication — n'a rien à savoir.

Bénéfice concret et vérifiable : `DELETE` rejoué répond **204 puis 204** et ne produit **qu'une seule**
publication Mercure. `DELETE` redevient l'opération idempotente que HTTP promet, par construction.

### 3.4 `reconstitute()` n'enregistre toujours rien

La signature s'allonge de trois paramètres nullables. La règle de la tranche 1 est intacte et le
commentaire qui la protège dans le code reste valable mot pour mot : **ne pas ajouter d'enregistrement
d'événement dans `reconstitute()`**. Un message relu depuis la base pour être édité ne doit pas
republier son envoi.

### 3.5 Écriture : lire l'agrégat, muter, sauvegarder

`MessageRepositoryInterface` gagne deux méthodes :

```php
public function ofId(ConversationId $conversationId, MessageId $messageId): Message;  // ou lève MessageNotFoundException
public function save(Message $message): void;
```

`ofId()` prend **les deux identifiants** : un message demandé dans la mauvaise conversation est
introuvable, point. La règle anti-oracle de la tranche 1 est ainsi portée par la signature du port,
pas par la vigilance de l'appelant (§5.4).

`save()` écrit un `UPDATE` explicite sur les trois colonnes mutables et collecte les événements
enregistrés, exactement comme `insertIfAbsent()` :

```sql
UPDATE messages
SET content = :content, edited_at = :edited_at, deleted_at = :deleted_at
WHERE id = :id
```

---

## Section 4 — Temps réel

### 4.1 Deux événements partagés, deux événements Mercure

Deux nouveaux domain events dans `Shared/Domain/Event/` :

```php
final readonly class MessageWasEdited implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public string $content,
        public \DateTimeImmutable $editedAt,
    ) {}
}

final readonly class MessageWasDeleted implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public \DateTimeImmutable $deletedAt,
    ) {}
}
```

**Pourquoi dans `Shared` et pas dans `Message`** : chacun est écouté par **deux** contextes,
`Realtime` (qui publie) et `Conversation` (qui rafraîchit son aperçu). C'est précisément le critère
posé par l'ADR 0001 — un événement qu'un seul contexte écoute reste chez lui. Charge utile de types
`Shared` et de scalaires uniquement : le contenu voyage en `string`, jamais en `MessageContent`.

`MessageWasDeleted` **ne transporte aucun contenu**. Un événement de rétractation qui embarquerait la
charge utile qu'il rétracte serait une contradiction — et le hub la diffuserait à tout le monde.

Publication sur le topic `/conversations/{id}`, en *private update*, construit par
`Topic::conversation()`. Un `publish` par événement, le fan-out reste au hub.

| Événement | Charge utile |
|---|---|
| `message.edited` | `{ id, conversation_id, sender_id, content, edited_at }` |
| `message.deleted` | `{ id, conversation_id, sender_id, deleted_at }` |

`edited_at` et `deleted_at` sont au format ISO 8601 `ATOM`, comme `created_at` — même format que
l'historique, sinon tout tri par chaîne mélange les deux sources.

### 4.2 Aucun `id` d'événement Mercure, et c'est une décision

La tranche 1 pose que l'`id` d'un événement Mercure est **l'ULID du message**, ce qui rendra
`Last-Event-ID` exploitable sans changer de format. La tranche 2 a déjà exempté `typing.started` et
`receipt.updated`, qui ne décrivent pas un point du fil.

Ici le motif est plus fort qu'une simple absence de pertinence. Éditer un message ancien émettrait un
événement portant un ULID **antérieur** à ceux déjà reçus par le client. Un `Last-Event-ID` qui recule
ferait rejouer au hub tout l'historique depuis ce point à la reconnexion suivante.

> **Un identifiant de reprise qui recule est pire que pas de reprise du tout.**

`message.edited` et `message.deleted` sont donc **non rejouables** : un client déconnecté pendant une
édition la découvrira en rechargeant la page d'historique, qui porte déjà l'état à jour. C'est le
comportement correct, et il est écrit plutôt que subi.

### 4.3 L'édition n'a pas besoin d'un `client_message_id`

L'envoi optimiste de la tranche 1 avait un problème dur : l'écho SSE part **avant** la réponse du
`POST`, et l'optimiste n'a alors aucun `id` serveur à opposer. D'où le `client_message_id` dans la
charge utile `message.created`.

L'édition n'a pas ce problème. **La clé de réconciliation existe déjà : c'est l'`id` serveur.** Le
message est en base, le front le connaît, et l'écho SSE comme la réponse du `PATCH` portent le même
état final. Les appliquer dans n'importe quel ordre, une ou deux fois, donne le même résultat.

C'est l'occasion d'expliciter la propriété qui rend tout ça sûr : **`message.edited` et
`message.deleted` transportent un état, pas un delta.** Une charge utile du genre « ajouter 3
caractères en position 12 » exigerait un ordre de livraison garanti, que SSE ne promet pas. Un état
complet est idempotent et commutatif — c'est ce qui permet de se passer d'accusé, de séquence et de
rejeu.

### 4.4 Publication après commit, comme le reste

Rien de nouveau : les événements sont enregistrés sur l'agrégat, collectés pendant la transaction, et
dispatchés **après le commit** par le middleware transactionnel du `command.bus`. Publier dans la
transaction pousserait une rétractation qu'un rollback ferait disparaître — un message ressuscité chez
les destinataires, invisible chez l'auteur.

---

## Section 5 — API HTTP

### 5.1 Les deux routes

| Méthode | Route | Corps | Réponse nominale |
|---|---|---|---|
| `PATCH` | `/api/conversations/{conversationId}/messages/{messageId}` | `{ "content": "…" }` | **200** + `MessageView` |
| `DELETE` | `/api/conversations/{conversationId}/messages/{messageId}` | — | **204**, y compris au rejeu |

**Routes imbriquées sous la conversation**, cohérentes avec toutes les routes existantes.
L'alternative — `/api/messages/{id}`, l'ULID étant globalement unique — obligerait à **charger le
message pour savoir quelle conversation autoriser**, et ferait porter au 404 deux causes distinctes
qu'il faut ensuite penser à ne pas distinguer. La route imbriquée rend la conversation disponible
avant tout accès au message ; l'anti-oracle en découle au lieu d'être une précaution (§5.4).

`PATCH` et non `PUT` : on modifie un champ d'une ressource dont l'`id`, l'expéditeur et l'instant
d'envoi ne sont pas remplaçables.

### 5.2 Les contrôleurs suivent le patron existant

Comme `SendMessageController` : le contrôleur désérialise, construit la commande, dispatche, puis
**pose une query** pour connaître l'effet. Le handler rend `void`, comme tous les handlers de commande
du projet — y compris ici, où il serait tentant de renvoyer le message édité.

L'appartenance est vérifiée **par le handler, dans la transaction**, via le port que `Message` utilise
déjà pour l'envoi. La contrôler aussi dans le contrôleur laisserait croire que c'est cette
vérification-là qui protège, alors qu'elle serait devançable.

Le `ConversationVoter` **n'est pas modifié** : il ne traite que la dimension du rôle, qui se traduit
en 403, et l'édition n'introduit aucun rôle. La qualité d'auteur est un invariant de l'agrégat, pas
une permission — la placer dans un voter la sortirait du domaine pour la mettre dans Symfony.

Corps du `PATCH` : un DTO `EditMessagePayload` dans `Message/Infrastructure/Http/Payload/`, avec ses
contraintes Symfony, monté par `#[MapRequestPayload]`. Le contrôleur reçoit un objet déjà désérialisé
et validé ; un corps mal formé produit un **422**, jamais un 500.

### 5.3 Trois entrées au catalogue RFC 7807

| `type` | `title` | Statut | Cas |
|---|---|---|---|
| `/problems/not-the-author` | `Vous n'êtes pas l'auteur de ce message` | **403** | membre de la conversation, mais pas l'auteur |
| `/problems/edit-window-expired` | `Ce message n'est plus modifiable` | **403** | plus de 15 min après l'envoi |
| `/problems/message-already-deleted` | `Ce message a été supprimé` | **409** | tentative d'édition d'un tombstone |

**403 et non 404 pour les deux premiers** : la règle de la tranche 1 dit 403 uniquement quand
l'appartenance est déjà établie et que seule l'autorisation manque. C'est exactement le cas — l'appelant
est membre de la conversation, il voit déjà le message dans son historique. Le 403 ne lui apprend rien
qu'il ne sache.

**409 et non 403 pour le troisième** : ce n'est pas un problème d'autorisation. L'auteur a parfaitement
le droit d'éditer son message ; c'est l'**état de la ressource** qui rend l'opération impossible.
`Conflict` est la sémantique exacte, et le client peut en déduire une action utile — rafraîchir, il a
une vue périmée.

La traduction exception → statut vit **uniquement** dans le listener de `Shared/Infrastructure`, comme
tout le reste. Les trois exceptions de `Message/Domain` ignorent HTTP.

### 5.4 Pas d'oracle d'existence, encore

Un `messageId` inconnu, ou appartenant à **une autre conversation**, donne un **404 indistinguable**.
La garantie est portée par la signature du port (§3.5) : `ofId()` exige les deux identifiants, donc
il n'existe aucun chemin de code capable de charger un message hors de sa conversation.

Un test fonctionnel verrouille le cas : même statut, même `type`, même `title` pour un identifiant
inventé et pour le message réel d'une conversation voisine.

---

## Section 6 — Contrat de lecture

### 6.1 `MessageView` change de forme

> [!warning] Changement cassant, assumé et versionné avec le front
> Modifier un `*View` est un changement cassant. Celui-ci l'est ; il est livré dans la même branche
> que l'adaptation du front.

```php
public ?string $content,      // null ⇔ supprimé pour tous
public ?string $editedAt,     // ISO 8601 ATOM, null si jamais édité
public ?string $deletedAt,    // ISO 8601 ATOM, null si vivant
```

**`content: null` plutôt que `content: ""`.** Une chaîne vide est indistinguable d'un contenu vide —
que `MessageContent` interdit précisément. `null` dit sans ambiguïté « il n'y a plus de charge utile »,
et PHPStan au niveau `max` comme TypeScript en mode strict **forcent** les deux côtés à traiter le cas
au lieu de l'oublier. Le type porte l'information ; la lire dans `deleted_at` reposerait sur la
discipline.

Les lectures SQL (`DbalMessagePageReader`, `DbalMessageReader`) sélectionnent les trois colonnes et le
mapper resserre les types — c'est la frontière désignée, comme toujours.

### 6.2 Le libellé n'existe pas côté serveur

« Ce message a été supprimé » n'apparaît **nulle part** dans le backend. C'est de la présentation :
le libellé vit dans `labels.ts`, avec les autres.

Le serveur dit qu'il n'y a plus de charge utile. Le client décide comment le dire — et pourra le dire
dans une autre langue le jour où l'interface sera traduite, sans toucher à l'API.

### 6.3 Ce que la tranche 2 ne remarque même pas

Aucun impact sur les accusés de réception ni sur les compteurs de non-lus. C'est la validation
concrète de l'argument « références pendantes » de §1.1 : les watermarks pointent des **identifiants**,
et le tombstone préserve id et ordre.

**Décision explicite : un message supprimé reste compté comme non lu.** Il a bien été reçu, sa place
dans l'ordre est inchangée, et le destinataire verra un tombstone — ce qui est une information, pas
un vide. Recompter les non-lus à chaque suppression demanderait à `Message` de piloter une écriture de
`Conversation`, ce que l'ADR 0001 interdit, pour corriger un compteur d'un cran.

Coût de cette décision : zéro ligne de code. Elle est écrite parce qu'une décision non écrite est un
oubli déguisé.

### 6.4 L'aperçu d'une conversation dont le dernier message est un tombstone

Supprimer le dernier message vide `last_message_preview` (§7). `ConversationList` affiche alors
« Aucun message », **par repli sur `last_message_preview ?? 'Aucun message'`** — et c'est faux : le
fil contient un tombstone, il n'est pas vide.

C'est une **conséquence assumée du choix de l'aperçu dénormalisé**. La liste de gauche est servie
sans jointure vers `messages` : elle ne dispose que d'une chaîne, et une chaîne absente ne peut pas
distinguer « rien n'a jamais été envoyé » de « le dernier message a été retiré ». Les deux se lisent
`NULL`.

Distinguer les deux cas demanderait à `ConversationSummary` de porter l'information — un troisième
état, ou un `last_message_deleted_at`. C'est un **changement de contrat de lecture**, donc un
changement cassant, pour un libellé. Hors périmètre de cette tranche.

Le jour où T4 ou T5 touchera à `ConversationSummary` pour une autre raison, le champ s'ajoutera dans
le même mouvement. En attendant, la limite est écrite : une limite écrite est une décision, une limite
découverte plus tard est un bug.

---

## Section 7 — Chorégraphie : l'aperçu de conversation

### 7.1 Le problème que ça résout

`conversations.last_message_preview` contient une copie du contenu du dernier message, écrite par le
listener de la tranche 1 pour supprimer la jointure vers `messages` sur l'écran d'accueil.

Vider `messages.content` sans toucher à cette copie rendrait **« payload hard » faux** : le contenu
supprimé continuerait de s'afficher dans la liste des conversations, à l'endroit le plus visible de
l'application.

### 7.2 Un contexte ne pilote pas l'écriture d'un autre

`Message` ne fait **pas** l'`UPDATE`. Il publie un fait ; `Conversation` écoute et met à jour **son**
pointeur par **sa** propre commande. C'est le mécanisme déjà en place pour `MessageWasSent`, réutilisé
tel quel.

```
Message  ──MessageWasEdited/MessageWasDeleted──►  Conversation
                                                  └─ RefreshLastMessagePreviewCommand
```

`LastMessagePointerWriterInterface` gagne une méthode :

```php
public function refreshPreview(
    ConversationId $conversationId,
    MessageId $messageId,
    ?string $preview,
): void;
```

### 7.3 La garde dans le `WHERE` fait tout le travail

```sql
UPDATE conversations
SET last_message_preview = :preview
WHERE id = :conversation_id
  AND last_message_id = :message_id
```

Le `AND last_message_id = :message_id` est le cœur du mécanisme. Si le message édité n'est plus le
dernier de la conversation, **zéro ligne touchée** — rien à décider, rien à lire au préalable, aucune
course entre un `SELECT` et un `UPDATE`. La condition et l'écriture sont la même instruction.

Même forme que le `record()` existant, dont le `WHERE` porte déjà la garde de monotonie
`last_message_id IS NULL OR last_message_id < :message_id`.

`Conversation` ne consulte toujours **jamais** la table `messages` : le contenu à écrire lui arrive
dans la charge utile de l'événement, et `NULL` pour une suppression.

---

## Section 8 — Front

### 8.1 Le store, où vit la logique

`StoredMessage` gagne `content: string | null`, `editedAt: string | null`, `deletedAt: string | null`.
Deux actions nouvelles sur le reducer :

```ts
| { type: 'message/edited'; conversationId: string; id: string; content: string; editedAt: string }
| { type: 'message/deleted'; conversationId: string; id: string; deletedAt: string }
```

Appliquées **par `id` serveur** — pas de passe `client_message_id`, elle n'a plus lieu d'être (§4.3).

**Un événement dont l'`id` est absent du thread est ignoré, silencieusement.** Le message n'a jamais
été chargé ; la page d'historique qui le contiendra le lira déjà à jour. Ne rien faire est le
comportement correct, pas un oubli — un commentaire le dit dans le code.

Les deux actions posent un **état complet**, jamais un delta : les rejouer dans n'importe quel ordre,
une ou deux fois, donne le même résultat. C'est ce qui rend l'écho SSE arrivant avant la réponse HTTP
inoffensif, sans coordination.

### 8.2 L'interface

| Élément | Comportement |
|---|---|
| Menu au survol | sur ses propres messages vivants uniquement : « Modifier », « Supprimer » |
| Éditeur | en ligne dans la bulle ; `Échap` annule, `Entrée` valide |
| Message édité | mention « (modifié) » discrète à côté de l'heure |
| Tombstone | « Ce message a été supprimé », italique grisé, sans menu ni action |
| Confirmation | une confirmation avant suppression — l'action est irréversible et sans annulation |

L'entrée « Modifier » disparaît passé 15 minutes, calculées côté client. **C'est du confort, pas de la
sécurité** : le serveur reste l'autorité et répondra 403 à un appel forgé. Un commentaire le dit dans
le composant, parce que c'est exactement le genre de garde qu'un relecteur pourrait prendre pour la
protection réelle.

### 8.3 Le volet fuseaux horaires

Le backend est déjà conforme et n'est pas touché : `TIMESTAMPTZ` partout, `TZ=UTC` et
`date.timezone = UTC` dans le `Dockerfile` du backend, transport en ISO 8601. **Le fuseau est un
problème de présentation** — tout le travail de la tranche est donc côté client.

Un module `frontend/src/ui/dates.ts`, pur, sans React :

| Fonction | Rôle |
|---|---|
| `dayKey(iso, timeZone)` | clé de jour (`2026-07-26`) **dans le fuseau du viewer** |
| `formatDaySeparator(iso, timeZone, locale)` | « Aujourd'hui », « Hier », « 25 juillet » |
| `formatRelative(iso, now, locale)` | « il y a 5 min », via `Intl.RelativeTimeFormat` |

**Le fuseau et la locale sont des paramètres, jamais des globales lues à l'intérieur.** C'est ce qui
rend le module testable sans bidouiller `process.env.TZ`, et ce qui permet de vérifier Tokyo et New
York dans le même fichier de test.

Résolus une seule fois, à la frontière React : `Intl.DateTimeFormat().resolvedOptions().timeZone`
(nom IANA, gère le DST tout seul) et `navigator.language`. Cela remplace le `'fr-FR'` codé en dur
actuellement dans `labels.ts`.

`MessageList` insère un séparateur collant quand `dayKey` change entre deux messages consécutifs. Un
même message peut être « Aujourd'hui » pour Tokyo et « Hier » pour New York : **c'est correct et
attendu**, et c'est précisément ce que le test vérifie.

### 8.4 Le fuseau n'est pas persisté

Une seule décision, mais elle ferme une question que la note du vault laisse ouverte. La note est
explicite : **seul le scheduling** (messages programmés, quiet hours) oblige le serveur à connaître le
fuseau IANA de l'utilisateur. Rien de tel n'existe dans les cinq tranches. Pas de colonne
`users.time_zone`, pas de sélecteur dans l'interface : ce serait une donnée que personne ne lit côté
serveur, et le navigateur connaît déjà la sienne.

---

## Section 9 — Tests

### 9.1 Unitaires — l'agrégat, sans aucune I/O

- **Fenêtre d'édition**, horloge gelée : 14 min 59 passe, 15 min 01 lève `EditWindowExpiredException`.
- **Non-auteur** : édition et suppression lèvent `NotTheAuthorException`.
- **Édition d'un tombstone** : `MessageAlreadyDeletedException`.
- **Contenu identique** : aucun événement enregistré, `editedAt` inchangé.
- **Suppression rejouée** : aucun événement enregistré, `deletedAt` inchangé (le premier instant est
  conservé, pas écrasé).
- **`reconstitute()`** d'un message édité et d'un tombstone : toujours aucun événement.

### 9.2 Unitaires — le front

Reducer : application par `id`, `id` inconnu ignoré, écho SSE avant la réponse du `PATCH` → même état
final dans les deux ordres d'arrivée.

`dates.ts` : un instant unique rendu « Aujourd'hui » à Tokyo et « Hier » à New York ; séparateur inséré
au bon endroit dans une liste chevauchant deux jours.

### 9.3 Fonctionnels

Les trois axes de la tranche 1 sur chaque route — nominal, non authentifié (401), non-membre (404) —
et chaque erreur vérifiée comme un Problem Details valide : en-tête `application/problem+json`,
membres obligatoires, `type` conforme au catalogue.

Plus les scénarios qui portent la valeur de la tranche :

| Scénario | Ce qu'il prouve |
|---|---|
| après `DELETE`, `SELECT content FROM messages WHERE id = …` renvoie **`NULL`** | **« payload hard »** — le test le plus important de la tranche |
| `DELETE` deux fois | 204 puis 204, **une seule** publication Mercure |
| éditer le **dernier** message | `last_message_preview` rafraîchi |
| éditer l'**avant-dernier** message | `last_message_preview` **inchangé** |
| supprimer le dernier message | `last_message_preview` passe à `NULL` |
| éditer le message d'une **autre** conversation | 404 indistinguable d'un identifiant inconnu |
| éditer le message d'un autre membre | 403 `/problems/not-the-author` |
| tombstone et pagination | 120 messages dont un supprimé au milieu : ni trou ni doublon |

`EventPublisher` reste un espion en mémoire : on assert le topic **et** la charge utile — notamment
que `message.deleted` ne transporte aucun contenu. Aucun hub Mercure n'est nécessaire en CI.

---

## Section 10 — Découpage en stories

Une branche par story, chacune laissant `make test`, `make static-code-analysis`, `make check-cs` et
`make deptrac` verts.

| # | Story | Contenu |
|---|---|---|
| 1 | **Migration et contrat nullable** | colonnes, `CHECK`, `MessageView` en `?string`, lectures SQL et mapper, front adapté au type. **Aucun comportement nouveau** |
| 2 | **Supprimer pour tous** | `Message::deleteForEveryone()`, commande, route `DELETE`, `MessageWasDeleted`, publication `message.deleted` |
| 3 | **Aperçu rafraîchi à la suppression** | `refreshPreview()`, listener côté `Conversation`, chorégraphie |
| 4 | **Éditer un message** | `Message::edit()` et fenêtre de 15 min, commande, route `PATCH`, `MessageWasEdited`, publication et rafraîchissement de l'aperçu |
| 5 | **Front — suppression** | action reducer, rendu du tombstone, menu au survol, confirmation |
| 6 | **Front — édition** | action reducer, éditeur en ligne, mention « (modifié) », masquage passé 15 min |
| 7 | **Front — dates et fuseaux** | `dates.ts`, séparateurs de jour, temps relatif, `Intl` en remplacement du `'fr-FR'` codé en dur |

La story 1 est délibérément inerte : elle porte à elle seule le changement cassant du contrat, ce qui
rend les six suivantes additives et relisibles.

---

## Section 11 — Hors périmètre, explicitement

« Supprimer pour moi » et la table `message_hidden` · retrait par un modérateur et modération en
général (T5) · historique des versions d'un message · hard delete RGPD et crypto-shredding · messages
éphémères et purge par TTL · préférence de fuseau persistée côté serveur · reprise `Last-Event-ID` sur
`message.edited` et `message.deleted` (§4.2) · annulation d'une suppression · notification aux
destinataires qu'un message a été édité hors de leur vue courante · distinction, dans l'aperçu de la
liste de conversations, entre « aucun message » et « dernier message supprimé » (§6.4).

---

## Critères d'acceptation de la tranche 3

1. Alice envoie un message, Bob le voit. Alice le supprime : Bob voit le tombstone **sans rafraîchir**,
   et `SELECT content` renvoie `NULL` en base.
2. Alice corrige une faute dans les 15 minutes : Bob voit le texte corrigé et la mention « (modifié) »
   **sans rafraîchir**.
3. Passé 15 minutes, l'action « Modifier » a disparu de l'interface, et un appel forgé au `PATCH`
   répond 403 `/problems/edit-window-expired`.
4. Bob ne peut ni éditer ni supprimer le message d'Alice : 403 `/problems/not-the-author`.
5. Supprimer le dernier message d'une conversation vide son aperçu dans la liste de gauche ; supprimer
   l'avant-dernier ne le touche pas.
6. Rejouer le `DELETE` répond 204 et ne produit aucune seconde publication.
7. Un client réglé sur `Asia/Tokyo` et un client réglé sur `America/New_York` affichent le même message
   sous deux séparateurs de jour différents.
8. Les quatre portes de qualité sont vertes.

## Questions restées ouvertes

Aucune.
