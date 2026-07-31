import { useCallback, useEffect, useRef, useState } from 'react';
import { ulid } from 'ulid';
import { api } from '../api/client';
import { putBytes } from '../api/upload';

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
 *
 * ## Le troisième cas : le transfert de propriété
 *
 * Il y a un moment où l'on ne révoque SURTOUT pas : quand l'image part avec un
 * message. Son aperçu devient alors celui de la bulle, affiché jusqu'à ce que
 * le serveur annonce une vraie miniature (`message.media_ready`). Le révoquer
 * là casserait l'image sous les yeux de l'expéditeur.
 *
 * `takeUploaded()` matérialise ce transfert : il vide la liste **sans**
 * révoquer. À partir de là, l'aperçu appartient au store des messages, et ce
 * hook n'en répond plus.
 */

export type PendingUploadStatus = 'uploading' | 'uploaded' | 'failed';

export type PendingUpload = {
  /** Identifiant CLIENT, disponible dès le choix du fichier — l'id serveur, lui, n'arrive qu'après la pré-signature. */
  localId: string;
  fileName: string;
  previewUrl: string;
  status: PendingUploadStatus;
  /** Renseigné dès la pré-signature, donc avant même que les octets soient partis. */
  mediaId: string | null;
};

/** Ce qu'une image emporte avec elle quand elle part dans un message. */
export type TakenMedia = { mediaId: string; previewUrl: string };

export function useMediaUpload() {
  const [pending, setPending] = useState<PendingUpload[]>([]);

  // Miroir de l'état, pour que le nettoyage de démontage lise la liste COURANTE.
  // Sans lui, il faudrait mettre `pending` dans les dépendances du `useEffect`,
  // et le nettoyage se relancerait à chaque ajout — révoquant des aperçus encore
  // affichés. Un `useRef` change sans provoquer de rendu ni de réexécution.
  const currentRef = useRef<PendingUpload[]>([]);

  const replace = useCallback((next: (previous: PendingUpload[]) => PendingUpload[]) => {
    setPending((previous) => {
      const updated = next(previous);
      currentRef.current = updated;

      return updated;
    });
  }, []);

  const add = useCallback(
    async (file: File): Promise<void> => {
      const localId = ulid();
      const previewUrl = URL.createObjectURL(file);

      // L'entrée apparaît AVANT tout appel réseau : l'utilisateur voit sa
      // vignette dès qu'il a choisi son fichier, pas trois secondes plus tard.
      replace((previous) => [
        ...previous,
        { localId, fileName: file.name, previewUrl, status: 'uploading', mediaId: null },
      ]);

      try {
        const ticket = await api.presignUpload(file.name, file.type, file.size);

        // Les octets vont DIRECTEMENT au stockage : ils ne traversent jamais
        // notre backend.
        await putBytes(ticket.upload_url, file);

        // Le serveur n'a rien vu passer : sans cette confirmation, il ne saurait
        // pas qu'il y a des octets à inspecter, et le worker ne partirait jamais.
        await api.confirmUpload(ticket.media_id);

        replace((previous) =>
          previous.map((item) =>
            item.localId === localId
              ? { ...item, status: 'uploaded', mediaId: ticket.media_id }
              : item,
          ),
        );
      } catch {
        // On garde la vignette, marquée en erreur, plutôt que de la faire
        // disparaître : l'utilisateur doit comprendre que CETTE image n'est pas
        // partie, et pouvoir la retirer lui-même.
        replace((previous) =>
          previous.map((item) => (item.localId === localId ? { ...item, status: 'failed' } : item)),
        );
      }
    },
    [replace],
  );

  const remove = useCallback(
    (localId: string) => {
      const target = currentRef.current.find((item) => item.localId === localId);

      if (target !== undefined) {
        URL.revokeObjectURL(target.previewUrl);
      }

      replace((previous) => previous.filter((item) => item.localId !== localId));
    },
    [replace],
  );

  /**
   * Consomme les images prêtes à partir : rend ce qu'il faut pour construire le
   * message optimiste, puis vide la liste **sans révoquer** (cf. l'en-tête).
   *
   * Ce qui a échoué est simplement écarté : le serveur refuserait un média dont
   * les octets ne sont jamais arrivés, et l'utilisateur verrait un message
   * cassé plutôt qu'une vignette en erreur.
   */
  const takeUploaded = useCallback((): TakenMedia[] => {
    const taken = currentRef.current
      .filter((item): item is PendingUpload & { mediaId: string } =>
        item.status === 'uploaded' && item.mediaId !== null,
      )
      .map((item) => ({ mediaId: item.mediaId, previewUrl: item.previewUrl }));

    // Les entrées en échec partent aussi : le compositeur repart vide, et
    // l'utilisateur n'hérite pas des vignettes du message précédent. Elles, en
    // revanche, n'ont jamais quitté ce hook — on révoque donc leurs aperçus.
    currentRef.current
      .filter((item) => item.status !== 'uploaded')
      .forEach((item) => URL.revokeObjectURL(item.previewUrl));

    replace(() => []);

    return taken;
  }, [replace]);

  // Dépendances vides : ce nettoyage ne doit tourner qu'au démontage. Il lit la
  // liste par le `ref`, donc il voit toujours l'état courant sans avoir besoin
  // de se réabonner.
  useEffect(
    () => () => {
      currentRef.current.forEach((item) => URL.revokeObjectURL(item.previewUrl));
    },
    [],
  );

  return {
    pending,
    add,
    remove,
    takeUploaded,
    /** Vrai tant qu'un transfert est en cours : le compositeur s'en sert pour bloquer l'envoi. */
    isUploading: pending.some((item) => item.status === 'uploading'),
  };
}
