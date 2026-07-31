import type { StoredMedia } from '../store/messagesReducer';

type Props = {
  media: StoredMedia;
  /** Rechargement de la page de messages, pour obtenir des URL fraîchement signées. */
  onExpired: () => void;
};

/**
 * Une piece jointe dans un fil : image ou document, aiguilles par `isDocument`.
 *
 * Pour une image, `processing` affiche un placeholder AUX PROPORTIONS de
 * l'image quand on les connait. Ce n'est pas de la coquetterie : sans hauteur
 * reservee, la liste saute au moment ou l'image arrive, et le lecteur perd sa
 * ligne. C'est le meme probleme que le decalage de mise en page sur une page
 * web lente. Un document, lui, garde une hauteur FIXE : rien ne depend de son
 * contenu, donc rien a reserver.
 *
 * `onError`/`onExpired` recharge la page de messages — pour une image
 * seulement. L'erreur attendue n'est PAS un fichier casse : c'est une URL
 * signee EXPIREE, dans un onglet reste ouvert plus de quinze minutes.
 * Recharger en obtient une fraiche. Un document n'a PAS ce filet : voir la
 * note sur le lien "Telecharger" plus bas.
 *
 * ## Chez l'expediteur, l'attente n'est pas vide — pour une image seulement
 *
 * Celui qui envoie possede deja les octets : `previewUrl` est sa `blob:` URL
 * locale, affichee pendant que le worker travaille. Les autres membres du fil
 * ne l'ont pas — eux voient le placeholder. C'est la meme image, a deux
 * moments differents de son voyage. Pour un document, il n'y a jamais
 * d'apercu local a afficher : `previewUrl` reste `null` meme chez
 * l'expediteur (voir `useMediaUpload`).
 */
/**
 * Document ou image ?
 *
 * `mimeType` est `null` tant que le worker n'a pas tranche — on se rabat
 * alors sur l'extension du nom, la seule information disponible avant
 * validation. C'est de l'affichage, pas de la securite : le serveur, lui,
 * a mesure les octets.
 */
function isDocument(media: StoredMedia): boolean {
  if (media.mimeType !== null) {
    return !media.mimeType.startsWith('image/');
  }

  return /\.(txt|csv|md|pdf)$/i.test(media.filename);
}

export function MessageMedia({ media, onExpired }: Props) {
  if (media.status === 'rejected') {
    // Un refus s'affiche, il ne s'escamote pas : sans cela, l'emplacement
    // resterait « en cours… » pour toujours et personne ne saurait pourquoi.
    return (
      <div className="mt-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
        Fichier refusé
      </div>
    );
  }

  if (isDocument(media)) {
    const ready = media.status === 'ready' && media.url !== null;

    // Hauteur FIXE, identique entre l'attente et l'affichage. Tout le
    // raisonnement `aspectRatio` de la branche image est sans objet ici :
    // la hauteur d'une piece jointe ne depend pas de son contenu, donc il
    // n'y a aucun saut de mise en page a prevenir.
    return (
      <div className="mt-2 flex h-14 w-64 items-center gap-3 rounded border border-slate-200 bg-slate-50 px-3">
        <span aria-hidden="true" className="text-xl">
          📄
        </span>
        <div className="min-w-0 flex-1">
          {/* `truncate` : un nom de 255 caracteres ne doit pas elargir la bulle. */}
          <div className="truncate text-sm text-slate-800">{media.filename}</div>
          {ready ? (
            <a
              href={media.url ?? undefined}
              // Pas de `target="_blank"` : le serveur sert ces octets en
              // `Content-Disposition: attachment`, donc le navigateur
              // telecharge sans naviguer. Un nouvel onglet s'ouvrirait et
              // se refermerait aussitot.
              //
              // Pas de `onError={onExpired}` ici, contrairement a l'`<img>`
              // de la branche image plus bas : un `<a>` n'emet aucun
              // evenement DOM `error` observable pour un telechargement qui
              // echoue (contrairement au chargement d'une ressource `<img>`
              // ou `<video>`). Si l'URL signee a expire, le clic se solde
              // par un telechargement rate, sans que cette page en soit
              // informee ni ne puisse se recharger automatiquement. Pas de
              // recuperation ici : c'est une limite connue, pas un oubli.
              className="text-xs text-blue-600 underline"
            >
              Télécharger
            </a>
          ) : (
            // L'expediteur voit la meme chose que les autres : contrairement
            // a une image, il n'y a rien a previsualiser localement.
            <div className="text-xs text-slate-500">Fichier en cours de traitement…</div>
          )}
        </div>
      </div>
    );
  }

  if (media.status === 'ready' && media.thumbnailUrl !== null && media.url !== null) {
    return (
      <a
        href={media.url}
        target="_blank"
        rel="noreferrer"
        className="mt-2 block w-fit overflow-hidden rounded"
      >
        <img
          src={media.thumbnailUrl}
          alt="Image jointe"
          // La miniature fait 400 px de large côté serveur : on la borne ici
          // pour qu'une bulle ne soit jamais plus large que le fil.
          className="max-h-64 max-w-full object-contain"
          onError={onExpired}
        />
      </a>
    );
  }

  // Tout le reste — `pending`, `processing`, ou un `ready` incomplet, qui ne
  // devrait pas exister mais dont on ne veut pas dépendre pour ne pas casser.
  return (
    <div className="mt-2 w-fit">
      {media.previewUrl !== null ? (
        <img
          src={media.previewUrl}
          alt="Image en cours d'envoi"
          // Estompée : elle est bien là, mais le serveur ne l'a pas encore
          // validée. L'expéditeur voit donc que quelque chose est en cours.
          className="max-h-64 max-w-full rounded object-contain opacity-60"
        />
      ) : (
        <div
          className="flex items-center justify-center rounded bg-slate-200 text-xs text-slate-500"
          // Proportions réservées quand on les connaît. `aspectRatio` plutôt
          // qu'une hauteur fixe : la largeur reste fluide, et le bloc occupe
          // exactement la place que l'image prendra.
          style={
            media.width !== null && media.height !== null
              ? { aspectRatio: `${media.width} / ${media.height}`, width: '16rem' }
              : { width: '16rem', height: '9rem' }
          }
        >
          Image en cours de traitement…
        </div>
      )}
    </div>
  );
}
