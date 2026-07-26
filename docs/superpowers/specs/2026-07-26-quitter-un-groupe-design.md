# Quitter une conversation de groupe

**Date** : 2026-07-26 · **Branche** : `feat/quitter-un-groupe`

## Le besoin

Bob est simple membre du groupe « Equipe projet », administré par Alice. Il veut en partir.
Après son départ :

- la conversation disparaît de sa barre latérale ;
- il ne reçoit plus les messages entrants de ce fil ;
- il ne figure plus dans la liste des membres.

## Ce qui existe déjà

L'essentiel de la plomberie est en place depuis les tranches 1 à 3 :

| Élément | État |
|---|---|
| `Conversation::removeMember()` | existe — vérifie le type groupe, idempotent, enregistre `MembershipChanged(Left)` |
| `DELETE /api/conversations/{id}/members/{userId}` | existe — **exige `MANAGE_MEMBERS`**, donc fermé à Bob |
| `PublishMembershipChangedListener` | publie `membership.changed` sur le topic système du membre concerné |
| Front, sur `membership.changed` | appelle déjà `resubscribe()` et rafraîchit la liste des conversations |

Le départ produit donc déjà, sans une ligne de plus : un nouveau JWT qui ne couvre plus le
topic de la conversation, et une liste latérale d'où elle a disparu — `ListMyConversations`
ne renvoyant que les conversations dont on est membre.

Manquent le droit de se retirer soi-même, l'invariant de bord, et le bouton.

## Décisions

### Qui peut partir — seuls les non-admins

Tout membre d'un **groupe** dont le rôle est `member` peut se retirer. Un admin reçoit un
**409** : il doit d'abord transférer ses droits. L'endpoint de promotion n'existe pas encore,
et cette story ne le crée pas.

*Écartées* : laisser partir n'importe qui, y compris le dernier admin (laisse un groupe
orphelin où plus personne ne peut ajouter de membre — un cul-de-sac silencieux) ; transmettre
automatiquement l'administration au plus ancien membre restant (une règle implicite de plus,
à retenir et à tester, pour un cas qui n'est pas celui de la story).

### 409 et non 403 pour l'admin

Le rôle ne *manque* pas : il est trop élevé. Le client ne peut rien corriger dans sa requête,
il doit changer l'état de la ressource. C'est la définition de `ConflictExceptionInterface` —
« l'appelant a le droit d'agir, mais l'état rend l'opération impossible ». Le 403 reste
réservé au cas où l'appartenance est établie et où seul le rôle manque.

### Route dédiée

`DELETE /api/conversations/{conversationId}/members/me`, distincte de la route d'exclusion.

*Écartées* : assouplir la route existante quand `{userId}` est celui de la session — le même
endpoint renverrait 403 ou 204 selon une comparaison d'identifiants ; `POST /leave` — rompt
avec le style ressource du reste de l'API.

« Partir » et « exclure » ont des règles d'autorisation opposées (un admin **peut** exclure et
**ne peut pas** partir), des statuts d'erreur différents, et évolueront séparément. Le segment
`me` évite en outre au front de connaître son propre ULID pour construire l'URL.

### Les membres restants ne sont pas notifiés en temps réel

`MembershipChanged` continue de n'être publié que sur le topic système du partant. Alice ne
verra Bob disparaître qu'en rouvrant le panneau des membres.

Le partant, lui, **doit** être notifié — sans quoi son `EventSource` resterait abonné à un
topic qu'il n'a plus le droit d'écouter. Les autres n'ont aucune décision à prendre : leur vue
reste correcte, seulement un peu périmée, sur un panneau le plus souvent fermé.

*Écartées* : publier en plus sur le topic de la conversation (double le fan-out d'un départ
pour rafraîchir un panneau fermé neuf fois sur dix) ; un message système « Bob a quitté »
(nouveau type de message, donc une story à part entière).

**Coût assumé** : si Alice a le panneau ouvert au moment du départ, elle voit une liste fausse
jusqu'à réouverture.

## Conception

### Domaine — `Conversation/Domain/`

```php
public function leave(UserId $userId): void
{
    $this->assertIsGroup();

    if (!$this->hasMember($userId)) {
        return;                       // idempotent, aucun evenement
    }

    if ($this->isAdmin($userId)) {
        throw AdminCannotLeaveException::forUser($userId);
    }

    $this->removeMember($userId);     // enregistre MembershipChanged(Left)
}
```

`leave()` est distincte de `removeMember()` et ne la remplace pas : deux règles différentes
sur la même mutation. `removeMember()` reste le geste de l'admin, sans contrainte de rôle sur
sa cible.

Nouvelle exception `AdminCannotLeaveException` implémentant `ConflictExceptionInterface`,
slug `admin-cannot-leave`, sur le modèle de `MessageAlreadyDeletedException`.

### Application — `Conversation/Application/Command/`

`LeaveConversationCommand(ConversationId $conversationId, UserId $userId)` et son handler :
charge l'agrégat, appelle `leave()`, sauvegarde, loggue en `notice`. Retour `void`.

### HTTP — `Conversation/Infrastructure/Http/LeaveConversationController`

`DELETE /api/conversations/{conversationId}/members/me`, nom de route
`conversation_members_leave`, réponse **204**.

Le contrôleur pose d'abord `GetConversationQuery` — comme `RemoveMemberController` — pour que
**le non-membre reçoive 404** plutôt qu'un 204 trompeur. Conséquence voulue : un second
`DELETE` après le départ renvoie 404. Aucun appel au voter : tout membre a le droit de
*tenter*, c'est le domaine qui tranche sur le rôle.

### Collision de route

`/members/me` et `/members/{userId}` peuvent matcher la même URL, et l'ordre dépend de l'ordre
de chargement des fichiers. On le rend explicite en contraignant `{userId}` à un ULID sur la
route d'exclusion. `AbstractUlidIdentifier::PATTERN` étant une regex délimitée, on en extrait
le motif nu :

```php
/** Motif nu, sans delimiteurs : utilisable en `requirements` de route. */
public const string ROUTE_PATTERN = '[0-7][0-9A-HJKMNP-TV-Z]{25}';
public const string PATTERN = '/^' . self::ROUTE_PATTERN . '$/';
```

Une seule source pour le format, conformément à la règle « une contrainte ne redéfinit jamais
un format défini ailleurs ». La concaténation dans une expression constante est le seul moyen
disponible : PHP n'autorise pas `sprintf()` dans une constante de classe.

### Front

**`api/client.ts`** — une entrée alignée sur `deleteMessage` :

```ts
leaveConversation: (conversationId: string) =>
  request<void>(`/api/conversations/${conversationId}/members/me`, { method: 'DELETE' }),
```

**`hooks/useAppState.ts`** — expose `leaveConversation(conversationId): Promise<void>`.
L'appel ne peut pas rester dans `MembersPanel` comme l'ajout de membres : partir a des
conséquences globales — la conversation ouverte disparaît, la sélection devient invalide — et
`useAppState` est le seul à pouvoir les porter. Sur le 204 : désélectionner, puis
`refreshConversations()`.

Pas d'attente de l'écho SSE, même choix que la suppression d'un message (`b1e747d`). L'écho
`membership.changed` qui suit déclenche le `resubscribe()` déjà en place et reste sans effet
supplémentaire : la liste rechargée ne contient déjà plus la conversation.

**`ui/ConversationView.tsx`** — nouvelle prop `onLeave: () => Promise<void>`, transmise à
`MembersPanel` avec `meId`, déjà reçu.

**`ui/MembersPanel.tsx`** — bouton rouge en pied, séparé de la liste :

```
┌ Membres ──────────────── × ┐
│ Alice                admin │
│ Bob                 member │
│ ─────────────────────────── │
│ Ajouter  [ ] Carol         │
│ ─────────────────────────── │
│   Quitter le groupe         │   ← absent si je suis admin
└────────────────────────────┘
```

Rendu seulement si mon rôle dans `members` vaut `member` — donc jamais pour un admin, ni tant
que la liste n'est pas chargée. Le 409 reste la vraie garantie ; masquer le bouton évite de
proposer une action interdite, ce que le voter journalise en `warning` comme un bug
d'interface.

Au clic : `window.confirm('Quitter « … » ? Vous ne verrez plus cette conversation.')`, puis
l'appel. Une confirmation est justifiée ici — contrairement à la suppression d'un message, le
départ est irréversible sans l'intervention d'un admin. En cas d'échec, le message s'affiche
dans le `<p role="alert">` déjà présent, pas dans une `window.alert`.

Le panneau n'a pas à se refermer : la conversation quitte la liste, `selected` devient `null`,
tout le sous-arbre est démonté.

`ConversationMember.role` reste typé `string` : on compare à `'member'`. Le resserrer en union
littérale toucherait des appelants hors de cette story.

## Tests

**Unitaires du domaine** (`tests/Unit/Conversation/Domain/ConversationMembershipTest.php`) :

- un simple membre quitte un groupe → il n'est plus membre, un seul `MembershipChanged(Left)` ;
- un admin quitte → `AdminCannotLeaveException`, composition inchangée, aucun événement ;
- un non-membre quitte → aucun effet, aucun événement ;
- quitter un direct → `NotAGroupException`.

**Fonctionnel** (`tests/Functional/Conversation/`), le scénario de l'énoncé — Alice crée
« Equipe projet » avec Bob :

- Bob `DELETE .../members/me` → 204 ;
- sa liste de conversations ne la contient plus ;
- `GET` du détail par Bob → 404 ;
- Alice voit un membre de moins ;
- un second `DELETE` par Bob → 404 ;
- Alice, admin → 409, slug `admin-cannot-leave` ;
- le jeton `GET /api/realtime/token` de Bob ne contient plus le topic de la conversation.

Cette dernière assertion couvre le « je ne suis plus notifié » sans tester Mercure : le jeton
ne liste que les conversations dont on est membre.

**Front** — un test de `MembersPanel` : bouton absent pour un admin, présent pour un membre,
`onLeave` non appelé si la confirmation est refusée (`window.confirm` stubbé).

## Effets de bord assumés

- Le départ supprime la ligne `conversation_members`, **et avec elle les watermarks de lecture
  de la tranche 2**. Réintégré plus tard, l'ancien membre repart de zéro et retrouve tout
  l'historique en non lu.
- **Les messages du partant restent** dans le fil, visibles des autres : `messages` ne
  référence pas `conversation_members`.
- On ne revient pas seul. Il n'existe pas d'endpoint « rejoindre » ; un admin doit ré-ajouter
  la personne, ce que `AddMembers` fait déjà pour un ancien membre.

## Hors périmètre

Le transfert d'administration, le départ du dernier membre, un message système « Bob a quitté »,
la notification temps réel des membres restants.

**Conséquence à assumer** : avec cette règle, un groupe dont l'admin veut partir est bloqué, et
un groupe peut se vider de tous ses non-admins sans que rien ne le signale. Une story
« transférer l'administration » devient nécessaire assez vite pour que la fonctionnalité soit
complète.

## Portes de qualité

`make static-code-analysis` · `make check-cs` · `make deptrac` · `make test`
