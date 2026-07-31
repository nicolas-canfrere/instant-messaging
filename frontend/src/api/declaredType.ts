/**
 * Quel type MIME declarer au backend pour ce fichier.
 *
 * ## Pourquoi ne pas simplement lire `file.type`
 *
 * `file.type` vient de la base MIME du systeme d'exploitation, pas des
 * octets. Pour une image, c'est fiable : aucun systeme ne se trompe sur un
 * JPEG. Pour les documents, non :
 *
 *  - `.md` n'a souvent aucune entree -> `file.type` vaut `''`. Un
 *    `content_type` vide echoue sur la contrainte backend -> 422 ;
 *  - `.csv` sort en `application/vnd.ms-excel` quand Excel est installe.
 *
 * On deduit donc le type de l'EXTENSION pour les quatre types documentaires,
 * et on ne se rabat sur `file.type` que pour les images.
 *
 * ## Ce que cette fonction n'est pas
 *
 * Ce n'est pas une validation de securite. Le client declare, le serveur
 * mesure les octets et tranche. Ici on evite seulement un aller-retour
 * reseau voue a finir en 422, et on produit un refus lisible tout de suite.
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

/** Pour l'attribut `accept` du selecteur de fichiers, et pour les messages d'erreur. */
export const ACCEPTED_DESCRIPTION = 'images, txt, csv, md, pdf';
export const ACCEPT_ATTRIBUTE = 'image/*,.txt,.csv,.md,.pdf';
