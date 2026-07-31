# ADR 0001 — Communication inter-contextes : contrats publiés et chorégraphie

## Status
Accepted — 2026-07-25

## Context

Le backend est découpé en contextes bornés (`Identity`, `Conversation`, `Message`, `Realtime`,
`Shared`), chacun en couches `Domain` / `Application` / `Infrastructure`. La règle de dépendance
technique est vérifiée par deptrac.

Trois besoins inter-contextes sont apparus dès la tranche 1, et la première rédaction du plan les
avait résolus par un accès direct à la base de l'autre contexte :

| Besoin | Résolution initiale | Problème |
|---|---|---|
| `Realtime` doit connaître les conversations d'un utilisateur pour construire ses topics | `SELECT` sur `conversation_members` | table possédée par `Conversation` |
| `Conversation` affiche un aperçu du dernier message | `LEFT JOIN messages` | table possédée par `Message` |
| `Message` maintient le pointeur `conversations.last_message_*` | `UPDATE conversations` | table possédée par `Conversation` |

**Ces trois couplages sont invisibles pour deptrac** : ce ne sont pas des dépendances de code mais
des dépendances de schéma. Rien ne signalerait leur rupture lors d'un changement de structure.

Une première correction avait consisté à remonter des contrats dans `Shared/Domain/Contract/`. Elle
gonflait le noyau partagé et diluait la propriété de la surface publiée.

## Decision

Appliquer *Published Language / Open Host Service* : **un contexte ne dépend jamais que du contrat
publié d'un autre, jamais de ses internes** — ni de son code, ni de ses tables.

### Lectures — contrat publié, possédé par le producteur

Le producteur expose `App\{Contexte}\Application\Contract\` contenant :

- une interface (`{Chose}FinderInterface`, `{Chose}CheckerInterface`, …) ;
- un DTO de lecture stable (`{Chose}View`) — **jamais l'agrégat**.

Il l'implémente dans `App\{Contexte}\Infrastructure\Contract\`, et possède seul l'accès à ses tables.

Le consommateur garde **son propre port de domaine** (`Domain\Port\`), qui exprime son besoin dans sa
propre langue ; son adaptateur d'infrastructure délègue au contrat du producteur.

**Amendé le 2026-07-29 — ce port n'est dû que s'il traduit réellement quelque chose.** Un port
gagne sa place quand sa signature diffère de celle du contrat : vocabulaire propre au
consommateur, besoin plus étroit que la surface offerte, type de retour retraduit. Quand
l'adaptateur se contente de réexpédier l'appel avec la **même signature**, il n'y a pas de
traduction — seulement une interface recopiée. Dans ce cas, **le consommateur nomme le contrat
publié directement**, quelle que soit sa couche.

C'est déjà l'usage majoritaire du dépôt : les cinq consommateurs de
`ConversationMembershipInterface`, tous en `Message\Application\`, le nomment sans port. La
formulation initiale ci-dessus était donc plus stricte que la pratique ; cet amendement aligne
la règle sur elle plutôt que l'inverse.

Ce que le port ne doit **jamais** servir à contourner : la dépendance elle-même. Nommer
directement un contrat publié reste un couplage inter-contextes, et il passe par la même
allowlist de `deptrac-contexts.yaml`. L'amendement supprime une indirection, pas une friction.

Tranche 1 — un seul contrat : `Conversation` publie `MemberConversationsFinderInterface`, consommé
par `Realtime` pour construire les topics d'abonnement.

### Écritures — chorégraphie par événements

**Un contexte ne pilote jamais les use cases d'un autre.** Le producteur publie un fait ; le contexte
intéressé réagit avec sa propre commande.

Tranche 1 : `Message` publie `MessageWasSent`. `Conversation` l'écoute et met à jour **son** pointeur
`last_message_id` / `last_message_at` / `last_message_preview` par sa propre commande.

Conséquence directe : `Message` n'écrit plus dans `conversations`, et le stockage de l'aperçu supprime
le `LEFT JOIN` vers `messages`. **Deux des trois couplages disparaissent au lieu d'être médiés.**

### Les événements inter-contextes vivent dans le noyau partagé

Déclarés dans `App\Shared\Domain\Event\` comme DTO `readonly` purs. Ils sont l'autre moitié de la
langue publiée : ni le producteur ni le consommateur n'importe le namespace de l'autre, les deux ne
dépendent que de `Shared`, que toutes les couches voient déjà — donc **aucune règle deptrac
supplémentaire**.

Un événement nomme un **fait passé** du producteur. Il transporte des identifiants et des primitives
stables, **jamais un agrégat ni un VO local**. `MessageWasSent` transporte donc le contenu en `string`
et non en `MessageContent` : mettre un VO de `Message` dans `Shared` ferait dépendre `Shared` de
`Message`, une inversion pire que le problème d'origine.

Un événement qu'un seul contexte écoute reste chez lui ; il ne remonte que le jour où un second s'y
abonne.

### Application — deptrac par contexte, en second fichier

`deptrac.yaml` porte la dimension **technique** (`Domain` / `Application` / `Infrastructure`).
`deptrac-contexts.yaml` porte la dimension **contexte** : une couche par contexte, plus une couche
`*Contract` par surface publiée. Les dépendances inter-contextes ne sont autorisées que **vers** les
couches `*Contract`, via une allowlist explicite reflétant la consommation réelle. Les classes de
contrat ne dépendent de rien.

Deux fichiers parce que deptrac n'accepte qu'un `ruleset` par fichier : mélanger les deux dimensions
imposerait le produit cartésien couche × contexte — une quinzaine de couches en tranche 1, davantage
ensuite — pour un résultat illisible.

## Alternatives considered

- **Accès direct aux tables voisines.** Simple et performant, mais invisible pour l'outillage : un
  changement de schéma casse un contexte voisin sans qu'aucun test ni analyse ne le signale. Rejeté.
- **Contrats dans `App\Shared\Domain\Contract\`.** Histoire deptrac plus simple — tout le monde voit
  déjà `Shared`, aucune allowlist. Rejeté : gonfle le noyau partagé et dilue la propriété. Le
  producteur possède sa surface publiée. *(La règle « tout ce qui est inter-contexte va dans Shared »
  reste vraie pour les **événements**, dont la nature est précisément d'être un contrat symétrique
  que personne ne possède.)*
- **Contrat d'écriture synchrone pour le pointeur** (`Message` appelle un
  `LastMessageRecorderInterface` publié par `Conversation`, dans la même transaction). Garantit la
  cohérence forte, ce que la spec T1 avait initialement retenu. Rejeté : un contexte pilote alors
  l'écriture d'un autre, et le gain est nul ici — voir ci-dessous.
- **Bus de messages générique pour les lectures inter-contextes.** Générique et déjà en place, mais
  non contraignable (n'importe quel contexte peut demander n'importe quoi), non typé à la frontière,
  et il n'échoue qu'à l'exécution si aucun handler n'est enregistré. Rejeté pour les contrats de
  lecture ; le bus reste intra-contexte.

## Consequences

- **Le pointeur `last_message_*` devient éventuellement cohérent.** Ceci **supersede** la décision
  « même transaction » de la spec T1 (section 3.6). La fenêtre est microscopique — événement publié
  après commit, même processus, même requête HTTP — et le pointeur est **auto-réparateur** : le
  message suivant le corrige. Le mode d'échec est un aperçu périmé dans la liste des conversations,
  jamais un message perdu. L'échec de la seconde transaction est loggué en `error`.
- **Une colonne `conversations.last_message_preview`** est ajoutée : elle porte l'aperçu écrit par le
  listener, ce qui supprime la jointure vers `messages`.
- Les agrégats ne franchissent plus les frontières. Un producteur peut refactorer ses internes
  librement tant que son namespace `Contract` reste stable.
- **Modifier un `*View` ou la charge utile d'un événement de `Shared\Domain\Event\` est un changement
  cassant pour les consommateurs**, à traiter comme une modification d'API publique.
- Ajouter un consommateur d'un contrat existant, ou publier un nouveau contrat, impose de mettre à
  jour l'allowlist de `deptrac-contexts.yaml`. Cette friction est **intentionnelle** : elle rend tout
  nouveau couplage explicite et relisible.
- `make qa` exécute les deux analyses deptrac.
