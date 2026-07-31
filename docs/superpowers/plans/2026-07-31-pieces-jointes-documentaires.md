# Pièces jointes documentaires — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** permettre de joindre à un message des fichiers `.txt`, `.csv`, `.md` et `.pdf`, en plus des
images déjà livrées par la tranche 4, et les déposer par glisser-déposer.

**Architecture:** on retire du contexte `Media` la prémisse « un média est une image ». La détection
du type réel sort de `ImageInspectorInterface` vers un `MimeTypeDetectorInterface` dédié ; l'agrégat
gagne deux transitions nommées (`markImageReady` / `markDocumentReady`) au lieu d'une seule aux
paramètres nullables ; l'allowlist n'est ouverte qu'au dernier commit backend, quand tout le chemin
sait traiter un document.

**Tech Stack:** PHP 8.4 / Symfony · DBAL (SQL pur, pas d'ORM) · `ext-fileinfo`, `ext-gd` ·
AWS SDK (MinIO) · Messenger/RabbitMQ · React + TypeScript + Vite + Vitest.

**Spec de référence :**
[`docs/superpowers/specs/2026-07-31-pieces-jointes-documentaires-design.md`](../specs/2026-07-31-pieces-jointes-documentaires-design.md).
En cas de divergence entre ce plan et la spec, **la spec gagne** — signale l'écart plutôt que de
trancher seul.

## Global Constraints

- **Branche unique** : `feat/pieces-jointes-documentaires`. Jamais de commit sur `main`.
- **Ni PHP ni Node ne sont installés sur la machine.** Toute commande passe par `make` ou
  `docker compose run --rm <service>`. Ne jamais invoquer `php`, `composer`, `npm`, `vendor/bin/*`
  directement.
- **Ne pas installer de paquet Composer ni npm.** S'il en manque un, arrêter et le signaler.
- **`Domain/` ne dépend de rien** — zéro vendor, pas même `symfony/uid`. `Application/` ne connaît
  que les `Psr\*`.
- **Quatre portes vertes avant chaque commit** : `make static-code-analysis` · `make check-cs` ·
  `make deptrac` · `make test`. Plus `make front-test` et `make front-typecheck` pour les tâches 5
  et 6.
- **PHPStan niveau `max`.** Jamais de baseline, jamais de `@phpstan-ignore`.
- **TDD** : le test d'abord, on le voit échouer, puis le code minimal.
- **Commits conventionnels, en français, à l'impératif.** Un commit par tâche.
- **Migrations** : `COMMENT ON COLUMN` obligatoire sur toute colonne créée.
- **Logs** : placeholders `{entre_accolades}`, variables dans le second argument, message littéral
  constant. Jamais de nom de fichier complet ni de contenu dans un log — des identifiants.
- **`sprintf()`**, jamais de concaténation par `.` (sauf messages de log, cf. ci-dessus).
- Tous les commentaires de code sont **en français, sans accents** (convention du dépôt : les
  fichiers PHP existants n'en portent pas).

---

## File Structure

**Créés (backend)**

| Fichier | Responsabilité |
|---|---|
| `backend/src/Media/Domain/OriginalFilename.php` | VO du nom de fichier ; refuse ce qui ne peut pas aller dans un en-tête HTTP |
| `backend/src/Media/Domain/MediaDisposition.php` | Enum `Inline` / `Attachment` |
| `backend/src/Media/Domain/MediaFamily.php` | Enum `Image` / `Document` — ce que les octets suffisent à établir |
| `backend/src/Media/Application/MimeTypeDetectorInterface.php` | Port : « que sont vraiment ces octets ? » |
| `backend/src/Media/Application/ImageDimensions.php` | DTO `width`/`height`, remplace `InspectedImage` |
| `backend/src/Media/Infrastructure/File/FinfoMimeTypeDetector.php` | Adaptateur `finfo` + garde UTF-8 |

**Modifiés (backend)** — `MediaObject`, `MediaMimeType`, `StorageKey`, `MediaMapper`,
`DbalMediaRepository`, `DbalMediaFinder`, `MediaView`, `MediaStorageInterface`, `S3MediaStorage`,
`ImageInspectorInterface`, `GdImageInspector`, `ProcessMediaCommandHandler`,
`RequestMediaUploadCommand(+Handler, +Controller)`, `PresignUploadPayload`, `config/services.yaml`.

**Supprimé** — `backend/src/Media/Application/InspectedImage.php` (tâche 2).

**Créés / modifiés (frontend)** — `src/api/declaredType.ts` (nouveau), `src/api/upload.ts`,
`src/api/client.ts`, `src/api/types.ts`, `src/hooks/useMediaUpload.ts`,
`src/store/messagesReducer.ts`, `src/ui/MessageMedia.tsx`, `src/ui/Composer.tsx`,
`src/ui/ConversationView.tsx`, `src/ui/DropOverlay.tsx` (nouveau).

---

## Task 1 : conserver le nom de fichier d'origine

Aucun type nouveau. À la fin de cette tâche, ouvrir une image téléversée propose « photo.jpg »
dans « Enregistrer sous… » au lieu de « 01JX….jpg ».

**Files:**
- Create: `backend/src/Media/Domain/OriginalFilename.php`
- Create: `backend/src/Media/Domain/MediaDisposition.php`
- Create: `backend/tests/Unit/Media/Domain/OriginalFilenameTest.php`
- Create: `backend/migrations/Version<horodatage>.php` (généré)
- Modify: `backend/src/Media/Domain/MediaObject.php`
- Modify: `backend/src/Media/Application/MediaStorageInterface.php`
- Modify: `backend/src/Media/Infrastructure/Storage/S3MediaStorage.php`
- Modify: `backend/src/Media/Application/Contract/MediaView.php`
- Modify: `backend/src/Media/Infrastructure/Contract/DbalMediaFinder.php`
- Modify: `backend/src/Media/Infrastructure/Persistence/MediaMapper.php`
- Modify: `backend/src/Media/Infrastructure/Persistence/DbalMediaRepository.php`
- Modify: `backend/src/Media/Application/Command/RequestMediaUploadCommand.php` + son handler
- Modify: `backend/src/Media/Infrastructure/Http/RequestMediaUploadController.php`
- Modify: `backend/src/Media/Infrastructure/Http/Payload/PresignUploadPayload.php`
- Modify: `backend/tests/Functional/Media/MediaReadyPublicationTest.php` (liste de clés)

**Interfaces:**
- Produces:
  - `OriginalFilename::fromString(string): self`, `::toString(): string`, `::PATTERN` (const string),
    `::MAX_LENGTH` (const int)
  - `MediaDisposition::Inline`, `MediaDisposition::Attachment`
  - `MediaObject::request(MediaId, UserId, StorageKey, OriginalFilename, MediaMimeType, int, \DateTimeImmutable): self`
  - `MediaObject::originalFilename(): OriginalFilename`
  - `MediaStorageInterface::presignDownload(StorageKey, MediaDisposition, ?OriginalFilename, \DateTimeImmutable): string`
  - `MediaView` : nouvelle propriété finale `public string $filename`

- [ ] **Step 1: Écrire le test du VO**

Créer `backend/tests/Unit/Media/Domain/OriginalFilenameTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\OriginalFilename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginalFilename::class)]
final class OriginalFilenameTest extends TestCase
{
    public function testItKeepsTheNameVerbatim(): void
    {
        self::assertSame('rapport ete.pdf', OriginalFilename::fromString('rapport ete.pdf')->toString());
    }

    public function testItAcceptsNonAsciiCharacters(): void
    {
        self::assertSame('rapport été.pdf', OriginalFilename::fromString('rapport été.pdf')->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        // Le cas qui justifie ce VO : un nom qui finit dans un en-tete HTTP.
        yield 'injection d\'en-tete' => ["facture\r\nX-Injection: oui.pdf"];
        yield 'saut de ligne seul' => ["deux\nlignes.txt"];
        yield 'octet NUL' => ["truque\x00.pdf"];
        yield 'caractere de suppression' => ["truque\x7F.pdf"];
        yield 'chaine vide' => [''];
        yield 'espaces seuls' => ['   '];
        yield 'trop long' => [str_repeat('a', 256)];
        yield 'utf-8 invalide' => ["\xC3\x28.pdf"];
    }

    #[DataProvider('invalidNames')]
    public function testItRefusesWhatCannotGoInAHeader(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OriginalFilename::fromString($name);
    }

    public function testItAcceptsExactlyTheMaximumLength(): void
    {
        self::assertSame(255, mb_strlen(OriginalFilename::fromString(str_repeat('a', 255))->toString()));
    }
}
```

- [ ] **Step 2: Voir le test échouer**

```bash
make unit-test ARGS="--filter=OriginalFilenameTest"
```

Attendu : ERROR, `Class "App\Media\Domain\OriginalFilename" not found`.

- [ ] **Step 3: Écrire le VO**

Créer `backend/src/Media/Domain/OriginalFilename.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Le nom que l'utilisateur a donne a son fichier, et rien d'autre. Il ne
 * devient JAMAIS un chemin : la cle de stockage se fabrique depuis l'ULID
 * (voir StorageKey). Sa seule destination est l'en-tete
 * `Content-Disposition` de l'URL de telechargement signee.
 *
 * C'est cette destination qui justifie le VO : un `\r\n` dans un en-tete HTTP
 * est une injection. Le VO REFUSE, il ne nettoie pas — un nom rafistole en
 * silence est un nom qu'on ne peut plus expliquer a l'utilisateur, alors qu'un
 * refus remonte en 422 avec le champ fautif.
 */
final readonly class OriginalFilename implements \Stringable
{
    public const int MAX_LENGTH = 255;

    /**
     * Aucun caractere de controle U+0000-U+001F ni U+007F : cela couvre `\r`,
     * `\n` et l'octet NUL. Le drapeau `u` fait echouer `preg_match` sur de
     * l'UTF-8 invalide, ce qui le rejette par la meme occasion.
     */
    public const string PATTERN = '/\A[^\x00-\x1F\x7F]++\z/u';

    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Un nom de fichier ne peut pas etre vide.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Ce nom de fichier est trop long.');
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Ce nom de fichier contient des caracteres interdits.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 4: Voir le test passer**

```bash
make unit-test ARGS="--filter=OriginalFilenameTest"
```

Attendu : PASS, 11 assertions.

- [ ] **Step 5: Créer l'enum de disposition**

Créer `backend/src/Media/Domain/MediaDisposition.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Comment le navigateur doit traiter les octets qu'on lui sert.
 *
 * `Inline` ne veut pas dire « sans nom » : une image s'affiche dans une
 * balise `<img>` ET propose son vrai nom dans « Enregistrer sous… ».
 */
enum MediaDisposition: string
{
    case Inline = 'inline';
    case Attachment = 'attachment';
}
```

- [ ] **Step 6: Générer la migration**

```bash
make generate-migration
```

Remplir la classe générée (`up()` uniquement ; `down()` fait l'inverse) :

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE media_objects ADD COLUMN original_filename TEXT');

    // Retro-remplissage : les medias de la tranche 4 n'ont jamais porte de
    // nom. On leur en fabrique un depuis la cle de stockage, ce qui donne
    // « 01JX....jpg » — laid mais honnete, et surtout telechargeable. On
    // n'invente aucune information qu'on n'a pas.
    $this->addSql("UPDATE media_objects SET original_filename = regexp_replace(storage_key, '^media/', '') WHERE original_filename IS NULL");

    $this->addSql('ALTER TABLE media_objects ALTER COLUMN original_filename SET NOT NULL');

    $this->comment(
        'COLUMN media_objects.original_filename',
        "Nom donne au fichier par l'utilisateur, tel qu'il l'a envoye. Ne devient JAMAIS un chemin : la cle de stockage vient de l'ULID. Sa seule destination est l'en-tete Content-Disposition de l'URL signee.",
    );
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE media_objects DROP COLUMN original_filename');
}
```

Recopier le helper privé `comment()` depuis `Version20260726144434.php:118-121` si la classe générée
ne l'a pas.

- [ ] **Step 7: Appliquer la migration**

```bash
make migrate
```

Attendu : la migration s'applique sans erreur. Vérifier avec `make migration-status`.

- [ ] **Step 8: Faire porter le nom par l'agrégat**

Dans `MediaObject` : ajouter `private readonly OriginalFilename $originalFilename` au constructeur
(juste après `$storageKey`), le propager dans `request()` et `reconstitute()` avec la même position,
et ajouter l'accesseur :

```php
public function originalFilename(): OriginalFilename
{
    return $this->originalFilename;
}
```

- [ ] **Step 9: Propager dans la persistance**

`MediaMapper::fromRow()` — ajouter `original_filename: string` au `@param array{…}` et passer
`OriginalFilename::fromString($row['original_filename'])` en 4ᵉ argument de `reconstitute()`.

`DbalMediaRepository::add()` — la requête devient :

```sql
INSERT INTO media_objects (id, owner_id, storage_key, original_filename, status, declared_mime_type, declared_size, created_at)
VALUES (:id, :owner_id, :storage_key, :original_filename, :status, :declared_mime_type, :declared_size, :created_at)
```

avec `'original_filename' => $media->originalFilename()->toString()` dans les paramètres liés.

`DbalMediaRepository::ofId()` — ajouter `original_filename` au `SELECT` **et** au `@var array{…}`.
Ne pas toucher à `save()` : le nom n'est pas mutable.

- [ ] **Step 10: Étendre le port de stockage**

`MediaStorageInterface::presignDownload()` devient :

```php
/**
 * `$filename` est `null` pour une miniature : elle n'a pas de nom a porter,
 * ce n'est pas un fichier que l'utilisateur a choisi.
 */
public function presignDownload(
    StorageKey $key,
    MediaDisposition $disposition,
    ?OriginalFilename $filename,
    \DateTimeImmutable $now,
): string;
```

Dans `S3MediaStorage::presignDownload()`, construire les paramètres de commande :

```php
$parameters = ['Bucket' => $this->bucket, 'Key' => $key->toString()];

if (null !== $filename) {
    $parameters['ResponseContentDisposition'] = $this->contentDisposition($disposition, $filename);
}

if (MediaDisposition::Attachment === $disposition) {
    // Le navigateur ne doit rien deduire du type : il telecharge, point.
    $parameters['ResponseContentType'] = 'application/octet-stream';
}

$command = $this->signerClient->getCommand('GetObject', $parameters);
```

et ajouter la méthode privée :

```php
/**
 * RFC 6266 : `filename` en ASCII pour les clients anciens, `filename*` en
 * UTF-8 pour la verite. Les deux, parce qu'un client qui ne comprend pas
 * `filename*` doit quand meme obtenir quelque chose de lisible.
 *
 * Le VO OriginalFilename a deja interdit les caracteres de controle ; il
 * reste a neutraliser le guillemet et l'antislash, qui fermeraient la
 * chaine citee.
 */
private function contentDisposition(MediaDisposition $disposition, OriginalFilename $filename): string
{
    $name = $filename->toString();
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'fichier';
    $ascii = str_replace(['\\', '"'], '_', $ascii);

    return sprintf(
        '%s; filename="%s"; filename*=UTF-8\'\'%s',
        $disposition->value,
        $ascii,
        rawurlencode($name),
    );
}
```

- [ ] **Step 11: Exposer le nom dans le contrat publié**

`MediaView` — ajouter `public string $filename` **en dernier paramètre** du constructeur (l'ajout en
queue évite de renuméroter les appels existants) et `'filename' => $this->filename` dans `toArray()`.

`DbalMediaFinder::viewsFor()` — ajouter `original_filename` au `SELECT` et au `@var list<array{…}>`,
puis, dans la boucle :

```php
$filename = OriginalFilename::fromString($row['original_filename']);

$views[$row['id']] = new MediaView(
    $row['id'],
    $status->value,
    null === $thumbnailKey ? null : $row['mime_type'],
    null === $thumbnailKey ? null : $row['width'],
    null === $thumbnailKey ? null : $row['height'],
    null === $thumbnailKey
        ? null
        // Inline AVEC nom : l'image s'affiche toujours dans <img>, mais
        // « Enregistrer sous… » propose enfin le vrai nom.
        : $this->storage->presignDownload(StorageKey::fromString($row['storage_key']), MediaDisposition::Inline, $filename, $now),
    null === $thumbnailKey
        ? null
        : $this->storage->presignDownload(StorageKey::fromString($thumbnailKey), MediaDisposition::Inline, null, $now),
    $filename->toString(),
);
```

- [ ] **Step 12: Faire remonter le nom depuis le contrôleur**

`PresignUploadPayload` — la contrainte de format **référence** le VO, elle ne la redéclare pas :

```php
#[Assert\NotBlank(message: 'Le nom du fichier est requis.')]
#[Assert\Length(max: OriginalFilename::MAX_LENGTH, maxMessage: 'Ce nom de fichier est trop long.')]
#[Assert\Regex(
    pattern: OriginalFilename::PATTERN,
    message: 'Ce nom de fichier contient des caracteres interdits.',
)]
public string $filename = '',
```

`RequestMediaUploadCommand` — ajouter `public OriginalFilename $originalFilename` après `$ownerId`.

`RequestMediaUploadCommandHandler` — passer `$command->originalFilename` en 4ᵉ argument de
`MediaObject::request()`. **Ne rien ajouter au log** : un nom de fichier est une donnée utilisateur,
et la règle « on loggue des identifiants, jamais des charges utiles » s'y applique.

`RequestMediaUploadController` — construire le VO :

```php
// Les contraintes du payload ont deja garanti le format : `fromString` ne
// peut pas echouer ici, et un `try` masquerait un bug de contrainte.
OriginalFilename::fromString($payload->filename),
```

- [ ] **Step 13: Corriger l'assertion de forme du test fonctionnel**

`backend/tests/Functional/Media/MediaReadyPublicationTest.php:67` liste les clés exactes de la charge
utile. Ajouter `'filename'` **en fin de liste** :

```php
['id', 'status', 'mime_type', 'width', 'height', 'url', 'thumbnail_url', 'filename'],
```

- [ ] **Step 14: Ajouter le test fonctionnel du nom signé**

Dans `backend/tests/Functional/Media/PresignedUploadSignatureTest.php`, ajouter :

```php
public function testTheDownloadUrlCarriesTheOriginalFilename(): void
{
    // Suivre le motif d'arrangement deja utilise dans ce fichier pour
    // obtenir un media `ready` (fixtures + confirmation), puis :
    $url = $this->mediaViewFor($mediaId)->url;

    self::assertIsString($url);
    self::assertStringContainsString('response-content-disposition=', $url);
    self::assertStringContainsString('inline', urldecode($url));
    self::assertStringContainsString('filename%2A%3DUTF-8', $url);
}
```

Adapter l'arrangement au style du fichier — ne pas inventer de helper qui n'existe pas ; lire les
tests voisins de `tests/Functional/Media/` avant d'écrire.

- [ ] **Step 15: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

Attendu : les quatre vertes. Si `check-cs` râle, `make apply-cs` puis relire le diff.

- [ ] **Step 16: Commit**

```bash
git add -A
git commit -m "feat(medias): conserver le nom de fichier d'origine"
```

---

## Task 2 : séparer la détection de la mesure

**Refactoring pur.** Aucun comportement observable ne change : tous les tests de la tranche 4 doivent
passer **sans modification**, à l'exception de `GdImageInspectorTest` dont l'objet même se scinde.

**Files:**
- Create: `backend/src/Media/Application/MimeTypeDetectorInterface.php`
- Create: `backend/src/Media/Application/ImageDimensions.php`
- Create: `backend/src/Media/Infrastructure/File/FinfoMimeTypeDetector.php`
- Create: `backend/tests/Unit/Media/Infrastructure/FinfoMimeTypeDetectorTest.php`
- Create: `backend/tests/Fixtures/Media/` (fixtures binaires)
- Delete: `backend/src/Media/Application/InspectedImage.php`
- Modify: `backend/src/Media/Application/ImageInspectorInterface.php`
- Modify: `backend/src/Media/Infrastructure/Image/GdImageInspector.php`
- Modify: `backend/src/Media/Application/Command/ProcessMediaCommandHandler.php`
- Modify: `backend/config/services.yaml`
- Modify: `backend/tests/Unit/Media/Infrastructure/GdImageInspectorTest.php`
- Modify: `backend/tests/Unit/Media/Application/Command/ProcessMediaCommandHandlerTest.php`

**Interfaces:**
- Consumes: rien de la tâche 1.
- Produces:
  - `MimeTypeDetectorInterface::detect(string $localPath): MediaMimeType|MediaRejectionReason`
  - `ImageDimensions` : `__construct(public int $width, public int $height)`
  - `ImageInspectorInterface::measure(string $localPath): ImageDimensions|MediaRejectionReason`
  - `ImageInspectorInterface::thumbnail(string $localPath, string $targetPath): void` (inchangé)

- [ ] **Step 1: Créer les fixtures binaires**

Créer `backend/tests/Fixtures/Media/` et y produire les fichiers depuis le conteneur backend (aucun
outil n'est installé sur la machine) :

```bash
docker compose run --rm backend sh -c '
  cd tests/Fixtures/Media
  printf "%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n" > minimal.pdf
  printf "# Titre\n\nUn paragraphe.\n" > notes.md
  printf "nom,age\nAlice,30\nBob,41\n" > donnees.csv
  printf "#!/bin/sh\necho bonjour\n" > script-comme-texte.txt
  printf "texte\x00binaire\n" > avec-nul.txt
  printf "<html><body><script>alert(1)</script></body></html>\n" > deguise.txt
  printf "<svg xmlns=\"http://www.w3.org/2000/svg\"><rect/></svg>\n" > deguise.png
  php -r "imagepng(imagecreatetruecolor(20, 10), \"valide.png\");"
  php -r "\$g = imagecreatetruecolor(20, 10); imagegif(\$g, \"tronque.gif\");"
  php -r "\$c = file_get_contents(\"tronque.gif\"); file_put_contents(\"tronque.gif\", substr(\$c, 0, 12));"
'
```

Vérifier avec `docker compose run --rm backend ls -la tests/Fixtures/Media` que les dix fichiers
existent et ne sont pas vides.

- [ ] **Step 2: Écrire le test du détecteur**

Créer `backend/tests/Unit/Media/Infrastructure/FinfoMimeTypeDetectorTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Infrastructure\File\FinfoMimeTypeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinfoMimeTypeDetector::class)]
final class FinfoMimeTypeDetectorTest extends TestCase
{
    /** @return iterable<string, array{string, MediaMimeType|MediaRejectionReason}> */
    public static function fixtures(): iterable
    {
        yield 'png valide' => ['valide.png', MediaMimeType::Png];
        yield 'gif tronque garde son type' => ['tronque.gif', MediaMimeType::Gif];
        // Un SVG renomme .png : c'est le deguisement que la tranche 4 attrape
        // deja, et qui doit continuer d'etre attrape.
        yield 'svg deguise en png' => ['deguise.png', MediaRejectionReason::UnsupportedType];
    }

    #[DataProvider('fixtures')]
    public function testItReadsWhatTheBytesReallyAre(string $fixture, MediaMimeType|MediaRejectionReason $expected): void
    {
        self::assertSame($expected, (new FinfoMimeTypeDetector())->detect(self::path($fixture)));
    }

    public function testAMissingFileIsUnsupported(): void
    {
        self::assertSame(
            MediaRejectionReason::UnsupportedType,
            (new FinfoMimeTypeDetector())->detect('/tmp/ce-fichier-n-existe-pas'),
        );
    }

    private static function path(string $fixture): string
    {
        return sprintf('%s/../../../Fixtures/Media/%s', __DIR__, $fixture);
    }
}
```

> Les fixtures texte et le PDF ne sont **pas** testées ici : `text/plain` et `application/pdf` ne
> sont pas encore dans l'allowlist. Elles arrivent à la tâche 4, qui étendra ce data provider.

- [ ] **Step 3: Voir le test échouer**

```bash
make unit-test ARGS="--filter=FinfoMimeTypeDetectorTest"
```

Attendu : ERROR, `Class "App\Media\Infrastructure\File\FinfoMimeTypeDetector" not found`.

- [ ] **Step 4: Écrire le port et l'adaptateur**

Créer `backend/src/Media/Application/MimeTypeDetectorInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

/**
 * Point UNIQUE du projet ou l'on decide ce que sont vraiment des octets. Le
 * type declare par le client n'entre jamais ici : c'est toute la these de la
 * tranche 4, et elle survit aux documents.
 *
 * Un `Domain` dans la signature d'un port d'`Application` reste dans les
 * clous de deptrac : c'est un type de retour, pas une dependance vendor.
 */
interface MimeTypeDetectorInterface
{
    public function detect(string $localPath): MediaMimeType|MediaRejectionReason;
}
```

Créer `backend/src/Media/Infrastructure/File/FinfoMimeTypeDetector.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\File;

use App\Media\Application\MimeTypeDetectorInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

final readonly class FinfoMimeTypeDetector implements MimeTypeDetectorInterface
{
    public function detect(string $localPath): MediaMimeType|MediaRejectionReason
    {
        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return MediaRejectionReason::UnsupportedType;
        }

        return MediaMimeType::tryFrom($detected) ?? MediaRejectionReason::UnsupportedType;
    }
}
```

Créer `backend/src/Media/Application/ImageDimensions.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application;

/** Ce que seule une image possede. Un document n'en a jamais. */
final readonly class ImageDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
```

- [ ] **Step 5: Voir le test passer**

```bash
make unit-test ARGS="--filter=FinfoMimeTypeDetectorTest"
```

Attendu : PASS.

- [ ] **Step 6: Amputer `ImageInspectorInterface`**

Remplacer le contenu de `backend/src/Media/Application/ImageInspectorInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaRejectionReason;

/**
 * Ne decide plus de RIEN : la detection est passee a
 * MimeTypeDetectorInterface. Ce port ne sait que mesurer et miniaturiser, et
 * on ne l'appelle que sur des octets deja reconnus comme une image.
 */
interface ImageInspectorInterface
{
    /**
     * Rend `MediaRejectionReason::Undecodable` quand le type etait bon mais
     * que le decodage echoue : fichier tronque ou corrompu. C'est la
     * distinction que la tranche 4 avait prise soin d'etablir, et elle
     * survit au decoupage.
     */
    public function measure(string $localPath): ImageDimensions|MediaRejectionReason;

    /** Ecrit une miniature JPEG dans `$targetPath`, ratio preserve. */
    public function thumbnail(string $localPath, string $targetPath): void;
}
```

Supprimer `backend/src/Media/Application/InspectedImage.php`.

Dans `GdImageInspector`, remplacer `inspect()` par :

```php
public function measure(string $localPath): ImageDimensions|MediaRejectionReason
{
    // `@` parce que getimagesize() emet un warning PHP sur un fichier
    // corrompu, et `failOnWarning` est actif dans la suite de tests.
    $size = @getimagesize($localPath);

    return false === $size
        ? MediaRejectionReason::Undecodable
        : new ImageDimensions($size[0], $size[1]);
}
```

`thumbnail()` est inchangée. Ajuster les `use` : `ImageDimensions` remplace `InspectedImage`,
`MediaMimeType` disparaît.

- [ ] **Step 7: Réécrire le handler**

Dans `ProcessMediaCommandHandler`, injecter `MimeTypeDetectorInterface $detector` en plus de
`ImageInspectorInterface $inspector`, et remplacer le corps du `try` par :

```php
// La taille AVANT tout le reste : rien ne plafonne un PUT pre-signe
// (spec T4 §3.2), donc un objet de 2 Gio peut atterrir dans le bucket.
// Le detecter avant de lire quoi que ce soit evite de le charger.
$byteSize = filesize($localPath);

if (false === $byteSize) {
    $this->reject($media, MediaRejectionReason::MissingObject, $now, eraseBytes: false);

    return;
}

if ($byteSize > MediaObject::MAX_BYTES) {
    $this->reject($media, MediaRejectionReason::TooLarge, $now, eraseBytes: true);

    return;
}

$mimeType = $this->detector->detect($localPath);

if ($mimeType instanceof MediaRejectionReason) {
    // Un `.jpg` qui contient du PHP meurt ICI, pas a l'affichage.
    $this->reject($media, $mimeType, $now, eraseBytes: true);

    return;
}

$dimensions = $this->inspector->measure($localPath);

if ($dimensions instanceof MediaRejectionReason) {
    // Un GIF tronque : le type etait bon, seul le decodage a echoue.
    $this->reject($media, $dimensions, $now, eraseBytes: true);

    return;
}

$thumbnailPath = sprintf('%s-thumb', $localPath);
$thumbnailKey = StorageKey::forThumbnail($media->id());
$this->inspector->thumbnail($localPath, $thumbnailPath);
$this->storage->put($thumbnailKey, $thumbnailPath, MediaMimeType::Jpeg);

$media->markReady($mimeType, $dimensions->width, $dimensions->height, $byteSize, $thumbnailKey, $now);
$this->media->save($media);

if ($mimeType !== $media->declaredMimeType()) {
    $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
        'media_id' => $media->id()->toString(),
        'declared_mime_type' => $media->declaredMimeType()->value,
        'actual_mime_type' => $mimeType->value,
    ]);
}

$this->logger->info('Media {media_id} pret', [
    'media_id' => $media->id()->toString(),
    'width' => $dimensions->width,
    'height' => $dimensions->height,
    'byte_size' => $byteSize,
]);
```

Le garde-fou de redélivrage en tête de méthode et le `finally` de nettoyage sont **inchangés**.

- [ ] **Step 8: Câbler le nouveau service**

Dans `backend/config/services.yaml`, à côté de la ligne `App\Media\Application\ImageInspectorInterface` :

```yaml
    App\Media\Application\MimeTypeDetectorInterface: '@App\Media\Infrastructure\File\FinfoMimeTypeDetector'
```

- [ ] **Step 9: Adapter les tests existants**

`GdImageInspectorTest` : les cas qui testaient la détection (`UnsupportedType` sur un SVG déguisé)
déménagent vers `FinfoMimeTypeDetectorTest` — ils y sont déjà à l'étape 2, donc les **supprimer** ici.
Les cas de mesure et de miniature deviennent des appels à `measure()`.

`ProcessMediaCommandHandlerTest` : ajouter le double de `MimeTypeDetectorInterface` au constructeur.
Les scénarios existants gardent **exactement les mêmes assertions** — c'est ce qui prouve que le
refactoring est neutre. Ajouter un cas :

```php
public function testAnOversizedFileIsRejectedWithoutEvenDetectingItsType(): void
{
    // L'ordre compte : detecter d'abord ferait lire un fichier
    // arbitrairement gros. Le detecteur ne doit PAS etre appele.
    // Arranger un fichier temporaire de MAX_BYTES + 1 octets, puis :
    self::assertSame(MediaRejectionReason::TooLarge, $media->rejectionReason());
    self::assertSame(0, $detector->detectCallCount());
}
```

Suivre le style de doublure déjà utilisé dans ce fichier (classe anonyme ou fake nommé) — lire le
test avant d'écrire.

- [ ] **Step 10: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

Attendu : vertes. **Aucun test fonctionnel de la tranche 4 ne doit avoir été modifié** — si l'un
échoue, le refactoring a changé un comportement : corriger le code, pas le test.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "refactor(medias): separer la detection de la mesure"
```

---

## Task 3 : distinguer image et document dans l'agrégat

Toujours aucun type document dans l'allowlist : le code ajouté ici est couvert par des tests
unitaires, mais pas encore atteignable depuis l'API. C'est voulu — la tâche 4 ouvre la porte
seulement quand tout le chemin sait traiter un document.

**Files:**
- Create: `backend/src/Media/Domain/MediaFamily.php`
- Create: `backend/migrations/Version<horodatage>.php` (généré)
- Create: `backend/tests/Functional/Media/MediaReadyConstraintTest.php`
- Modify: `backend/src/Media/Domain/MediaMimeType.php`
- Modify: `backend/src/Media/Domain/MediaObject.php`
- Modify: `backend/src/Media/Application/Command/ProcessMediaCommandHandler.php`
- Modify: `backend/tests/Unit/Media/Domain/MediaObjectTest.php`

**Interfaces:**
- Consumes: `ImageDimensions`, `MimeTypeDetectorInterface` (tâche 2).
- Produces:
  - `MediaFamily::Image`, `MediaFamily::Document`
  - `MediaMimeType::family(): MediaFamily`
  - `MediaObject::markImageReady(MediaMimeType, int $width, int $height, int $byteSize, StorageKey $thumbnailKey, \DateTimeImmutable): void`
  - `MediaObject::markDocumentReady(MediaMimeType, int $byteSize, \DateTimeImmutable): void`

- [ ] **Step 1: Écrire les tests des deux transitions**

Ajouter à `backend/tests/Unit/Media/Domain/MediaObjectTest.php` :

```php
public function testADocumentBecomesReadyWithoutDimensionsOrThumbnail(): void
{
    $media = $this->pendingMedia();
    $media->markUploaded($this->now);

    $media->markDocumentReady(MediaMimeType::Text, 4_096, $this->now);

    self::assertSame(MediaStatus::Ready, $media->status());
    self::assertSame(4_096, $media->byteSize());
    self::assertNull($media->width());
    self::assertNull($media->height());
    self::assertNull($media->thumbnailKey());
}

public function testADocumentAnnouncesItselfLikeAnImageDoes(): void
{
    // Sans cet evenement, un message porteur resterait « en cours… » pour
    // toujours. C'est la meme raison que pour markRejected().
    $media = $this->pendingMedia();
    $media->markUploaded($this->now);

    $media->markDocumentReady(MediaMimeType::Text, 4_096, $this->now);

    $events = $media->releaseEvents();
    self::assertCount(1, $events);
    self::assertInstanceOf(MediaWasProcessed::class, $events[0]);
    self::assertNull($events[0]->width);
    self::assertNull($events[0]->height);
}

public function testAnImageCannotBeMarkedReadyAsADocument(): void
{
    // Une transition ne se laisse pas appeler a contresens : c'est ce qui
    // rend les deux methodes meilleures qu'un markReady() a nullables.
    $media = $this->pendingMedia();
    $media->markUploaded($this->now);

    $this->expectException(\InvalidArgumentException::class);

    $media->markDocumentReady(MediaMimeType::Png, 4_096, $this->now);
}

public function testADocumentCannotBeMarkedReadyAsAnImage(): void
{
    $media = $this->pendingMedia();
    $media->markUploaded($this->now);

    $this->expectException(\InvalidArgumentException::class);

    $media->markImageReady(MediaMimeType::Text, 10, 10, 4_096, StorageKey::forThumbnail($media->id()), $this->now);
}
```

> `MediaMimeType::Text` n'existe pas encore. **C'est normal** : c'est le test qui force son
> introduction. L'étape 3 ajoute le cas d'enum, la tâche 4 l'ouvre à l'API.

Adapter `$this->pendingMedia()` et `$this->now` au style réellement présent dans le fichier — le lire
avant d'écrire.

- [ ] **Step 2: Voir les tests échouer**

```bash
make unit-test ARGS="--filter=MediaObjectTest"
```

Attendu : ERROR sur `MediaMimeType::Text` (cas d'enum inconnu).

- [ ] **Step 3: Créer `MediaFamily` et `family()`**

Créer `backend/src/Media/Domain/MediaFamily.php` :

```php
<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Ce que les octets suffisent a etablir. Le sous-type, lui, ne l'est pas
 * toujours : text/plain, text/csv et text/markdown partagent les memes
 * octets — voir MediaMimeType::covers().
 *
 * Deux cas et pas trois : Text et Pdf partagent exactement le meme
 * traitement — aucune mesure, aucune miniature, telechargement en
 * attachment. Les distinguer ici n'aurait aucun consommateur.
 */
enum MediaFamily
{
    case Image;
    case Document;
}
```

Dans `MediaMimeType`, ajouter **le seul cas nécessaire aux tests de cette tâche** — `Text` — puis :

```php
public function family(): MediaFamily
{
    return match ($this) {
        self::Jpeg, self::Png, self::Webp, self::Gif => MediaFamily::Image,
        self::Text => MediaFamily::Document,
    };
}
```

et son extension dans `extension()` : `self::Text => 'txt'`.

> Le cas `Text` est ajouté ici mais **`values()` le rend déjà**, donc `Assert\Choice` l'accepterait.
> C'est la seule fenêtre où l'API accepte un type que le worker sait traiter mais que
> `StorageKey::PATTERN` refuse encore (`.txt` n'y est pas). Ajouter `txt` au pattern **dans cette
> même étape** pour ne pas laisser le dépôt dans cet état intermédiaire :
> `…\.(jpg|png|webp|gif|txt)\z/`.

- [ ] **Step 4: Écrire les deux transitions**

Dans `MediaObject`, remplacer `markReady()` par :

```php
public function markImageReady(
    MediaMimeType $mimeType,
    int $width,
    int $height,
    int $byteSize,
    StorageKey $thumbnailKey,
    \DateTimeImmutable $now,
): void {
    if (MediaFamily::Image !== $mimeType->family()) {
        throw new \InvalidArgumentException('Un media qui n\'est pas une image ne peut pas etre mesure.');
    }

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

/**
 * Pas de dimensions, pas de miniature : un PDF n'en a pas. Deux methodes
 * plutot qu'un markReady() a parametres nullables — un nullable dirait
 * « ces valeurs sont parfois absentes », deux methodes disent « il y a deux
 * formes de media pret, et chacune a ses obligations ».
 */
public function markDocumentReady(MediaMimeType $mimeType, int $byteSize, \DateTimeImmutable $now): void
{
    if (MediaFamily::Document !== $mimeType->family()) {
        throw new \InvalidArgumentException('Une image ne peut pas etre marquee prete comme un document.');
    }

    $this->guardNotTerminal(MediaStatus::Ready);

    $this->status = MediaStatus::Ready;
    $this->mimeType = $mimeType;
    $this->byteSize = $byteSize;
    $this->processedAt = $now;

    $this->recordEvent(new MediaWasProcessed(
        $this->id, $this->status->value, $mimeType->value, null, null, $byteSize, $now,
    ));
}
```

- [ ] **Step 5: Voir les tests passer**

```bash
make unit-test ARGS="--filter=MediaObjectTest"
```

Attendu : PASS. Renommer aussi l'appel dans `ProcessMediaCommandHandler` (`markReady` →
`markImageReady`) pour que la compilation tienne.

- [ ] **Step 6: Écrire le test de la contrainte SQL**

Créer `backend/tests/Functional/Media/MediaReadyConstraintTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * La contrainte est BILATERALE, et c'est le point : elle interdit non
 * seulement une image prete sans dimensions — le bug que le placeholder a
 * proportions du front ne saurait pas gerer — mais aussi un document pret
 * AVEC une miniature, qui signalerait que le worker a pris la mauvaise
 * branche. Une contrainte qui n'interdit que la moitie des etats impossibles
 * n'est qu'un commentaire.
 */
final class MediaReadyConstraintTest extends /* la classe de base fonctionnelle du depot */
{
    public function testAReadyImageWithoutDimensionsIsRefused(): void
    {
        $this->expectException(DriverException::class);

        $this->insertMedia(status: 'ready', mimeType: 'image/png', width: null, height: null, thumbnailKey: 'media/…-thumb.jpg');
    }

    public function testAReadyDocumentCarryingAThumbnailIsRefused(): void
    {
        $this->expectException(DriverException::class);

        $this->insertMedia(status: 'ready', mimeType: 'text/plain', width: null, height: null, thumbnailKey: 'media/…-thumb.jpg');
    }

    public function testAReadyDocumentWithoutDimensionsIsAccepted(): void
    {
        $this->insertMedia(status: 'ready', mimeType: 'text/plain', width: null, height: null, thumbnailKey: null);

        $this->addToAssertionCount(1);
    }
}
```

Lire `tests/Functional/Media/MediaRepositoryTest.php` pour la classe de base et l'accès à la
`Connection`, et écrire `insertMedia()` en `INSERT` SQL littéral avec paramètres liés — pas de
QueryBuilder, conformément à `CLAUDE.md`.

- [ ] **Step 7: Voir le test échouer**

```bash
make functional-test ARGS="--filter=MediaReadyConstraintTest"
```

Attendu : `testAReadyDocumentWithoutDimensionsIsAccepted` **échoue** — la contrainte actuelle exige
`width`, `height` et `thumbnail_key` sur tout `ready`.

- [ ] **Step 8: Migrer la contrainte**

```bash
make generate-migration
```

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE media_objects DROP CONSTRAINT media_ready_is_measured');
    $this->addSql(<<<'SQL'
        ALTER TABLE media_objects ADD CONSTRAINT media_ready_is_measured CHECK (
            status <> 'ready'
            OR (
                mime_type IS NOT NULL
                AND byte_size IS NOT NULL
                AND (
                    (mime_type LIKE 'image/%'
                        AND width IS NOT NULL AND height IS NOT NULL AND thumbnail_key IS NOT NULL)
                    OR
                    (mime_type NOT LIKE 'image/%'
                        AND width IS NULL AND height IS NULL AND thumbnail_key IS NULL)
                )
            )
        )
        SQL);
}
```

`down()` restaure la contrainte de la tranche 4 (la recopier depuis `Version20260726144434.php`).

```bash
make migrate
```

- [ ] **Step 9: Voir les tests passer**

```bash
make functional-test ARGS="--filter=MediaReadyConstraintTest"
```

Attendu : PASS, trois tests.

- [ ] **Step 10: Brancher le handler sur la famille**

Dans `ProcessMediaCommandHandler`, après la détection réussie, remplacer le bloc mesure + miniature
par un aiguillage :

```php
if (MediaFamily::Document === $mimeType->family()) {
    $media->markDocumentReady($mimeType, $byteSize, $now);
    $this->media->save($media);

    $this->logger->info('Media {media_id} pret', [
        'media_id' => $media->id()->toString(),
        'mime_type' => $mimeType->value,
        'byte_size' => $byteSize,
    ]);

    return;
}
```

placé **avant** l'appel à `measure()`. Le `finally` de nettoyage s'exécute quand même — c'est tout
l'intérêt d'un `return` dans un `try`.

- [ ] **Step 11: Corriger le témoin de « servable » dans le finder**

Indispensable **ici** et pas plus tard : dès cette tâche, `values()` rend `text/plain`, donc l'API
accepte un `.txt` et le worker le marque `ready`. Or `DbalMediaFinder` se sert de `thumbnail_key`
comme témoin de « on peut signer une URL » — et un document prêt n'en a pas. Sans cette étape, un
texte téléversé ressortirait `ready` avec `url: null`, servable par personne.

```php
$mimeType = null === $row['mime_type'] ? null : MediaMimeType::from($row['mime_type']);

// La miniature ne peut plus servir de temoin : un document pret n'en a pas.
// C'est le statut qui tranche, et le CHECK media_ready_is_measured garantit
// qu'une ligne `ready` porte tout ce que sa famille exige.
$isServable = MediaStatus::Ready === $status;

$disposition = MediaFamily::Document === $mimeType?->family()
    ? MediaDisposition::Attachment
    : MediaDisposition::Inline;

$views[$row['id']] = new MediaView(
    $row['id'],
    $status->value,
    $isServable ? $row['mime_type'] : null,
    $isServable ? $row['width'] : null,
    $isServable ? $row['height'] : null,
    $isServable
        ? $this->storage->presignDownload(StorageKey::fromString($row['storage_key']), $disposition, $filename, $now)
        : null,
    // Reste conditionne a la miniature ELLE-MEME : elle est nulle pour tout
    // document, et le front s'en sert pour choisir son rendu.
    $isServable && null !== $row['thumbnail_key']
        ? $this->storage->presignDownload(StorageKey::fromString($row['thumbnail_key']), MediaDisposition::Inline, null, $now)
        : null,
    $filename->toString(),
);
```

> **Relire le commentaire existant de `DbalMediaFinder` (lignes 60-70) avant de le remplacer.** Il
> explique que la miniature sert de témoin unique — c'est précisément ce qui cesse d'être vrai. Un
> commentaire obsolète se met à jour ou se supprime, il ne se laisse pas mentir.

Ajouter à `tests/Functional/Media/MediaRepositoryTest.php` (ou au fichier fonctionnel le plus proche)
un cas : un média `ready` de type `text/plain`, sans miniature, ressort avec `url` non nul,
`thumbnail_url` nul, `width` et `height` nuls.

- [ ] **Step 12: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat(medias): distinguer image et document dans l'agregat"
```

---

## Task 4 : accepter txt, csv, md et pdf

**C'est ici que l'allowlist s'ouvre**, et pas avant : aucune tâche antérieure n'accepte un type que
le worker ne saurait traiter.

**Files:**
- Modify: `backend/src/Media/Domain/MediaMimeType.php`
- Modify: `backend/src/Media/Domain/StorageKey.php`
- Modify: `backend/src/Media/Infrastructure/File/FinfoMimeTypeDetector.php`
- Modify: `backend/src/Media/Infrastructure/Contract/DbalMediaFinder.php`
- Modify: `backend/src/Media/Application/Command/ProcessMediaCommandHandler.php`
- Modify: `backend/tests/Unit/Media/Domain/MediaMimeTypeTest.php`
- Modify: `backend/tests/Unit/Media/Domain/StorageKeyTest.php`
- Modify: `backend/tests/Unit/Media/Infrastructure/FinfoMimeTypeDetectorTest.php`
- Modify: `backend/tests/Unit/Media/Application/Command/ProcessMediaCommandHandlerTest.php`
- Modify: `backend/tests/Functional/Media/UploadFlowTest.php`

**Interfaces:**
- Consumes: `MediaFamily`, `MediaMimeType::family()`, les deux transitions (tâche 3) ;
  `OriginalFilename`, `MediaDisposition` (tâche 1).
- Produces: `MediaMimeType::covers(self $declared): bool`, et les cas `Csv`, `Markdown`, `Pdf`.

- [ ] **Step 1: Écrire le test de `covers()` et de l'allowlist**

Réécrire `backend/tests/Unit/Media/Domain/MediaMimeTypeTest.php` :

```php
public function testTheAllowlistIsFourImagesAndFourDocuments(): void
{
    self::assertSame(
        ['image/jpeg', 'image/png', 'image/webp', 'image/gif',
         'text/plain', 'text/csv', 'text/markdown', 'application/pdf'],
        MediaMimeType::values(),
    );
}

public function testZipIsNotAcceptable(): void
{
    // Ecarte explicitement (spec §9) : application/zip n'identifie pas un
    // fichier zip, c'est le conteneur de .docx, .jar et .apk.
    /** @var list<string> $outsideTheAllowlist */
    $outsideTheAllowlist = ['application/zip', 'image/svg+xml', 'text/html'];

    foreach ($outsideTheAllowlist as $mimeType) {
        self::assertNull(MediaMimeType::tryFrom($mimeType));
    }
}

public function testEachTypeKnowsItsExtension(): void
{
    self::assertSame('jpg', MediaMimeType::Jpeg->extension());
    self::assertSame('png', MediaMimeType::Png->extension());
    self::assertSame('webp', MediaMimeType::Webp->extension());
    self::assertSame('gif', MediaMimeType::Gif->extension());
    self::assertSame('txt', MediaMimeType::Text->extension());
    self::assertSame('csv', MediaMimeType::Csv->extension());
    self::assertSame('md', MediaMimeType::Markdown->extension());
    self::assertSame('pdf', MediaMimeType::Pdf->extension());
}

public function testEachTypeKnowsItsFamily(): void
{
    self::assertSame(MediaFamily::Image, MediaMimeType::Jpeg->family());
    self::assertSame(MediaFamily::Image, MediaMimeType::Gif->family());
    self::assertSame(MediaFamily::Document, MediaMimeType::Text->family());
    self::assertSame(MediaFamily::Document, MediaMimeType::Csv->family());
    self::assertSame(MediaFamily::Document, MediaMimeType::Markdown->family());
    self::assertSame(MediaFamily::Document, MediaMimeType::Pdf->family());
}

public function testTextCoversItsThreeIndistinguishableSubtypes(): void
{
    // RFC 4180 decrit une grammaire sans en-tete, RFC 7763 §1.1 pose que la
    // source Markdown reste du texte lisible. Aucun nombre magique n'existe,
    // et il n'en existera pas : c'est la SEULE exception a l'egalite.
    self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Text));
    self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Csv));
    self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Markdown));
}

public function testCoveringIsNotSymmetric(): void
{
    self::assertFalse(MediaMimeType::Csv->covers(MediaMimeType::Text));
    self::assertFalse(MediaMimeType::Markdown->covers(MediaMimeType::Csv));
}

public function testEveryOtherPairIsPlainEquality(): void
{
    foreach (MediaMimeType::cases() as $measured) {
        foreach (MediaMimeType::cases() as $declared) {
            $exception = MediaMimeType::Text === $measured
                && \in_array($declared, [MediaMimeType::Text, MediaMimeType::Csv, MediaMimeType::Markdown], true);

            self::assertSame(
                $measured === $declared || $exception,
                $measured->covers($declared),
                sprintf('%s couvre %s ?', $measured->value, $declared->value),
            );
        }
    }
}
```

- [ ] **Step 2: Voir les tests échouer**

```bash
make unit-test ARGS="--filter=MediaMimeTypeTest"
```

Attendu : ERROR sur `MediaMimeType::Csv`.

- [ ] **Step 3: Compléter l'enum**

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

    // …values() inchangee…

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Gif => 'gif',
            self::Text => 'txt',
            self::Csv => 'csv',
            self::Markdown => 'md',
            self::Pdf => 'pdf',
        };
    }

    public function family(): MediaFamily
    {
        return match ($this) {
            self::Jpeg, self::Png, self::Webp, self::Gif => MediaFamily::Image,
            self::Text, self::Csv, self::Markdown, self::Pdf => MediaFamily::Document,
        };
    }

    /**
     * Les octets MESURES autorisent-ils ce que le client a DECLARE ?
     *
     * L'egalite, toujours — et cette seule exception : Text couvre Csv et
     * Markdown, parce qu'aucun octet ne les distingue. C'est le point unique
     * du projet ou l'on admet que les octets ne tranchent pas. L'isoler dans
     * une methode nommee vaut mieux que de le laisser fuir dans un `if`.
     */
    public function covers(self $declared): bool
    {
        if ($this === $declared) {
            return true;
        }

        return self::Text === $this
            && \in_array($declared, [self::Csv, self::Markdown], true);
    }
}
```

`StorageKey::PATTERN` devient :

```php
private const string PATTERN = '/\Amedia\/[0-7][0-9A-HJKMNP-TV-Z]{25}(-thumb)?\.(jpg|png|webp|gif|txt|csv|md|pdf)\z/';
```

- [ ] **Step 4: Voir les tests passer**

```bash
make unit-test ARGS="--filter=MediaMimeTypeTest"
```

Ajouter aussi à `StorageKeyTest` un cas par nouvelle extension, plus le rejet de `media/01JX….exe`.

- [ ] **Step 5: Écrire le test de la table finfo et de la garde UTF-8**

Étendre le data provider de `FinfoMimeTypeDetectorTest` :

```php
yield 'pdf' => ['minimal.pdf', MediaMimeType::Pdf];
yield 'markdown' => ['notes.md', MediaMimeType::Text];
yield 'csv' => ['donnees.csv', MediaMimeType::Text];
// La garde UTF-8 le sauve : finfo le classe text/x-shellscript, mais ses
// octets sont du texte valide. Sans elle, un .txt banal serait rejete.
yield 'texte a shebang' => ['script-comme-texte.txt', MediaMimeType::Text];
// Un octet NUL n'est pas du texte, quoi qu'en dise libmagic.
yield 'texte avec octet nul' => ['avec-nul.txt', MediaRejectionReason::UnsupportedType];
// LE test de securite : du HTML servi en same-origin s'executerait.
yield 'html deguise en txt' => ['deguise.txt', MediaRejectionReason::UnsupportedType];
```

- [ ] **Step 6: Voir le test échouer**

```bash
make unit-test ARGS="--filter=FinfoMimeTypeDetectorTest"
```

Attendu : FAIL sur `markdown`, `csv`, `texte a shebang` (rendus `UnsupportedType` par la table
littérale actuelle) et `html deguise en txt` (rendu `Text` — c'est le cas dangereux).

- [ ] **Step 7: Écrire la table et la garde**

```php
final readonly class FinfoMimeTypeDetector implements MimeTypeDetectorInterface
{
    /**
     * Les deux seuls types texte qu'un navigateur RENDRAIT au lieu de les
     * afficher. Ils restent exclus malgre la disposition `attachment` : la
     * disposition est une ligne de code qui peut regresser, l'allowlist est
     * la seconde barriere.
     */
    private const array RENDERABLE_TEXT = ['text/html', 'text/xml'];

    public function detect(string $localPath): MediaMimeType|MediaRejectionReason
    {
        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return MediaRejectionReason::UnsupportedType;
        }

        $exact = MediaMimeType::tryFrom($detected);

        // Un type texte reconnu tel quel (text/plain, text/csv si libmagic
        // le sait) passe quand meme par la garde : c'est elle qui verifie
        // que les octets SONT du texte.
        if (null !== $exact && MediaFamily::Image === $exact->family()) {
            return $exact;
        }

        if (MediaMimeType::Pdf === $exact) {
            return MediaMimeType::Pdf;
        }

        if (!str_starts_with($detected, 'text/') || \in_array($detected, self::RENDERABLE_TEXT, true)) {
            return MediaRejectionReason::UnsupportedType;
        }

        // finfo ne rend pas toujours text/plain sur du texte : un shebang
        // donne text/x-shellscript, du C donne text/x-c. Plutot qu'une
        // denylist, on VERIFIE les octets — c'est fidele a « les octets
        // decident », et ca vaut mieux qu'un parametre charset declaratif.
        return $this->isRealText($localPath) ? MediaMimeType::Text : MediaRejectionReason::UnsupportedType;
    }

    private function isRealText(string $localPath): bool
    {
        $bytes = @file_get_contents($localPath);

        if (false === $bytes) {
            return false;
        }

        return !str_contains($bytes, "\0") && mb_check_encoding($bytes, 'UTF-8');
    }
}
```

> Lire le fichier entier est sans danger **parce que** le handler a déjà rejeté tout ce qui dépasse
> `MediaObject::MAX_BYTES` (tâche 2, étape 7). L'ordre est un invariant, pas un détail.

- [ ] **Step 8: Voir le test passer**

```bash
make unit-test ARGS="--filter=FinfoMimeTypeDetectorTest"
```

Attendu : PASS, neuf cas.

- [ ] **Step 9: Écrire le test de la règle de désaccord**

Ajouter à `ProcessMediaCommandHandlerTest` :

```php
public function testACrossFamilyMismatchIsRejected(): void
{
    // Declare application/pdf, octets de texte. Nouveau par rapport a la
    // tranche 4 : la cle de stockage porte `.pdf` et le
    // Content-Disposition servirait le fichier sous un nom qui ment sur son
    // contenu. On ne sert pas un fichier dont on sait que l'extension est
    // fausse.
    self::assertSame(MediaStatus::Rejected, $media->status());
    self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());
}

public function testASameFamilyMismatchIsOnlyWarned(): void
{
    // Declare image/jpeg, octets PNG : comportement tranche 4 inchange.
    self::assertSame(MediaStatus::Ready, $media->status());
    self::assertSame(MediaMimeType::Png, $media->mimeType());
    self::assertCount(1, $logger->recordsAtLevel(LogLevel::WARNING));
}

public function testACoveredSubtypeIsAcceptedSilently(): void
{
    // Declare text/markdown, mesure text/plain : cas NOMINAL des trois types
    // texte. Un warning ici remplirait le journal de bruit non actionnable,
    // exactement ce que « warning doit etre actionnable » interdit.
    self::assertSame(MediaStatus::Ready, $media->status());
    self::assertSame(MediaMimeType::Text, $media->mimeType());
    self::assertCount(0, $logger->recordsAtLevel(LogLevel::WARNING));
}
```

Adapter aux doublures réellement présentes dans le fichier.

- [ ] **Step 10: Écrire la règle dans le handler**

Remplacer la comparaison `$mimeType !== $media->declaredMimeType()` par :

```php
$declared = $media->declaredMimeType();

if ($mimeType->family() !== $declared->family()) {
    // Familles differentes : la cle de stockage porte deja l'extension du
    // declare, et le Content-Disposition servirait un nom qui ment. La
    // tranche 4 se contentait d'un warning parce que la consequence etait
    // benigne — une image restait une image. Ici elle ne l'est plus.
    $this->reject($media, MediaRejectionReason::UnsupportedType, $now, eraseBytes: true);

    return;
}

if (!$mimeType->covers($declared)) {
    // Meme famille, sous-type different (jpeg declare, png reel) :
    // signal actionnable, mais pas de refus — comportement tranche 4.
    $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
        'media_id' => $media->id()->toString(),
        'declared_mime_type' => $declared->value,
        'actual_mime_type' => $mimeType->value,
    ]);
}
```

placé **juste après la détection réussie**, avant l'aiguillage par famille — sinon un PDF déclaré
comme du texte produirait une miniature avant d'être refusé.

- [ ] **Step 11: Test fonctionnel de bout en bout**

Ajouter à `backend/tests/Functional/Media/UploadFlowTest.php` un scénario complet pour un `.md` :
pré-signature (201 avec `content_type: "text/markdown"`), `PUT` des octets, confirmation, traitement,
puis lecture — `status: ready`, `mime_type: "text/plain"`, `width` et `height` à `null`,
`thumbnail_url` à `null`, `url` contenant `attachment` une fois décodée, et `filename` égal à
`notes.md`.

Ajouter aussi le refus :

```php
public function testZipIsRefusedAtPresign(): void
{
    // 422 avec la violation nommant le champ, pas un 500 ni un message
    // ad hoc : c'est la discipline RFC 7807 du projet.
    $this->client->request('POST', '/api/media', content: json_encode([
        'filename' => 'archive.zip',
        'content_type' => 'application/zip',
        'size' => 1024,
    ]));

    self::assertResponseStatusCodeSame(422);
    self::assertSame('content_type', $this->violations()[0]['field']);
}
```

Suivre le style d'assertion réellement utilisé dans `tests/Functional/` pour les violations.

- [ ] **Step 12: Les quatre portes**

```bash
make static-code-analysis && make check-cs && make deptrac && make test
```

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat(medias): accepter txt, csv, md et pdf"
```

---

## Task 5 : joindre des documents (front)

Nicolas est novice côté front : **commenter généreusement** le *pourquoi*, pas le *quoi*.

**Files:**
- Create: `frontend/src/api/declaredType.ts`
- Create: `frontend/src/api/declaredType.test.ts`
- Modify: `frontend/src/api/upload.ts`
- Modify: `frontend/src/api/types.ts`
- Modify: `frontend/src/hooks/useMediaUpload.ts`
- Modify: `frontend/src/store/messagesReducer.ts`
- Modify: `frontend/src/ui/MessageMedia.tsx`
- Modify: `frontend/src/ui/MessageMedia.test.tsx`
- Modify: `frontend/src/ui/Composer.tsx`

**Interfaces:**
- Consumes: le champ `filename` de `ApiMedia` (tâche 1), l'allowlist backend (tâche 4).
- Produces:
  - `declaredTypeFor(file: File): string | null`
  - `putBytes(uploadUrl: string, file: File, contentType: string): Promise<void>`
  - `StoredMedia.filename: string`

- [ ] **Step 1: Écrire le test de `declaredTypeFor`**

Créer `frontend/src/api/declaredType.test.ts` :

```ts
import { describe, expect, it } from 'vitest';
import { declaredTypeFor } from './declaredType';

/** `File` de test : le contenu importe peu, seuls le nom et le type comptent. */
function fileNamed(name: string, type: string): File {
  return new File(['x'], name, { type });
}

describe('declaredTypeFor', () => {
  it("déduit text/markdown d'un .md même quand le système ne connaît pas le type", () => {
    // C'est le cas le plus fréquent : aucune entrée `.md` dans la base MIME
    // du système, donc `file.type` vaut la chaîne vide. Se fier à lui
    // enverrait un content_type vide, que le backend refuse en 422.
    expect(declaredTypeFor(fileNamed('notes.md', ''))).toBe('text/markdown');
  });

  it("ignore le type du système quand l'extension est plus fiable", () => {
    // Avec Excel installé, un .csv sort en application/vnd.ms-excel.
    expect(declaredTypeFor(fileNamed('donnees.csv', 'application/vnd.ms-excel'))).toBe('text/csv');
  });

  it('reconnaît .txt et .pdf', () => {
    expect(declaredTypeFor(fileNamed('notes.txt', 'text/plain'))).toBe('text/plain');
    expect(declaredTypeFor(fileNamed('rapport.pdf', 'application/pdf'))).toBe('application/pdf');
  });

  it("se rabat sur file.type pour les images, dont aucun système ne se trompe", () => {
    expect(declaredTypeFor(fileNamed('photo.PNG', 'image/png'))).toBe('image/png');
    expect(declaredTypeFor(fileNamed('anim.gif', 'image/gif'))).toBe('image/gif');
  });

  it('refuse ce qui sort de l\'allowlist', () => {
    expect(declaredTypeFor(fileNamed('archive.zip', 'application/zip'))).toBeNull();
    expect(declaredTypeFor(fileNamed('setup.exe', 'application/x-msdownload'))).toBeNull();
  });

  it('refuse un dossier glissé, qui arrive sans type', () => {
    // Un dossier apparaît dans DataTransfer.files avec un type vide et une
    // taille arbitraire. C'est un cas que l'utilisateur produira.
    expect(declaredTypeFor(fileNamed('Documents', ''))).toBeNull();
  });
});
```

- [ ] **Step 2: Voir le test échouer**

```bash
make front-test
```

Attendu : FAIL, module `./declaredType` introuvable.

- [ ] **Step 3: Écrire la fonction**

Créer `frontend/src/api/declaredType.ts` :

```ts
/**
 * Quel type MIME déclarer au backend pour ce fichier.
 *
 * ## Pourquoi ne pas simplement lire `file.type`
 *
 * `file.type` vient de la base MIME du système d'exploitation, pas des
 * octets. Pour une image, c'est fiable : aucun système ne se trompe sur un
 * JPEG. Pour les documents, non :
 *
 *  - `.md` n'a souvent aucune entrée → `file.type` vaut `''`. Un
 *    `content_type` vide échoue sur la contrainte backend → 422 ;
 *  - `.csv` sort en `application/vnd.ms-excel` quand Excel est installé.
 *
 * On déduit donc le type de l'EXTENSION pour les quatre types documentaires,
 * et on ne se rabat sur `file.type` que pour les images.
 *
 * ## Ce que cette fonction n'est pas
 *
 * Ce n'est pas une validation de sécurité. Le client déclare, le serveur
 * mesure les octets et tranche. Ici on évite seulement un aller-retour
 * réseau voué à finir en 422, et on produit un refus lisible tout de suite.
 */

const BY_EXTENSION: Record<string, string> = {
  txt: 'text/plain',
  csv: 'text/csv',
  md: 'text/markdown',
  pdf: 'application/pdf',
};

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

export function declaredTypeFor(file: File): string | null {
  const dot = file.name.lastIndexOf('.');
  const extension = dot === -1 ? '' : file.name.slice(dot + 1).toLowerCase();

  const fromExtension = BY_EXTENSION[extension];
  if (fromExtension !== undefined) {
    return fromExtension;
  }

  return IMAGE_TYPES.includes(file.type) ? file.type : null;
}

/** Pour l'attribut `accept` du sélecteur de fichiers, et pour les messages d'erreur. */
export const ACCEPTED_DESCRIPTION = 'images, txt, csv, md, pdf';
export const ACCEPT_ATTRIBUTE = 'image/*,.txt,.csv,.md,.pdf';
```

- [ ] **Step 4: Voir le test passer**

```bash
make front-test
```

- [ ] **Step 5: Faire circuler le type déclaré**

`frontend/src/api/upload.ts` — `putBytes` prend le type en paramètre :

```ts
export async function putBytes(uploadUrl: string, file: File, contentType: string): Promise<void> {
  const response = await fetch(uploadUrl, {
    method: 'PUT',
    credentials: 'omit',
    // Doit être EXACTEMENT le type déclaré à la pré-signature — et ce n'est
    // plus `file.type` : voir declaredTypeFor(). Envoyer autre chose donne
    // `SignatureDoesNotMatch`, une erreur S3 opaque.
    headers: { 'Content-Type': contentType },
    body: file,
  });

  if (!response.ok) {
    throw new Error(`Le transfert a échoué (${response.status}).`);
  }
}
```

`frontend/src/hooks/useMediaUpload.ts` (~ligne 88) :

```ts
const contentType = declaredTypeFor(file);

if (contentType === null) {
  // Refus LOCAL : aucun appel réseau pour un fichier qu'on sait refusé.
  markFailed(localId, `Type non accepté (${ACCEPTED_DESCRIPTION}).`);
  return;
}

const ticket = await api.presignUpload(file.name, contentType, file.size);
// …
await putBytes(ticket.upload_url, file, contentType);
```

Adapter `markFailed` au mécanisme d'erreur réellement présent dans le hook — le lire avant d'écrire.

**`previewUrl` ne se crée que pour une image** : `URL.createObjectURL` sur un PDF produit une URL
valide mais rien ne l'affichera, et la mémoire resterait retenue pour rien. Conditionner la création
à `contentType.startsWith('image/')` et laisser `previewUrl` à `null` sinon.

- [ ] **Step 6: Étendre les types du store**

`frontend/src/api/types.ts` — `ApiMedia` gagne `filename: string`, et le commentaire de bloc au-dessus
mentionne que `ready` ne signifie plus « miniature disponible » mais « validé ; miniature pour les
images seulement ».

`frontend/src/store/messagesReducer.ts` — `StoredMedia` gagne `filename: string`. Mettre à jour le
mapping `ApiMedia → StoredMedia` (chercher où `thumbnailUrl` est renseigné) et les fabriques des
tests.

- [ ] **Step 7: Écrire le test du rendu document**

Ajouter à `frontend/src/ui/MessageMedia.test.tsx` :

```tsx
it('affiche une pièce jointe nommée pour un document prêt, pas une image', () => {
  render(<MessageMedia media={documentReady({ filename: 'rapport.pdf' })} onExpired={() => {}} />);

  expect(screen.getByText('rapport.pdf')).toBeInTheDocument();
  expect(screen.queryByRole('img')).not.toBeInTheDocument();
});

it("garde la même hauteur entre l'attente et l'affichage d'un document", () => {
  // Pas de saut de mise en page à traiter : contrairement à une image, la
  // hauteur d'une pièce jointe ne dépend pas de son contenu.
  const { container: waiting } = render(<MessageMedia media={documentProcessing()} onExpired={() => {}} />);
  const { container: ready } = render(<MessageMedia media={documentReady()} onExpired={() => {}} />);

  expect(waiting.firstElementChild?.className).toContain('h-14');
  expect(ready.firstElementChild?.className).toContain('h-14');
});

it("n'affiche aucun aperçu local pour un document en cours", () => {
  // L'expéditeur d'un PDF voit la même chose que les autres : il n'y a rien
  // à prévisualiser. C'est la différence avec le cas image.
  render(<MessageMedia media={documentProcessing()} onExpired={() => {}} />);

  expect(screen.queryByRole('img')).not.toBeInTheDocument();
  expect(screen.getByText(/en cours/i)).toBeInTheDocument();
});
```

Écrire les fabriques `documentReady()` / `documentProcessing()` dans le style des fabriques déjà
présentes dans le fichier.

- [ ] **Step 8: Voir le test échouer, puis écrire la branche document**

```bash
make front-test
```

Dans `MessageMedia.tsx`, insérer l'aiguillage **après** la branche `rejected` (réutilisée telle
quelle) et **avant** la branche image :

```tsx
/**
 * Document ou image ?
 *
 * `mimeType` est `null` tant que le worker n'a pas tranché — on se rabat
 * alors sur l'extension du nom, la seule information disponible avant
 * validation. C'est de l'affichage, pas de la sécurité : le serveur, lui,
 * a mesuré les octets.
 */
function isDocument(media: StoredMedia): boolean {
  if (media.mimeType !== null) {
    return !media.mimeType.startsWith('image/');
  }

  return /\.(txt|csv|md|pdf)$/i.test(media.filename);
}
```

```tsx
if (isDocument(media)) {
  const ready = media.status === 'ready' && media.url !== null;

  // Hauteur FIXE, identique entre l'attente et l'affichage. Tout le
  // raisonnement `aspectRatio` de la branche image est sans objet ici :
  // la hauteur d'une pièce jointe ne dépend pas de son contenu, donc il
  // n'y a aucun saut de mise en page à prévenir.
  return (
    <div className="mt-2 flex h-14 w-64 items-center gap-3 rounded border border-slate-200 bg-slate-50 px-3">
      <span aria-hidden="true" className="text-xl">
        📄
      </span>
      <div className="min-w-0 flex-1">
        {/* `truncate` : un nom de 255 caractères ne doit pas élargir la bulle. */}
        <div className="truncate text-sm text-slate-800">{media.filename}</div>
        {ready ? (
          <a
            href={media.url ?? undefined}
            // Pas de `target="_blank"` : le serveur sert ces octets en
            // `Content-Disposition: attachment`, donc le navigateur
            // télécharge sans naviguer. Un nouvel onglet s'ouvrirait et
            // se refermerait aussitôt.
            className="text-xs text-blue-600 underline"
            onError={onExpired}
          >
            Télécharger
          </a>
        ) : (
          // L'expéditeur voit la même chose que les autres : contrairement
          // à une image, il n'y a rien à prévisualiser localement.
          <div className="text-xs text-slate-500">Fichier en cours de traitement…</div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 9: Étendre le sélecteur de fichiers**

`frontend/src/ui/Composer.tsx:125` :

```tsx
accept={ACCEPT_ATTRIBUTE}
```

en important la constante depuis `../api/declaredType`. `accept` est une **commodité** du sélecteur,
jamais une validation : `declaredTypeFor` reste la porte.

- [ ] **Step 10: Les portes front et back**

```bash
make front-test && make front-typecheck && make test
```

- [ ] **Step 11: Vérifier dans un vrai navigateur**

Redémarrer le conteneur frontend (Vite sert du code périmé après une opération git) :

```bash
make restart SERVICE=frontend
```

Puis, sur `localhost:8080` : joindre un `.md`, un `.pdf` et une image dans la même conversation.
Vérifier que le PDF **se télécharge** sous son nom au lieu de s'ouvrir, que l'image s'affiche
toujours en miniature, et que le second onglet d'un autre utilisateur reçoit bien les trois.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "feat(front): joindre des documents"
```

---

## Task 6 : déposer un fichier par glisser-déposer

**Files:**
- Create: `frontend/src/ui/DropOverlay.tsx`
- Create: `frontend/src/hooks/useFileDrop.ts`
- Create: `frontend/src/hooks/useFileDrop.test.ts`
- Modify: `frontend/src/ui/ConversationView.tsx`
- Modify: `frontend/src/ui/ConversationView.test.tsx`

**Interfaces:**
- Consumes: `declaredTypeFor`, `ACCEPTED_DESCRIPTION` (tâche 5), le point d'entrée d'ajout de
  fichiers de `useMediaUpload` (tâche 5).
- Produces: `useFileDrop({ onFiles })` → `{ isDragging, handlers }`, où `handlers` s'étale sur
  l'élément conteneur (`onDragEnter`, `onDragOver`, `onDragLeave`, `onDrop`).

- [ ] **Step 1: Écrire les tests des trois pièges**

Créer `frontend/src/hooks/useFileDrop.test.ts` :

```ts
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useFileDrop } from './useFileDrop';

/** Événement de dépôt minimal : seuls `preventDefault` et `files` comptent. */
function dropEvent(files: File[]) {
  return {
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
    dataTransfer: { files, items: files.map(() => ({ kind: 'file' })) },
  } as unknown as React.DragEvent;
}

describe('useFileDrop', () => {
  it('empêche le navigateur de quitter l\'application pour ouvrir le fichier', () => {
    // Sans preventDefault sur `drop`, le navigateur navigue vers le fichier :
    // l'onglet est perdu, le brouillon avec. C'est la régression la plus
    // coûteuse et la plus facile à introduire.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));
    const event = dropEvent([new File(['x'], 'a.pdf', { type: 'application/pdf' })]);

    act(() => result.current.handlers.onDrop(event));

    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('empêche aussi le navigateur de refuser le dépôt', () => {
    // Sans preventDefault sur `dragover`, `drop` ne se déclenche jamais.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));
    const event = dropEvent([]);

    act(() => result.current.handlers.onDragOver(event));

    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('ne clignote pas quand le curseur passe sur un enfant', () => {
    // dragenter/dragleave se déclenchent aussi sur les enfants. Un booléen
    // naïf ferait disparaître le voile au premier enfant survolé. On compte
    // la profondeur.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));

    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDragLeave(dropEvent([])));

    expect(result.current.isDragging).toBe(true);

    act(() => result.current.handlers.onDragLeave(dropEvent([])));

    expect(result.current.isDragging).toBe(false);
  });

  it('remet le compteur à zéro au dépôt', () => {
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));

    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDrop(dropEvent([])));

    expect(result.current.isDragging).toBe(false);
  });

  it('transmet les fichiers déposés', () => {
    const onFiles = vi.fn();
    const { result } = renderHook(() => useFileDrop({ onFiles }));
    const file = new File(['x'], 'notes.md', { type: '' });

    act(() => result.current.handlers.onDrop(dropEvent([file])));

    expect(onFiles).toHaveBeenCalledWith([file]);
  });
});
```

- [ ] **Step 2: Voir les tests échouer**

```bash
make front-test
```

- [ ] **Step 3: Écrire le hook**

Créer `frontend/src/hooks/useFileDrop.ts`. Les trois points à commenter, parce qu'ils sont
contre-intuitifs :

1. `preventDefault()` sur `dragover` **et** `drop` ;
2. le compteur de profondeur plutôt qu'un booléen ;
3. la remise à zéro sur `drop`, sinon le voile reste après un dépôt.

Le compteur vit dans un `useRef` (il ne doit pas déclencher de rendu à chaque `dragenter`) ; seul
`isDragging` est un `useState`.

- [ ] **Step 4: Voir les tests passer**

```bash
make front-test
```

- [ ] **Step 5: Écrire le voile**

Créer `frontend/src/ui/DropOverlay.tsx` : un `absolute inset-0` avec un fond translucide, une bordure
en pointillés et deux lignes de texte — « Déposez pour joindre » et `ACCEPTED_DESCRIPTION`.
`pointer-events-none` sur le contenu, pour que le voile ne vole pas les événements de dépôt au
conteneur.

- [ ] **Step 6: Écrire le test d'intégration dans la conversation**

Ajouter à `frontend/src/ui/ConversationView.test.tsx` :

```tsx
it('joint un fichier déposé sur la conversation', async () => {
  // La cible est toute la conversation, pas le composer : l'utilisateur ne
  // vise pas, il lâche.
  renderConversation();

  fireEvent.drop(screen.getByTestId('conversation'), {
    dataTransfer: { files: [new File(['x'], 'notes.md', { type: '' })] },
  });

  expect(await screen.findByText('notes.md')).toBeInTheDocument();
});

it("refuse un fichier non supporté sans aucun appel réseau", () => {
  const fetchSpy = vi.spyOn(globalThis, 'fetch');
  renderConversation();

  fireEvent.drop(screen.getByTestId('conversation'), {
    dataTransfer: { files: [new File(['x'], 'archive.zip', { type: 'application/zip' })] },
  });

  expect(screen.getByText(/type non accepté/i)).toBeInTheDocument();
  expect(fetchSpy).not.toHaveBeenCalled();
});
```

Adapter `renderConversation()` au harnais réellement présent dans le fichier.

- [ ] **Step 7: Brancher dans `ConversationView`**

Poser `useFileDrop` sur le conteneur de la conversation, étaler `handlers` dessus, ajouter `relative`
à sa classe et rendre `<DropOverlay />` quand `isDragging`. `onFiles` délègue au même point d'entrée
que le sélecteur de fichiers — **le glisser-déposer ne crée aucun second chemin d'envoi**.

- [ ] **Step 8: Voir les tests passer**

```bash
make front-test && make front-typecheck
```

- [ ] **Step 9: Vérifier dans un vrai navigateur**

```bash
make restart SERVICE=frontend
```

Sur `localhost:8080` : glisser un `.pdf` depuis le bureau sur le fil — le voile apparaît, le fichier
part. Glisser un `.zip` — message local, aucune requête dans l'onglet Réseau. Glisser un **dossier** —
refusé proprement. Puis, le test qui compte : **relâcher un fichier et vérifier que l'application ne
navigue pas**.

- [ ] **Step 10: Les quatre portes, plus le front**

```bash
make static-code-analysis && make check-cs && make deptrac && make test && make front-test && make front-typecheck
```

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat(front): deposer un fichier par glisser-deposer"
```

---

## Critères d'acceptation de la branche

Repris de la spec §*Critères d'acceptation*. Les vérifier **tous** avant d'ouvrir la PR :

1. Un `.md`, un `.csv`, un `.txt` et un `.pdf` se joignent et se téléchargent **sous leur nom
   d'origine**, accents compris.
2. Un `.html` renommé en `.txt` est **rejeté**, ses octets effacés du bucket, avec un `warning`.
3. Un `.zip` est refusé en **422** avec une violation nommant `content_type`.
4. Un PDF déclaré dont les octets sont du texte est rejeté ; un `.md` déclaré `text/markdown` et
   mesuré `text/plain` est accepté **sans aucun log `warning`**.
5. Les images de la tranche 4 fonctionnent exactement comme avant.
6. Un fichier glissé est joint ; un non supporté produit un message local **sans appel réseau** ;
   aucun dépôt ne fait quitter l'application.
7. En base, impossible d'écrire un document `ready` porteur d'une miniature, ou une image `ready`
   sans dimensions.
8. Les quatre portes vertes **à chacun des six commits**, pas seulement à la fin.
