import type { StoredMedia } from '../store/messagesReducer';

type Props = {
  media: StoredMedia;
  /** Rechargement de la page de messages, pour obtenir des URL fraîchement signées. */
  onExpired: () => void;
};

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
 *
 * ## Chez l'expéditeur, l'attente n'est pas vide
 *
 * Celui qui envoie possède déjà les octets : `previewUrl` est sa `blob:` URL
 * locale, affichée pendant que le worker travaille. Les autres membres du fil
 * ne l'ont pas — eux voient le placeholder. C'est la même image, à deux
 * moments différents de son voyage.
 */
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
