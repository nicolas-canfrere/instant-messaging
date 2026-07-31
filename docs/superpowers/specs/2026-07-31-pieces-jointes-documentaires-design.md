# Pièces jointes documentaires — txt, csv, md, pdf

> Prolonge la [tranche 4](2026-07-26-instant-messaging-tranche-4-design.md), qui a livré les pièces
> jointes **images**. Cette spec ne réexplique ni la pré-signature, ni le traitement asynchrone, ni
> la chorégraphie `Media → Message → Realtime` : elle suppose la T4 lue.
>
> Décisions transverses : [ADR 0001](../../adr/0001-cross-context-communication.md). Elles priment
> sur cette spec en cas de divergence.

## Contexte

La tranche 4 est livrée. La tranche 5 (recherche et modération) était engagée : **elle est
suspendue** le temps de livrer celle-ci, décision prise explicitement plutôt que subie.

La T4 avait rangé « vidéo, audio et documents » et « téléchargement de l'original sous son nom de
fichier d'origine » dans son §12 *Hors périmètre*. On y revient pour les documents seuls.

### Pourquoi ce n'est pas une extension d'enum

L'intuition — « ajouter quatre cas à `MediaMimeType` » — est fausse, et c'est ce qui rend la tranche
intéressante. La T4 est bâtie tout entière sur une prémisse implicite : **un média est une image**.
Cinq endroits la portent :

| Endroit | Ce qu'il suppose |
|---|---|
| `ImageInspectorInterface::inspect()` | tout média a une largeur et une hauteur |
| `MediaObject::markReady()` | un média prêt a des dimensions **et** une miniature |
| `media_ready_is_measured` (SQL) | idem, en base |
| `StorageKey::PATTERN` | l'extension est `jpg|png|webp|gif` |
| `MessageMedia.tsx` | un média s'affiche dans une `<img>` |

Ajouter `application/pdf` à l'enum sans toucher au reste produirait un PDF systématiquement rejeté
en `undecodable` par GD. La tranche consiste à **retirer cette prémisse**, pas à contourner ses
conséquences.

### La thèse de la tranche

**« Les octets décident » survit à des formats que les octets ne suffisent pas à identifier.**

C'est l'invariant fondateur de la T4 : le type déclaré par le client est une promesse, le type
mesuré par le worker est la vérité, et c'est le mesuré qui est stocké. Or `text/plain`, `text/csv`
et `text/markdown` sont **indistinguables par leurs octets** — non par lacune de `libmagic`, mais
par construction : RFC 4180 décrit une grammaire sans en-tête, et RFC 7763 §1.1 rappelle que le
principe même de Markdown est que la source reste du texte lisible tel quel. Aucun nombre magique
n'existe, et il n'en existera pas.

La tranche ne dilue pas l'invariant pour autant. Elle le rend **plus précis** : les octets décident
d'une **famille**, et le sous-type déclaré n'est accepté que s'il appartient à la famille mesurée.
Ce que le client contrôle est alors circonscrit — le sous-type d'un texte, jamais la famille — et ce
qu'il contrôle ne sert qu'à l'affichage et au nom de téléchargement, **jamais à une décision de
sécurité**.

---

## Section 1 — Ce que l'API accepte

### 1.1 Des types enregistrés, pas des types maison

Les quatre ajouts sont des types IANA, chacun avec sa RFC. La spec les cite parce que la citation
*est* la justification : on ne définit rien, on référence.

| Type | RFC | Note |
|---|---|---|
| `text/plain` | [RFC 2046 §4.1.3](https://datatracker.ietf.org/doc/html/rfc2046#section-4.1.3) | — |
| `text/csv` | [RFC 4180 §3](https://datatracker.ietf.org/doc/html/rfc4180#section-3) | paramètre `header` optionnel, ignoré ici |
| `text/markdown` | [RFC 7763](https://datatracker.ietf.org/doc/html/rfc7763) | `charset` requis par la RFC, `variant` optionnel ([RFC 7764](https://datatracker.ietf.org/doc/html/rfc7764)) |
| `application/pdf` | [RFC 8118](https://datatracker.ietf.org/doc/html/rfc8118) | — |

**L'API n'accepte que le type nu, sans paramètre.** RFC 7763 §2 fait de `charset` un paramètre
requis de `text/markdown` ; on ne le demande pas, et `Assert\Choice` rejetterait
`text/markdown; charset=UTF-8`. C'est un écart assumé : notre `content_type` n'est pas un en-tête
`Content-Type`, c'est une valeur d'énumération dans une charge utile JSON dont nous contrôlons les
deux extrémités. L'accepter avec paramètres obligerait à parser, normaliser et comparer des
paramètres — de la surface d'attaque pour zéro gain. **Le jeu de caractères est vérifié sur les
octets** (§2.2), ce qui vaut mieux qu'un paramètre déclaratif.

### 1.2 `MediaFamily`

```php
namespace App\Media\Domain;

/**
 * Ce que les octets suffisent à établir. Le sous-type, lui, ne l'est pas
 * toujours — voir MediaMimeType::covers().
 */
enum MediaFamily
{
    case Image;
    case Document;
}
```

Deux cas, pas trois : `Text` et `Pdf` partagent exactement le même traitement (aucune mesure, aucune
miniature, téléchargement en `attachment`). Distinguer `Text` de `Pdf` au niveau de la famille
n'aurait aucun consommateur — YAGNI.

### 1.3 `MediaMimeType`

```php
enum MediaMimeType: string
{
    case Jpeg     = 'image/jpeg';
    case Png      = 'image/png';
    case Webp     = 'image/webp';
    case Gif      = 'image/gif';
    case Text     = 'text/plain';      // RFC 2046 §4.1.3
    case Csv      = 'text/csv';        // RFC 4180 §3
    case Markdown = 'text/markdown';   // RFC 7763
    case Pdf      = 'application/pdf'; // RFC 8118

    public function family(): MediaFamily;
    public function extension(): string;
    public function covers(self $declared): bool;
}
```

`extension()` gagne `txt`, `csv`, `md`, `pdf`. `StorageKey::PATTERN` devient :

```
/\Amedia\/[0-7][0-9A-HJKMNP-TV-Z]{25}(-thumb)?\.(jpg|png|webp|gif|txt|csv|md|pdf)\z/
```

### 1.4 `covers()` — le seul endroit où vit l'indistinguabilité

`$mesuré->covers($déclaré)` rend `true` si les octets mesurés autorisent ce que le client a annoncé :

- l'égalité, toujours ;
- **et cette seule exception** : `Text` couvre `Text`, `Csv` et `Markdown`.

Rien d'autre. `Jpeg` ne couvre pas `Png`, `Pdf` ne couvre que `Pdf`. Cette méthode est le point
unique du projet où l'on admet que les octets ne tranchent pas — l'isoler dans une méthode nommée et
testée exhaustivement vaut mieux que de la laisser fuir dans un `if` du handler.

### 1.5 Où atterrit le sous-type déclaré

Il n'est pas perdu, et il ne pollue pas la vérité mesurée :

| Support | Valeur | Pourquoi |
|---|---|---|
| `media_objects.declared_mime_type` | le déclaré (`text/markdown`) | colonne existante, sémantique inchangée : « une promesse, pas une mesure » |
| `media_objects.mime_type` | le **mesuré** (`text/plain`) | son commentaire SQL promet le mesuré ; on ne le trahit pas |
| Clé de stockage | `media/01JX….md` | `StorageKey::forOriginal()` est construite **à la pré-signature**, donc depuis le déclaré |
| `Content-Type` de l'objet dans le bucket | `text/markdown` | signé au `PutObject`, donc issu du déclaré |
| `media_objects.original_filename` | `notes.md` | §3.2 — c'est *lui* qui porte le sous-type jusqu'à l'utilisateur |

Le front n'a donc pas besoin de lire un sous-type pour afficher « notes.md » : il lit le nom.

---

## Section 2 — Détection : séparer « qu'est-ce que c'est » de « mesure-le »

### 2.1 Le découpage

`ImageInspectorInterface` fait aujourd'hui deux choses en une — décider et mesurer — parce que pour
une image les deux tombent ensemble. Avec un PDF, elles se séparent. On les sépare donc dans le
code :

```php
namespace App\Media\Application;

/**
 * Point UNIQUE du projet où l'on décide ce que sont vraiment des octets.
 * Le type déclaré par le client n'entre jamais ici.
 */
interface MimeTypeDetectorInterface
{
    public function detect(string $localPath): MediaMimeType|MediaRejectionReason;
}

interface ImageInspectorInterface
{
    /** Appelée UNIQUEMENT si la famille détectée est Image. */
    public function measure(string $localPath): ImageDimensions|MediaRejectionReason;

    public function thumbnail(string $localPath, string $targetPath): void;
}
```

`InspectedImage` disparaît au profit de `ImageDimensions(int $width, int $height)` : la taille en
octets ne relève d'aucun des deux ports, `filesize()` la donne (§2.4), et le type est désormais rendu
par le détecteur.

`measure()` peut rendre `MediaRejectionReason::Undecodable` : le type est bon (`image/gif`) mais GD
échoue au décodage — fichier tronqué. C'est le motif que la T4 avait pris soin de distinguer
d'`UnsupportedType`, et il survit intact au découpage.

Implémentations : `FinfoMimeTypeDetector` dans `Media/Infrastructure/File/`, `GdImageInspector`
amputé de sa détection reste dans `Media/Infrastructure/Image/`. **Un document ne traverse jamais une
ligne de GD.**

### 2.2 La table de correspondance, et la garde UTF-8

`finfo` avec `FILEINFO_MIME_TYPE` ne rend pas `text/plain` sur tout ce qui est du texte : un fichier
commençant par un shebang sort en `text/x-shellscript`, un fichier qui ressemble à du C en
`text/x-c`. Une table strictement littérale rejetterait ces fichiers — un faux négatif que
l'utilisateur ne peut pas comprendre.

La règle retenue **vérifie positivement les octets** plutôt que de tenir une liste de méchants :

| Sortie de `finfo` | Résultat |
|---|---|
| `image/jpeg`, `image/png`, `image/webp`, `image/gif` | le cas d'enum correspondant |
| `application/pdf` | `Pdf` |
| tout `text/*` **sauf** `text/html` et `text/xml`, **et** octets valides selon la garde ci-dessous | `Text` |
| tout le reste | `MediaRejectionReason::UnsupportedType` |

**La garde** : les octets sont de l'UTF-8 valide (`mb_check_encoding($bytes, 'UTF-8')`) et ne
contiennent aucun octet `NUL`. Ce n'est pas une formalité — c'est ce qui empêche un binaire que
`libmagic` aurait classé `text/…` par hasard de finir servi comme du texte, et ça vaut mieux qu'un
paramètre `charset` déclaratif (§1.1).

`text/html` et `text/xml` restent exclus **malgré** la disposition `attachment` du §4. Défense en
profondeur : la disposition est une ligne de code qui peut régresser, l'allowlist est la seconde
barrière. Ce sont les deux seuls types texte qu'un navigateur *rend* au lieu de les afficher.

### 2.3 Une inversion de l'ordre de traitement

`ProcessMediaCommandHandler` détecte aujourd'hui **avant** de vérifier la taille. La garde UTF-8 lit
le fichier, donc cet ordre ferait charger en mémoire un fichier arbitrairement gros — la
pré-signature `PUT` ne peut pas plafonner la taille au transfert (T4 §3.2), et rien n'empêche donc
un objet de 2 Gio d'atterrir dans le bucket.

Le handler mesure donc `filesize()` **en premier** et rejette `TooLarge` avant toute lecture. Le
plafond `MediaObject::MAX_BYTES` (10 Mio) est inchangé.

### 2.4 Le handler, après

```
filesize()  >  MAX_BYTES              → reject TooLarge
detect()    →  MediaRejectionReason   → reject (UnsupportedType)
            →  famille Image          → measure() + thumbnail() + markImageReady()
            →  famille Document       → markDocumentReady()
```

**Un désaccord entre déclaré et mesuré se traite en deux cas, et c'est nouveau :**

- **Familles différentes** (déclaré `application/pdf`, mesuré `text/plain`) → **rejet**
  `UnsupportedType`. La T4 se contentait d'un `warning` parce que la conséquence était bénigne : une
  image restait une image. Ici elle ne l'est plus — la clé de stockage porte `.pdf`, et le
  `Content-Disposition` du §4 servirait le fichier sous un nom qui ment sur son contenu. On ne sert
  pas un fichier dont on sait que l'extension est fausse.
- **Même famille**, `covers()` faux (déclaré `image/jpeg`, mesuré `image/png`) → `warning` puis
  acceptation, comportement T4 inchangé.
- `covers()` vrai (déclaré `text/markdown`, mesuré `text/plain`) → **aucun log**. C'est le cas
  nominal des trois types texte ; le faire lever un `warning` remplirait le journal de bruit non
  actionnable, exactement ce que la règle « `warning` doit être actionnable » interdit.

---

## Section 3 — Agrégat et schéma

### 3.1 Deux transitions nommées

```php
public function markImageReady(
    MediaMimeType $mimeType, int $width, int $height, int $byteSize,
    StorageKey $thumbnailKey, \DateTimeImmutable $now,
): void;

public function markDocumentReady(
    MediaMimeType $mimeType, int $byteSize, \DateTimeImmutable $now,
): void;
```

Plutôt qu'un `markReady()` à paramètres nullables. Un nullable dirait « ces valeurs sont parfois
absentes » ; deux méthodes disent « il y a deux formes de média prêt, et chacune a ses obligations ».
La seconde formulation est vérifiable par le typage : on ne *peut pas* appeler `markDocumentReady()`
avec une miniature.

Chacune valide en outre que la famille du type correspond, et lève sinon — une transition ne se
laisse pas appeler à contresens.

Les deux enregistrent `MediaWasProcessed` comme aujourd'hui, `markDocumentReady()` avec
`width`/`height` à `null`.

### 3.2 `OriginalFilename`

Nouveau VO dans `Media/Domain/`. Le nom de fichier cesse d'être « uniquement de l'ergonomie côté
client » (commentaire actuel de `PresignUploadPayload`) : il devient une donnée persistée qui finit
dans un **en-tête HTTP**. Il lui faut donc un gardien.

Invariants : non vide après `trim`, ≤ 255 octets, **aucun caractère de contrôle U+0000–U+001F ni
U+007F** — ce qui couvre `\r` et `\n`, donc l'injection d'en-tête. UTF-8 valide.

Le VO ne fait **pas** de « sanitisation » silencieuse : il refuse. Un nom qu'on nettoie en douce est
un nom qu'on ne peut plus expliquer à l'utilisateur, et la contrainte du payload doit produire un
**422 avec le champ fautif**, pas un fichier renommé à son insu.

Ce qu'il ne garde **pas** : la garantie que le nom n'est pas un chemin. C'est inutile ici — la clé de
stockage vient de l'ULID (`StorageKey`), le nom n'entre nulle part dans un chemin. On ne défend pas
contre un risque qui n'existe pas, on documente pourquoi il n'existe pas.

### 3.3 Migration

Additive, plus un remplacement de contrainte. Commentaires SQL obligatoires sur la colonne ajoutée.

```sql
ALTER TABLE media_objects ADD COLUMN original_filename TEXT;
-- Rétro-remplissage : les médias T4 n'ont jamais porté de nom. On leur en
-- fabrique un depuis la clé de stockage, ce qui donne « 01JX….jpg » — laid
-- mais honnête, et surtout téléchargeable. Aucune information n'est inventée.
UPDATE media_objects
SET original_filename = regexp_replace(storage_key, '^media/', '')
WHERE original_filename IS NULL;
ALTER TABLE media_objects ALTER COLUMN original_filename SET NOT NULL;

ALTER TABLE media_objects DROP CONSTRAINT media_ready_is_measured;
ALTER TABLE media_objects ADD CONSTRAINT media_ready_is_measured CHECK (
    status <> 'ready'
    OR (
        mime_type IS NOT NULL
        AND byte_size IS NOT NULL
        AND (
            (mime_type LIKE 'image/%'
                AND width IS NOT NULL AND height IS NOT NULL
                AND thumbnail_key IS NOT NULL)
            OR
            (mime_type NOT LIKE 'image/%'
                AND width IS NULL AND height IS NULL
                AND thumbnail_key IS NULL)
        )
    )
);
```

La contrainte reste **bilatérale**, et c'est le point. Elle interdit non seulement une image prête
sans dimensions — le bug que le placeholder à proportions du front ne saurait pas gérer — mais aussi
un document prêt *avec* une miniature, qui signalerait que le handler a pris la mauvaise branche.
Une contrainte qui n'interdit que la moitié des états impossibles n'est qu'un commentaire.

---

## Section 4 — Téléchargement

### 4.1 La signature du port

```php
public function presignDownload(
    StorageKey $key,
    MediaDisposition $disposition,
    ?OriginalFilename $filename,
    \DateTimeImmutable $now,
): string;
```

`MediaDisposition` est une enum de `Media/Domain/` : `Inline`, `Attachment`. Le nom est `null` pour
`Inline` — une miniature n'a pas de nom à porter.

| Cible | Disposition | En-têtes signés |
|---|---|---|
| Miniature (toujours JPEG) | `Inline` | aucun |
| Original, famille `Image` | `Inline` | aucun |
| Original, famille `Document` | `Attachment` | `ResponseContentDisposition`, `ResponseContentType: application/octet-stream` |

### 4.2 Pourquoi `attachment` sur tous les documents

Caddy proxifie MinIO sous la même origine que l'application (`localhost:8080`) — c'est ce qui évite
CORS sur le bucket, et c'est un choix que la T4 documente comme structurant. La contrepartie : **du
contenu contrôlé par un utilisateur est servi depuis notre origine**. `attachment` +
`application/octet-stream` garantit que le navigateur télécharge sans jamais rendre. Un PDF se lit
donc après téléchargement, pas dans l'onglet : c'est une ergonomie légèrement moindre pour une
propriété de sécurité qui ne dépend d'aucune hypothèse sur le lecteur PDF du navigateur.

Les images restent `inline` — sans quoi `<img src>` cesserait de fonctionner. Elles sont couvertes
par un autre argument : leur type est mesuré sans ambiguïté par les octets, et `image/svg+xml`
(le seul format image scriptable) n'est pas dans l'allowlist et ne doit jamais y entrer.

### 4.3 Encodage du nom dans l'en-tête

`filename="…"` en ASCII n'accepte pas « rapport été.pdf ». On émet la forme
[RFC 6266](https://datatracker.ietf.org/doc/html/rfc6266) / [RFC 5987](https://datatracker.ietf.org/doc/html/rfc5987) :

```
attachment; filename="rapport.pdf"; filename*=UTF-8''rapport%20%C3%A9t%C3%A9.pdf
```

Le `filename` nu est un repli translittéré pour les clients anciens ; `filename*` porte la vérité.
Le VO ayant déjà interdit les caractères de contrôle, l'encodage percent suffit.

---

## Section 5 — Contrats publiés et temps réel

### 5.1 `MediaView` : ajout seulement

`MediaView` gagne `public string $filename`, et `toArray()` la clé `filename`. **Aucun champ n'est
retiré ni renommé** — c'est ce qui permet de livrer le backend avant le front sans rien casser. Un
consommateur qui ignore `filename` continue de fonctionner.

`mimeType` reste le **mesuré** (§1.5) : un `.md` s'y présente en `text/plain`. Le front en déduit la
famille pour choisir son rendu, et lit `filename` pour l'affichage. Il n'a jamais besoin du
sous-type.

### 5.2 `MediaWasProcessed` : inchangé

L'événement partagé ne bouge **pas**, et c'est une bonne surprise : ses `width`/`height` sont déjà
nullables (le cas `rejected` les passe à `null`), et il transporte déjà `mimeType`, ce qui suffit au
front pour savoir qu'il a affaire à un document. Aucun changement cassant d'une charge utile de
`Shared`, donc aucune coordination entre contextes.

`MessageMediaBecameReady` et l'événement Mercure `message.media_ready` sont inchangés pour la même
raison : ils recopient `MediaView`, qui ne fait que grandir.

---

## Section 6 — Front

### 6.1 `file.type` n'est plus une source fiable

`useMediaUpload.ts:88` fait `api.presignUpload(file.name, file.type, file.size)` et `putBytes`
renvoie `file.type` dans l'en-tête. Ça tient pour les images — aucun système ne se trompe sur un
JPEG. Ça ne tient plus :

- `.md` : `file.type` vaut très souvent `''`, faute d'entrée dans la base MIME du système. Un
  `content_type` vide échoue sur `Assert\Choice` → **422** ;
- `.csv` : peut sortir en `application/vnd.ms-excel` si Excel est installé → 422 également.

Une fonction pure `declaredTypeFor(file: File): DeclaredMediaType | null` dans `api/` :

1. extension de `file.name` en minuscules → `.txt`/`.csv`/`.md`/`.pdf` donnent le type RFC ;
2. sinon, `file.type` s'il est dans l'allowlist des images ;
3. sinon `null` — le fichier est refusé **localement**, avant tout appel réseau.

`putBytes(uploadUrl, file, contentType)` prend le type en paramètre. **C'est la même valeur qui part
à la pré-signature et dans l'en-tête** ; toute divergence donnerait `SignatureDoesNotMatch`, une
erreur S3 opaque que `upload.ts` documente déjà comme le piège du fichier.

`Composer.tsx:125` : `accept="image/*"` → `accept="image/*,.txt,.csv,.md,.pdf"`. `accept` est une
commodité du sélecteur, jamais une validation — `declaredTypeFor` reste la porte.

### 6.2 `MessageMedia` gagne une branche document

Les commentaires du fichier sur les proportions réservées et le transfert de propriété de la `blob:`
URL ne valent que pour les images. Ils restent, mais la branche document a sa propre logique :

- **pas de `previewUrl`** : il n'y a rien à prévisualiser d'un PDF. L'expéditeur voit donc la même
  chose que les autres membres, contrairement au cas image ;
- l'état d'attente est une ligne « pièce jointe » de **hauteur fixe**, avec le nom et un libellé.
  Aucun décalage de mise en page à traiter : la hauteur est identique entre `processing` et `ready`,
  ce qui rend tout le raisonnement `aspectRatio` sans objet ici ;
- l'état `ready` est un lien de téléchargement portant le nom, la taille, et une icône déduite de
  l'extension ;
- `rejected` est mutualisé avec le cas image, inchangé.

`StoredMedia` (dans `store/messagesReducer.ts`) gagne `filename: string`.

### 6.3 Dépôt par glisser-déposer

Ajout ergonomique, **zéro changement backend**. Il appartient à cette spec et non à une autre parce
qu'il rend le cas « type non supporté » beaucoup plus fréquent : avec le sélecteur, `accept` filtre
en amont ; en glissant, l'utilisateur lâche ce qu'il veut. `declaredTypeFor` rendant `null` est
exactement le point d'accroche du refus lisible.

**Portée : toute la conversation, avec un voile.** Les écouteurs vivent sur `ConversationView` ;
dès qu'un fichier entre, un voile couvre le fil : « Déposez pour joindre ». La cible est large, donc
le geste ne rate pas — et un fichier lâché à côté du composer n'est pas perdu.

```
┌─ ConversationView ───────────────────┐
│ ╭─ ╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌ ─╮ │
│ ╎       Déposez pour joindre       ╎ │
│ ╎  images · txt · csv · md · pdf   ╎ │
│ ╰─ ╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌ ─╯ │
│ [ Composer…                    ] (↑) │
└──────────────────────────────────────┘
```

Trois pièges que l'implémentation doit traiter, et que les tests doivent couvrir :

1. **`preventDefault()` sur `dragover` *et* sur `drop`.** Sans le premier, le navigateur refuse le
   dépôt. Sans le second, **il quitte l'application pour ouvrir le fichier** — l'onglet est perdu, le
   brouillon avec. C'est la régression la plus coûteuse et la plus facile à introduire.
2. **`dragenter`/`dragleave` se déclenchent aussi au passage sur les enfants**, ce qui fait clignoter
   le voile. On tient un compteur de profondeur, incrémenté sur `dragenter` et décrémenté sur
   `dragleave`, le voile s'affichant tant qu'il est positif — remis à zéro sur `drop`.
3. **Un dossier glissé apparaît dans `DataTransfer.files`** avec un type vide et une taille
   arbitraire. `declaredTypeFor` le rejette déjà par sa branche 3, mais le test doit le nommer :
   c'est un cas que l'utilisateur produira, pas une curiosité théorique.

Les fichiers acceptés entrent dans le flux existant de `useMediaUpload` — le glisser-déposer ne
crée aucun second chemin d'envoi. Les refusés produisent un message local nommant le fichier et les
types acceptés.

---

## Section 7 — Tests

TDD : le test qui décrit le comportement avant le code.

**Domaine (unitaire)**
- `MediaMimeType::covers()` — la table **entière**, 8 × 8. Notamment : `Text` couvre `Csv` et
  `Markdown`, `Csv` ne couvre pas `Text`, `Jpeg` ne couvre pas `Png`.
- `MediaMimeType::family()` et `extension()` sur les 8 cas.
- `OriginalFilename` — accepte « rapport été.pdf » ; refuse `"a\r\nX-Injection: y"`, la chaîne vide,
  256 octets, un octet `NUL`, de l'UTF-8 invalide.
- `StorageKey` — les 8 extensions passent, `media/01JX….exe` échoue, `../etc/passwd` échoue.
- `MediaObject::markDocumentReady()` — enregistre `MediaWasProcessed` avec `width`/`height` à `null` ;
  refuse un type de famille `Image` ; refuse depuis un état terminal.
- `MediaObject::reconstitute()` n'enregistre toujours rien (test T4 existant, à ne pas casser).

**Détection (unitaire, sur fixtures réelles)**

Fixtures binaires committées sous `backend/tests/Fixtures/Media/` :

| Fixture | Attendu |
|---|---|
| PDF minimal valide | `Pdf` |
| `.md` ordinaire | `Text` |
| `.csv` RFC 4180 | `Text` |
| `.txt` commençant par `#!/bin/sh` | `Text` — la garde UTF-8 le sauve du rejet |
| `.txt` contenant un octet `NUL` | `UnsupportedType` |
| **`.html` renommé `.txt`** | `UnsupportedType` — le test de sécurité central |
| SVG renommé `.png` | `UnsupportedType` |
| PNG valide | `Png` |
| GIF tronqué | `Gif` au détecteur, puis `Undecodable` à `measure()` |

**Handler (unitaire)**
- ordre : un fichier de 11 Mio est rejeté `TooLarge` **sans** que le détecteur soit appelé ;
- famille `Document` → aucun appel à `ImageInspectorInterface`, aucun `putObject` de miniature ;
- déclaré `application/pdf` + mesuré `text/plain` → rejet `UnsupportedType`, octets effacés ;
- déclaré `image/jpeg` + mesuré `image/png` → `warning`, puis prêt (comportement T4) ;
- déclaré `text/markdown` + mesuré `text/plain` → prêt, **aucun log de niveau `warning`** ;
- redélivrage sur média terminal → toujours ignoré (test T4 existant).

**Intégration**
- la contrainte SQL refuse l'insertion d'un `ready` non-image porteur d'un `thumbnail_key`, et d'un
  `ready` image sans dimensions ;
- la migration est réversible et le rétro-remplissage laisse zéro `NULL`.

**Fonctionnel**
- `POST /api/media/presign` avec `content_type: "application/zip"` → **422**, violation sur
  `content_type` ;
- avec `filename: "a\r\nb.txt"` → 422, violation sur `filename` ;
- l'URL signée d'un document contient bien `response-content-disposition` avec `attachment`.

**Front (Vitest)**
- `declaredTypeFor` : `.md` avec `file.type` vide → `text/markdown` ; `.csv` avec
  `application/vnd.ms-excel` → `text/csv` ; `.png` → `image/png` ; `.exe` → `null` ; un dossier
  → `null` ;
- `MessageMedia` rend une ligne de pièce jointe nommée pour un document `ready`, et pas de `<img>` ;
- `MessageMedia` d'un document `processing` a la **même hauteur** qu'à l'état `ready` ;
- glisser-déposer : `drop` appelle `preventDefault` ; le voile ne clignote pas quand `dragenter`
  survient sur un enfant ; un fichier refusé ne déclenche aucun appel à `presignUpload`.

---

## Section 8 — Découpage

Une seule branche, `feat/pieces-jointes-documentaires`, **six commits relisibles**. Chaque commit
laisse les quatre portes vertes (`make static-code-analysis`, `make check-cs`, `make deptrac`,
`make test`).

| # | Commit | Contenu |
|---|---|---|
| 1 | `feat(medias): conserver le nom de fichier d'origine` | VO `OriginalFilename`, colonne + migration, `MediaView.filename`, `MediaDisposition`, `presignDownload` étendu. Aucun type nouveau. **Utile seul** : le lien d'une image garde son nom. |
| 2 | `refactor(medias): separer la detection de la mesure` | `MimeTypeDetectorInterface`, `FinfoMimeTypeDetector`, `ImageInspectorInterface` réduit, `ImageDimensions`, handler réécrit, inversion de l'ordre taille/détection. **Comportement identique** : les tests T4 passent sans modification. |
| 3 | `feat(medias): distinguer image et document dans l'agregat` | `MediaFamily`, `markImageReady`/`markDocumentReady`, migration du `CHECK`, branche document du handler. Aucun type document dans l'enum encore : le code est couvert par des tests unitaires, pas encore atteignable par l'API. |
| 4 | `feat(medias): accepter txt, csv, md et pdf` | Les 4 cas d'enum, `covers()`, `StorageKey::PATTERN`, table finfo + garde UTF-8, règle du désaccord inter-familles. **C'est ici que l'allowlist s'ouvre** — et pas avant : aucun commit antérieur n'accepte un type que le worker ne saurait traiter. |
| 5 | `feat(front): joindre des documents` | `declaredTypeFor`, `putBytes`, `accept`, branche document de `MessageMedia`, `filename` dans `StoredMedia`. |
| 6 | `feat(front): deposer un fichier par glisser-deposer` | Voile sur `ConversationView`, compteur de profondeur, refus local lisible. |

L'ordre n'est pas négociable sur un point : **4 après 3**. L'inverse ouvrirait l'API à des types que
l'agrégat ne sait pas marquer prêts, et tout PDF téléversé serait rejeté en silence.

---

## Section 9 — Hors périmètre, explicitement

**ZIP et archives** — écarté après discussion. `application/zip` n'identifie pas un « fichier zip » :
c'est le conteneur de `.docx`, `.xlsx`, `.odt`, `.epub`, `.jar` et `.apk`. L'accepter reviendrait à
accepter un APK renommé, et la T4 a déjà écarté l'analyse antivirus. On conserve donc l'invariant
« tout média servi appartient à un format qu'on sait inspecter ». Le ZIP sera une décision séparée,
avec sa propre analyse de risque — pas un cas de plus dans une enum.

Également hors périmètre : bureautique (`.docx`, `.xlsx`, `.odt`) · vidéo et audio · aperçu de PDF
dans l'onglet · miniature de première page de PDF · extraction de texte pour la recherche (ce sera
la T5, sur son propre périmètre) · rendu Markdown côté front (on stocke et on télécharge, on
n'interprète pas) · détection du délimiteur ou de l'en-tête d'un CSV · quotas · chiffrement au
repos · antivirus · aperçus de liens et défense SSRF, toujours une tranche à part.

---

## Critères d'acceptation

1. Un `.md`, un `.csv`, un `.txt` et un `.pdf` se joignent à un message et se téléchargent **sous
   leur nom d'origine**, y compris avec des accents.
2. Un `.html` renommé en `.txt` est **rejeté** par le worker, ses octets effacés du bucket, avec un
   `warning` actionnable.
3. Un `.zip` est refusé en **422** à la pré-signature, avec une violation nommant `content_type`.
4. Un PDF déclaré alors que les octets sont du texte est rejeté ; un `.md` déclaré `text/markdown`
   et mesuré `text/plain` est accepté **sans aucun log de niveau `warning`**.
5. Les images de la tranche 4 fonctionnent exactement comme avant : miniature `inline`, placeholder à
   proportions, réconciliation optimiste.
6. Un fichier glissé sur la conversation est joint ; un fichier non supporté produit un message
   local **sans aucun appel réseau** ; aucun dépôt ne fait quitter l'application.
7. En base, il est impossible d'écrire un document `ready` porteur d'une miniature, ou une image
   `ready` sans dimensions.
8. `make static-code-analysis`, `make check-cs`, `make deptrac` et `make test` sont verts à chacun
   des six commits.
