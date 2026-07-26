# Instant Messaging — Tranche 2 : statuts & présence

> Source conceptuelle : [[Accusés de réception et présence]] du vault Obsidian `tech/InstantMessaging`.
> Ce document ne réexplique pas les concepts — il fige les **décisions d'implémentation** et renvoie
> aux notes.
>
> Prérequis : [tranche 1](2026-07-25-instant-messaging-tranche-1-design.md), livrée. Les décisions
> transverses ([ADR 0001](../../adr/0001-cross-context-communication.md)) s'appliquent sans changement.

## Contexte

Deuxième tranche des cinq annoncées par la spec T1. Périmètre fixé par son tableau de découpage :
**watermarks distribué/lu, typing, online (Redis TTL)**.

### La thèse de la tranche

Une seule idée structure tout le reste, et elle vient de la note du vault :

> **Accusés de réception = état durable.** Persistés, sous forme de watermarks en base.
> **Présence et « en train d'écrire » = état éphémère.** Jamais dans la base principale ; TTL en Redis.
>
> Deux cycles de vie opposés → deux mécanismes de stockage opposés.

Cette opposition doit être **visible dans le code**, pas seulement écrite ici. Le test : la migration
de la tranche ajoute deux colonnes pour les accusés et **ne mentionne ni la présence ni le typing**.
Si un jour une colonne `is_online` apparaît, la thèse est morte.

### Ce que la tranche ajoute, en une phrase par élément

| Élément | Nature | Stockage |
|---|---|---|
| Watermark « distribué » | durable | colonne sur `conversation_members` |
| Watermark « lu » | durable | colonne sur `conversation_members` |
| Compteur de non-lus | dérivé, jamais stocké | calculé depuis le watermark et `messages` |
| Présence en ligne | éphémère | clé Redis avec TTL |
| « En train d'écrire » | éphémère, non stocké **du tout** | événement Mercure, expiré par le client |

### Décisions prises pendant le design

| Question | Décision | Alternative écartée |
|---|---|---|
| Détection de la présence | Redis + TTL + heartbeat client | Mercure Subscriptions API (§ 4.5) |
| Topic des accusés | le topic conversation existant | `/users/{id}/receipts` (§ 3.3) |
| Déclencheur du « lu » | conversation ouverte **et** onglet visible | `IntersectionObserver` par message (§ 6.3) |
| Propriétaire des watermarks | colonnes sur `conversation_members`, contexte `Conversation` | table dédiée, contexte `Receipt` (§ 2.2) |
| Compteurs de non-lus | **dans le périmètre** | repoussés en T3 |
| « Dernière connexion » (`last_active_at`) | **hors périmètre** | — (§ 8) |

---

## Section 1 — Ce que la tranche ne casse pas

La spec T1 énumère des contrats temps réel « à ne pas casser ». Cette tranche les respecte tous, et
c'est une contrainte de conception, pas une observation après coup.

| Contrat T1 | État après T2 |
|---|---|
| Topics construits par `Topic::conversation()` / `Topic::userSystem()` | **inchangé** — aucun nouveau constructeur nommé |
| `/users/{id}/system` présent dans tous les JWT | **inchangé** |
| `GET /api/realtime/token` → `{hub_url, topics}` + cookie | **inchangé** |
| L'`id` de l'événement Mercure est l'ULID du message | inchangé pour `message.created` ; les deux nouveaux événements n'en portent pas (§ 3.4) |
| `message.created` porte `client_message_id` | **inchangé** — la charge utile n'est pas touchée |
| Un `publish` par message, fan-out par le hub | **étendu** : un `publish` par avancée de watermark, jamais un par destinataire |
| Publication après commit uniquement | **inchangé** — même middleware transactionnel |

> **Aucun nouveau topic, aucun changement de JWT.** C'est la conséquence directe de la décision § 3.3.
> La liste des topics d'un utilisateur reste « une entrée par conversation + le canal système », donc
> toute la mécanique de réémission de jeton de T1 continue de fonctionner sans y toucher.

---

## Section 2 — Modèle de données

### 2.1 La migration

Une seule migration, deux colonnes :

```sql
ALTER TABLE conversation_members
    ADD COLUMN last_delivered_message_id CHAR(26) DEFAULT NULL,
    ADD COLUMN last_read_message_id      CHAR(26) DEFAULT NULL;
```

Ces deux colonnes étaient **déjà annoncées** par la section 2 de la spec T1, sous « Hors périmètre T1
(ajout par migration en T2/T3) ». La tranche honore cette annonce sans rien y ajouter.

`NULL` signifie « n'a jamais rien reçu / rien lu ». Ce n'est pas un défaut à éviter : c'est l'état
initial légitime de tout membre à son arrivée, et la requête de comptage le traite explicitement
(§ 5.2).

**Aucun index.** Les deux accès sont couverts par la clé primaire `(conversation_id, user_id)` :
l'`UPDATE` vise une ligne unique, et l'agrégation « lu par 3/5 » balaie les membres d'une seule
conversation — quelques dizaines de lignes au maximum. Un index sur une colonne réécrite à chaque
message lu coûterait plus qu'il ne rapporte.

**Pas de contrainte de clé étrangère vers `messages`.** Un watermark est un curseur, pas une
référence : il doit survivre à la suppression du message qu'il désigne, que T3 va introduire. Une
`FOREIGN KEY` ici forcerait un `ON DELETE SET NULL` qui ferait **reculer** le watermark — exactement
l'invariant que la tranche s'emploie à garantir (§ 4.2).

### 2.2 Pourquoi sur `conversation_members`, et pas dans une table dédiée

**Énoncé de domaine.** « Ce membre a lu jusqu'à X » est un état de l'appartenance, au même titre que
son rôle ou sa date d'arrivée. Ce n'est pas une entité qui aurait sa propre identité et son propre
cycle de vie. Le modèle suit l'énoncé.

**Conséquence pratique** : l'agrégation « lu par 3/5 » est un `COUNT` sur la table qu'on lit déjà pour
afficher les membres, sans jointure ajoutée nulle part.

> **Alternative écartée : une table `read_receipts` avec son propre contexte `Receipt`.**
>
> L'argument était réel : séparer une table très chaude — une écriture par message lu — d'une table
> froide, l'appartenance ne changeant presque jamais. Et le contexte `Conversation` serait resté
> totalement inchangé par la tranche.
>
> Le coût le disqualifie. Un contexte hexagonal complet dont le `Domain` ne contiendrait aucun
> invariant réel, un contrat publié supplémentaire à maintenir vers `Conversation`, et une jointure
> de plus à chaque lecture — pour une contention d'écriture qui n'existe pas à cette échelle. On paie
> une structure de découplage sans avoir le problème qu'elle résout.
>
> Le compromis intermédiaire — table dédiée mais toujours possédée par `Conversation` — gardait la
> jointure et perdait l'énoncé de domaine clair, sans rien gagner de mesurable.

### 2.3 Ce qui n'est pas stocké

Ni la présence, ni le typing, ni le compteur de non-lus.

Le compteur mérite une justification, parce que le dénormaliser serait tentant : une colonne
`unread_count` par membre, incrémentée à chaque message. Refusé — ce serait un **second** pointeur
dénormalisé à maintenir cohérent, alors que le premier (`last_message_*` de T1) est déjà
éventuellement cohérent par chorégraphie. Deux dénormalisations qui doivent rester d'accord entre
elles est précisément la classe de bug qu'on ne veut pas introduire pour économiser un `COUNT` sur
un index existant.

---

## Section 3 — Temps réel

### 3.1 Les deux nouveaux événements

Tous deux publiés sur `/conversations/{id}`, le topic que T1 a déjà posé.

| Événement | Charge utile | Émis quand |
|---|---|---|
| `receipt.updated` | `{conversation_id, user_id, last_delivered_message_id, last_read_message_id}` | un watermark a **réellement** avancé |
| `typing.started` | `{conversation_id, user_id}` | `POST /typing`, throttlé côté client |

Les deux charges utiles ne contiennent que des identifiants. Aucun contenu de message n'y transite,
conformément à la règle de journalisation et de confidentialité de T1 — ce qui vaut pour les logs
vaut pour ce qui part sur le réseau.

`receipt.updated` transporte **les deux watermarks à chaque fois**, même si un seul a bougé. Le
destinataire remplace l'état du membre au lieu de le fusionner, ce qui rend le traitement idempotent
et supprime toute dépendance à l'ordre d'arrivée des événements.

### 3.2 Aucun `typing.stopped`

Le front auto-expire l'indicateur après 5 s, et un `message.created` du même auteur l'efface
immédiatement.

Un contre-événement doublerait le trafic pour une information que le destinataire peut déduire, et
introduirait un mode d'échec propre : un `typing.stopped` perdu laisse l'indicateur affiché
indéfiniment, alors qu'une expiration côté client est autoréparatrice par construction. Un signal
éphémère se conçoit avec une durée de vie, pas avec un signal d'arrêt.

### 3.3 Pourquoi le topic conversation, et pas `/users/{id}/receipts`

La spec T1 avait annoncé `/users/{id}/receipts` — « pattern standard, réutilisé tel quel en T2 » — et
la note du vault le décrit également. **Cette tranche revient sur cette annonce.**

Le motif est le principe que T1 a lui-même établi : *un `publish` par message, le hub fait le
fan-out, le métier reste en O(1)*.

Avec un topic par expéditeur, publier un accusé exige de connaître les expéditeurs distincts des
messages compris entre l'ancien et le nouveau watermark — donc une requête supplémentaire dans la
table `messages`, depuis le contexte `Conversation`, ce que l'ADR 0001 interdit — puis un `publish`
par expéditeur. Le métier repasserait en O(N) là où T1 avait obtenu O(1), et le JWT gagnerait un
topic de plus.

Avec le topic conversation : **un seul `publish`, aucune requête préalable, aucun topic ajouté**.
Chaque client tient une map `membre → watermarks` et calcule ses propres coches localement.

> **Ce qu'on accepte en échange.** Dans un groupe, chaque membre connaît le watermark de tous les
> autres, pas seulement celui de ses propres messages.
>
> Ce n'est pas une fuite : « lu par 3/5 » est précisément l'affichage qu'on veut produire, et il
> suppose de connaître qui a lu. La différence réelle avec le topic personnel est qu'un client
> pourrait savoir qui a lu un message dont il n'est pas l'auteur — une information que tous les
> membres du fil pourraient de toute façon obtenir en s'observant les uns les autres.
>
> Si une tranche ultérieure introduit un mode « accusés désactivés » par utilisateur, cette décision
> devra être réexaminée : il faudra alors filtrer par destinataire, et le topic personnel redeviendra
> le bon outil.

### 3.4 Pas d'`id` d'événement sur les deux nouveaux

T1 pose que l'`id` de l'événement Mercure est l'ULID du message, pour rendre `Last-Event-ID`
exploitable. Les deux nouveaux événements n'en portent pas.

`typing.started` n'a aucune valeur historique : le rejouer à la reconnexion afficherait un indicateur
pour une frappe terminée depuis longtemps. `receipt.updated` est autoréparateur — l'état complet est
rechargé au `GET /api/conversations/{id}`, et le watermark suivant corrige tout écart. Leur donner un
`id` les inscrirait dans un flux de rejeu où ils n'ont rien à faire.

### 3.5 Chorégraphie, ou publication directe

Les deux nouveaux événements ne suivent **pas** le même chemin, et la différence est la conséquence
directe de leur nature.

**`receipt.updated` passe par la chorégraphie**, comme `MessageWasSent` :

```
Conversation : la commande avance le watermark, l'agrégat enregistre ReceiptWatermarkAdvanced
            ↓ middleware transactionnel du command.bus — après commit
Realtime    : PublishReceiptUpdatedListener publie sur le topic conversation
```

`ReceiptWatermarkAdvanced` vit dans `Shared/Domain/Event/`, aux côtés de `MessageWasSent`, et ne
transporte que des identifiants de `Shared` et des scalaires. Publier avant le commit pousserait un
accusé qu'un rollback ferait disparaître — même raisonnement qu'en T1, même middleware, aucun
mécanisme nouveau.

**`typing.started` est publié directement** par son contrôleur, dans `Realtime`.

Il n'écrit rien : il n'a ni agrégat, ni transaction, ni domain event à enregistrer. Le faire transiter
par une commande vide dont le seul effet serait d'émettre un événement, à travers un middleware
transactionnel qui n'aurait aucune transaction à ouvrir, serait du cérémonial pur. Le contrôleur
vérifie l'appartenance puis appelle `EventPublisherInterface`.

C'est une asymétrie assumée, et elle est justifiable en une phrase : **la chorégraphie sert à ne pas
publier ce qui n'est pas commité ; sans écriture, elle n'a rien à protéger.**

---

## Section 4 — Présence

### 4.1 Le mécanisme

| Élément | Valeur |
|---|---|
| Clé Redis | `presence:{userId}` |
| Valeur | `1` — seule l'existence de la clé porte l'information |
| TTL | 30 s |
| Période du heartbeat client | 20 s |

La marge entre 20 et 30 s absorbe un aller-retour lent ou un heartbeat manqué sans faire clignoter
la pastille. Un rapport de 1 à 1,5 est le compromis courant : plus serré, la présence devient
instable ; plus large, une déconnexion met trop longtemps à se voir.

**Aucune persistance, aucun volume Docker.** Un état éphémère qui survivrait au redémarrage du
conteneur serait un mensonge — il affirmerait qu'un utilisateur est en ligne alors que plus personne
ne le sait. Redis démarre vide, et c'est correct : le premier heartbeat de chacun reconstruit l'état
en moins de 20 s.

### 4.2 Le heartbeat renvoie la présence

```
POST /api/presence/heartbeat  →  200  {"online_user_ids": ["01J...", "01J..."]}
```

Un seul aller-retour toutes les 20 s rafraîchit mon TTL **et** me dit qui est en ligne parmi les
utilisateurs avec qui je partage au moins une conversation.

Le contrôleur dispatche la commande — qui rend `void`, comme toute commande — puis pose la query.
C'est exactement la séparation CQS décrite par le CLAUDE.md : *pour connaître l'effet d'une écriture,
on pose ensuite une query*. Deux appels HTTP séparés doubleraient le trafic pour faire respecter une
séparation qui est déjà respectée à l'intérieur du contrôleur.

**L'ensemble des utilisateurs pertinents demande un nouveau contrat.** Le contrat existant,
`MemberConversationsFinderInterface`, ne rend que des `ConversationId` — il ne dit pas qui les
peuple, et `Realtime` n'a pas le droit d'aller le lire dans `conversation_members`. `Conversation`
publie donc un second contrat, à côté du premier :

```php
// Conversation/Application/Contract/ConversationPeersFinderInterface.php
interface ConversationPeersFinderInterface
{
    /** @return list<UserId> les utilisateurs partageant au moins une conversation avec celui-ci */
    public function peerIdsFor(UserId $userId): array;
}
```

Une **nouvelle interface** plutôt qu'une méthode ajoutée à `MemberConversationsFinderInterface` :
élargir un contrat publié déjà consommé est un changement cassant, et les deux questions sont
distinctes — « quels fils puis-je écouter » n'est pas « qui sont mes interlocuteurs ».

L'implémentation vit dans `Conversation/Infrastructure/Contract/` et tient en un `SELECT DISTINCT`
sur `conversation_members` joint à lui-même, en excluant l'utilisateur lui-même du résultat.

### 4.3 Pourquoi la présence se lit par sondage et ne se pousse pas

**L'expiration d'une clé Redis n'est pas un événement.** Personne ne peut publier « untel vient de
passer hors ligne » au moment où sa clé expire, sans ajouter un balayeur périodique ou activer les
keyspace notifications.

Ne pousser que la transition détectable — hors ligne → en ligne, qui correspond à un `SET` sur une
clé absente — produirait un statut qui monte et ne redescend jamais. C'est-à-dire exactement le
booléen `is_online` périmé que la note du vault désigne comme l'anti-pattern, obtenu par un autre
chemin.

Un sondage aligné sur le TTL est la version honnête du même besoin : l'information ne peut pas être
plus fraîche que le TTL de toute façon.

> **Ce qu'on ne fait pas, et qu'on saurait faire.** Le fan-out de présence est en O(contacts) :
> « Alice est en ligne » doit atteindre tous ses contacts, et les reconnexions massives après une
> coupure réseau produisent un *thundering herd*. C'est un des vrais casse-têtes du passage à
> l'échelle. À la taille de ce projet, un sondage de 20 s par client est négligeable, et le prétendre
> résolu par une architecture push serait de la complexité décorative.

### 4.4 Le port et son implémentation

```php
// Realtime/Domain/PresenceStoreInterface.php — zéro dépendance, comme EventPublisherInterface
interface PresenceStoreInterface
{
    public function touch(UserId $userId): void;

    /**
     * @param  list<UserId> $candidates
     * @return list<UserId> ceux qui sont en ligne
     */
    public function onlineAmong(array $candidates): array;
}
```

`onlineAmong()` prend les candidats en argument plutôt que de rendre « tous les utilisateurs en
ligne » : le port ne doit pas laisser fuiter la présence de gens avec qui je n'ai aucune conversation.
La restriction vit dans la signature, pas dans la discipline de l'appelant.

L'implémentation `Realtime/Infrastructure/Presence/RedisPresenceStore` utilise `SETEX` et un unique
`MGET` — jamais `KEYS`, qui balaie l'espace de clés entier.

### 4.5 Alternative écartée : la Subscriptions API de Mercure

Le hub sait déjà qui est abonné à quel topic, et publie les connexions et déconnexions sur
`/.well-known/mercure/subscriptions/{topic}`. Cela supprimait le conteneur Redis, le heartbeat, le
sondage, et donnait une détection de déconnexion **plus fiable** qu'un TTL puisqu'elle observe la
fermeture réelle de la connexion SSE.

Trois raisons de ne pas la retenir :

1. **Elle répond à une autre question.** « Abonné au topic » n'est pas « en ligne ». Un onglet
   endormi par le navigateur peut conserver sa connexion SSE ; un utilisateur qui vient de recharger
   la page est brièvement absent des deux côtés.
2. **Elle couple le métier aux internes du hub.** La présence deviendrait une propriété du transport,
   inobservable en test sans faire tourner un vrai hub.
3. **Redis resservira.** Le rate limiting de T5 en a besoin. Le conteneur n'est pas un coût dédié à
   la présence.

À quoi s'ajoute que la disponibilité exacte de cette API dans le hub FOSS demanderait une
vérification préalable — un pari de conception sur une fonctionnalité non vérifiée n'a pas sa place
dans le chemin critique d'une tranche.

---

## Section 5 — Accusés de réception

### 5.1 La monotonie, invariant central de la tranche

Un watermark ne recule **jamais**. C'est la seule propriété dont tout le reste dépend : les coches,
les compteurs, l'agrégation de groupe.

Elle est garantie par le `WHERE`, pas par la discipline de l'appelant :

```sql
UPDATE conversation_members
   SET last_read_message_id = :watermark
 WHERE conversation_id = :conversation_id
   AND user_id = :user_id
   AND (last_read_message_id IS NULL OR last_read_message_id < :watermark)
```

Le tri lexicographique des ULID *est* le tri chronologique — c'est la propriété qui a justifié leur
choix en T1, et elle sert ici directement, sans conversion ni jointure.

**Zéro ligne affectée signifie « déjà à jour »** : on ne publie alors rien. C'est ce qui empêche un
client bavard d'inonder le hub, et le cas nominal comme le rejeu passent par du contrôle de flux
ordinaire — pas par une exception rattrapée. Même mécanique que le
`ON CONFLICT … DO NOTHING RETURNING id` de T1, appliquée à un `UPDATE`.

Les deux watermarks s'avancent dans la même commande et la même transaction, chacun avec sa propre
garde. Un `delivered` qui avance sans que `read` bouge est le cas courant, pas une exception.

### 5.1 bis — Qui enregistre l'événement

T1 a posé un montage précis : **l'agrégat enregistre, le repository verse au collecteur** au moment
du `save()`, et le middleware transactionnel publie après commit
(`DbalMessageRepository`, `DbalConversationRepository`). Les watermarks ne s'y rangent pas
d'eux-mêmes, puisque l'avancée est décidée par un `WHERE` en base et non par un objet chargé.

Le montage retenu conserve le pattern sans le tordre :

1. Une entité `Membership` dans `Conversation/Domain` porte les deux watermarks et les méthodes
   `advanceDeliveredTo()` / `advanceReadTo()`. Elles comparent, et n'enregistrent
   `ReceiptWatermarkAdvanced` que si le curseur bouge. **La règle métier reste énoncée dans le
   domaine**, en PHP lisible et testable unitairement — c'est là qu'elle doit être.
2. `DbalMembershipRepository::save()` exécute l'`UPDATE` gardé de la § 5.1, puis **ne verse les
   événements au collecteur que si `rowCount() > 0`**.

Le point 2 est la seule inflexion par rapport à T1, où le repository verse inconditionnellement. Elle
est nécessaire : entre le `SELECT` et l'`UPDATE`, une requête concurrente du même utilisateur — deux
onglets, c'est le cas courant — peut avoir déjà poussé le curseur plus loin. L'entité aurait alors
enregistré un événement que l'`UPDATE` n'applique pas, et on publierait un accusé qui recule.

Le `WHERE` reste donc le garant de l'invariant, et la comparaison en PHP le garant de sa
**lisibilité**. Les deux disent la même chose ; celui qui tranche est celui qui touche la base.

### 5.2 Le compteur de non-lus traverse une frontière de contexte

Compter les non-lus, c'est `COUNT(messages WHERE id > mon watermark)`. Le watermark appartient à
`Conversation`, la table `messages` à `Message`. L'ADR 0001 interdit à l'un de lire la table de
l'autre — **y compris quand deptrac ne le voit pas**.

C'est exactement le cas d'usage pour lequel l'ADR a été écrit. `Message` publie donc un contrat :

```php
// Message/Application/Contract/UnreadCounterInterface.php
interface UnreadCounterInterface
{
    /**
     * @param  array<string, string|null> $watermarkByConversation  conversationId => last_read_message_id
     * @return array<string, int>                                   conversationId => nombre de non-lus
     */
    public function countUnread(UserId $reader, array $watermarkByConversation): array;
}
```

**Batché par conception.** L'écran d'accueil affiche N conversations ; un contrat qui répondrait pour
une seule produirait N requêtes. La signature rend la version lente impossible à écrire.

L'implémentation, dans `Message/Infrastructure/Contract/`, tient en une requête :

```sql
SELECT w.conversation_id, COUNT(m.id) AS unread
  FROM jsonb_to_recordset(:pairs::jsonb) AS w(conversation_id text, watermark text)
  LEFT JOIN messages m
         ON m.conversation_id = w.conversation_id
        AND m.id > COALESCE(w.watermark, '')
        AND m.sender_id <> :reader_id
 GROUP BY w.conversation_id
```

Trois points la rendent correcte :

- **`jsonb_to_recordset` transporte les paires `(conversation, watermark)` en un seul paramètre lié**,
  une chaîne JSON. C'est la réponse à un besoin que `IN (...)` ne couvre pas : ici chaque conversation
  a **son** watermark, donc il faut transmettre des paires, pas une liste.

  > `ArrayParameterType::STRING` ne convient pas ici, et la raison mérite d'être notée pour ne pas
  > être redécouverte : DBAL développe un tableau en placeholders séparés — `(?, ?, ?)` — ce qui sert
  > un `IN (...)` mais ne produit jamais un tableau PostgreSQL. Écrire `:ids::text[]` donnerait
  > `(?, ?, ?)::text[]`, du SQL invalide. Passer par du JSON garde **un** paramètre lié, sans
  > construire ni placeholder ni littéral de tableau à la main.
- **`COALESCE(w.watermark, '')`** traite le membre qui n'a jamais rien lu. La chaîne vide précède tout
  ULID en tri lexicographique, donc tous ses messages sont non lus — le comportement voulu, obtenu
  sans branche conditionnelle.
- **`m.sender_id <> :reader_id`** évite que mes propres messages me soient comptés comme non lus.
  Sans cette ligne, envoyer un message ferait apparaître un badge « 1 » sur ma propre conversation.

C'est un `LEFT JOIN` et non un `JOIN` : une conversation sans aucun message non lu doit rendre `0`,
pas disparaître du résultat.

### 5.3 Ce que le consommateur en fait

`Conversation` consomme ce contrat depuis son handler de query de la liste des conversations, par un
port déclaré dans son propre `Domain/Port/` et un adaptateur qui délègue — le montage décrit par
l'ADR 0001. `GET /api/conversations` gagne un champ `unread_count` par entrée ;
`GET /api/conversations/{id}` gagne les deux watermarks de chaque membre.

Aucune de ces deux modifications n'est cassante : ce sont des ajouts de champs.

---

## Section 6 — API HTTP

### 6.1 Les trois routes

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| `POST` | `/api/conversations/{id}/receipts` | `{delivered_up_to?, read_up_to?}` | `204` |
| `POST` | `/api/conversations/{id}/typing` | — | `204` |
| `POST` | `/api/presence/heartbeat` | — | `200 {online_user_ids: [...]}` |

Les deux premières rendent `204` : ce sont des écritures dont le résultat n'intéresse pas l'appelant,
qui apprendra l'effet par le flux temps réel comme tout le monde. Faire remonter le watermark résultant
dans la réponse créerait un second chemin d'information à garder cohérent avec le premier.

Les charges utiles sont validées par `#[MapRequestPayload]` sur des DTO de
`{Contexte}/Infrastructure/Http/Payload/`, avec des contraintes qui référencent
`AbstractUlidIdentifier::PATTERN` — jamais un format ULID réécrit sur place.

### 6.2 Erreurs : rien de nouveau au catalogue

| Cas | Statut | `type` |
|---|---|---|
| Non-membre, sur les trois routes | **404** | `/problems/resource-not-found` |
| `read_up_to` ou `delivered_up_to` mal formé | **422** | `/problems/validation-failed` |
| Non authentifié | **401** | `/problems/authentication-required` |

Le 404 pour un non-membre suit la règle de T1 sans exception : un 403 confirmerait l'existence de la
conversation.

**Un watermark qui désigne un message inexistant, ou d'une autre conversation, est accepté.** Le
`WHERE` de la § 5.1 ne compare que des ULID : il vérifie que le curseur avance, pas que sa cible
existe. Comportement à connaître plutôt qu'à corriger.

Ce n'est pas une faille. Un watermark n'est qu'une position dans un ordre, et seul son propriétaire
peut le pousser — un client qui envoie un ULID arbitrairement grand ne nuit qu'à lui-même, en
n'affichant plus ses propres non-lus. Vérifier l'appartenance du message au fil coûterait une requête
supplémentaire à chaque accusé, sur tous les clients, pour empêcher un utilisateur de se mentir à
lui-même.

La seule conséquence visible par autrui est la coche qui apparaîtra chez l'expéditeur. Un client
malveillant peut donc prétendre avoir lu — mais il le peut de toute façon, en ouvrant réellement la
conversation. La propriété n'est pas défendable côté serveur, et prétendre le contraire serait pire
que l'assumer.

Aucun nouveau `type` de problème n'est ajouté au catalogue de T1.

### 6.3 Le déclencheur du « lu », et pourquoi pas mieux

Le watermark « lu » avance quand la conversation est ouverte **et** que `document.visibilityState`
vaut `visible`. C'est le comportement de WhatsApp Web et de Slack.

> **Alternative écartée : un `IntersectionObserver` par message.**
>
> Plus honnête — le watermark n'avancerait qu'aux messages ayant réellement traversé le viewport,
> donc remonter loin dans l'historique sans redescendre ne marquerait pas les récents comme lus.
>
> Écartée pour deux raisons. Elle interagit avec la restauration de scroll de T1, déjà le point le
> plus délicat du front, et elle demande de raisonner sur l'ordre ULID des éléments observés au
> moment où ils entrent et sortent du viewport. Le gain est surtout théorique dans une messagerie où
> l'on est presque toujours en bas du fil.
>
> La condition de visibilité de l'onglet, elle, n'est **pas** négociable : sans elle, un onglet ouvert
> et oublié en arrière-plan marque tout comme lu pendant des heures. L'accusé devient alors un
> mensonge, c'est-à-dire exactement le défaut que la fonctionnalité est censée éviter.

---

## Section 7 — Frontend

La logique reste hors de React, comme en T1.

| Fichier | Rôle |
|---|---|
| `store/receiptsReducer.ts` | map `conversationId → (userId → {delivered, read})`, pur |
| `store/presenceReducer.ts` | ensemble des `userId` en ligne, **remplacé** à chaque heartbeat |
| `store/typingReducer.ts` | map `conversationId → (userId → expiresAt)`, pur |
| `hooks/useHeartbeat.ts` | `POST /presence/heartbeat` toutes les 20 s, suspendu quand l'onglet est caché |
| `hooks/useReadWatermark.ts` | conversation ouverte + onglet visible → debounce 500 ms → `POST /receipts` |
| `hooks/useTyping.ts` | au plus un `POST /typing` toutes les 3 s pendant la frappe |

`presenceReducer` **remplace** son état au lieu de le fusionner : le heartbeat rend la liste complète,
et fusionner ferait qu'un utilisateur passé hors ligne ne disparaîtrait jamais.

Les reducers reçoivent `now` en argument et ne lisent jamais l'horloge eux-mêmes — sans quoi
l'expiration du typing serait intestable.

### Trois points à commenter lourdement

Nicolas est novice côté front ; ces trois-là ne sont pas devinables et se paieraient en bugs
silencieux.

1. **L'ACK « distribué » ne vit pas dans la vue de conversation.** Il se déclenche à la réception SSE
   d'un `message.created`, y compris pour une conversation qu'on n'a pas ouverte. Il doit donc être
   branché au niveau du client temps réel global. Le placer dans `ConversationView` ne marquerait
   « distribué » que le fil affiché — faux par construction, et invisible en développement où l'on
   n'a généralement qu'un fil ouvert.

2. **Un indicateur qui expire tout seul n'entraîne aucun rendu.** Le store sait que le typing d'Alice
   expire dans 5 s, mais React ne le sait pas : sans réveil, l'indicateur reste affiché
   indéfiniment. Il faut un tick périodique **tant qu'au moins un typing est actif**, et seulement
   dans ce cas — un timer qui tourne en permanence réveillerait l'application entière toutes les
   secondes pour rien.

3. **Le watermark envoyé ne recule pas côté client non plus.** Le backend s'en protège déjà par son
   `WHERE`, mais rejouer un watermark déjà atteint génère une requête HTTP pour rien à chaque
   changement de focus. Le hook garde le dernier ULID envoyé.

### UI

Coches ✓ / ✓✓ sur mes propres messages, « lu par 3/5 » en groupe calculé depuis la map, pastille de
présence, ligne « Alice écrit… », badge de non-lus dans la liste des conversations.

---

## Section 8 — Hors périmètre

**« Dernière connexion » (`last_active_at`, « vu à 14:32 »).** Présent dans la note du vault, absent
du tableau de découpage de T1 — on suit T1. Le mécanisme demanderait une écriture en base à chaque
heartbeat, soit une écriture périodique par utilisateur connecté : c'est le seul élément de la note
qui ferait entrer un état de présence dans la base principale, et le faire entrer par une porte
dérobée affaiblirait la thèse de la tranche.

Également hors périmètre : accusés désactivables par utilisateur · notifications navigateur ·
compteur de non-lus global (badge d'application) · limitation des accusés dans les grands groupes ·
`Last-Event-ID` et rattrapage à la reconnexion (T2 ne change rien au format d'événement, la question
reste ouverte pour une tranche ultérieure).

---

## Section 9 — Découpage en commits

**Une seule branche pour la tranche : `feat/tranche-2-statuts-et-presence`.** Décision prise pour
cette tranche, qui déroge à la règle « une story = une branche » du CLAUDE.md. Chaque étape reste un
commit relisible laissant le dépôt vert.

| # | Commit | Contenu |
|---|---|---|
| 1 | `chore(infra)` | 6e conteneur `redis`, `ext-redis` au `Dockerfile`, healthcheck |
| 2 | `feat(presence)` | `PresenceStoreInterface`, `RedisPresenceStore`, `POST /presence/heartbeat` |
| 3 | `feat(front)` | hook heartbeat, `presenceReducer`, pastille |
| 4 | `feat(realtime)` | `POST /typing`, `typing.started`, hook throttlé, indicateur |
| 5 | `feat(conversation)` | migration, commande, monotonie, `POST /receipts` — **sans temps réel ni front** |
| 6 | `feat(realtime)` | `ReceiptWatermarkAdvanced`, listener, `receipt.updated`, coches |
| 7 | `feat(message)` | `UnreadCounterInterface`, requête `jsonb_to_recordset`, badge |
| 8 | `docs(readme)` | mise à jour du README (voir ci-dessous) |

### Le README à mettre à jour, section par section

Le commit final, et non des retouches disséminées : une documentation modifiée sept fois de suite se
contredit en cours de route.

| Section du README | Ce qui change |
|---|---|
| *What this project demonstrates* | Ajouter l'opposition état durable / état éphémère — c'est la thèse de la tranche, et c'est ce qui se défend le mieux en entretien |
| *Architecture* | Le 6e conteneur `redis` dans la topologie, et la mention qu'il ne porte **aucune** donnée durable |
| *Requirements* | `ext-redis` |
| *Everyday commands* | Rien a priori — à vérifier si une cible du `Makefile` est ajoutée |
| *Roadmap* | T2 passe de « à venir » à « livrée » ; T3 devient la suivante |
| *Documentation* | Lien vers la spec T2 |

**L'étape 5 précède la 6 délibérément.** Les watermarks doivent être corrects et testés avant qu'on
les diffuse. Une étape qui poserait la colonne et la publication d'un coup mélangerait deux natures
de bug — un `WHERE` faux et un topic faux — dans une seule revue.

### Prérequis à ta charge

`"ext-redis": "*"` à ajouter aux `require` de `backend/composer.json`. Composer n'installe pas les
extensions, il les **vérifie** : la ligne transforme le besoin en exigence de plateforme, et
`composer install` échouera bruyamment si le `Dockerfile` ne la fournit pas — au lieu d'un fatal
error à la première requête de heartbeat.

---

## Critères d'acceptation

- [ ] Deux navigateurs connectés : un message envoyé par l'un passe ✓ puis ✓✓ chez l'autre sans
      rechargement.
- [ ] La conversation ouverte dans un onglet **caché** ne marque rien comme lu ; revenir sur l'onglet
      avance le watermark.
- [ ] Dans un groupe de trois, le compteur « lu par N » progresse à mesure que chacun ouvre le fil.
- [ ] Un client qui rejoue le même watermark ne provoque **aucune** publication sur le hub.
- [ ] Fermer un onglet fait disparaître la pastille de présence en moins de 30 s.
- [ ] « Alice écrit… » disparaît seul après 5 s sans nouvelle frappe, et immédiatement à l'envoi.
- [ ] Le badge de non-lus ne compte jamais mes propres messages.
- [ ] La migration ne contient **aucune** colonne de présence ni de typing.
- [ ] `make static-code-analysis`, `make check-cs`, `make deptrac`, `make test` et `make front-test`
      verts à chaque commit.
