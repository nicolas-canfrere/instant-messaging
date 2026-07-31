import { useCallback, useEffect, useRef, useState } from 'react';
import { ulid } from 'ulid';
import { api } from '../api/client';
import { declaredTypeFor } from '../api/declaredType';
import { putBytes } from '../api/upload';

/**
 * Cycle complet d'un envoi de piece jointe : pre-signature → PUT direct →
 * confirmation. Une image comme un document suivent exactement le meme
 * cycle ; seul l'apercu differe (voir plus bas).
 *
 * ## Pourquoi un aperçu local
 *
 * Entre le moment où l'utilisateur choisit son fichier et celui où le serveur
 * a une miniature à servir, il s'écoule plusieurs secondes. Pendant ce temps,
 * le navigateur possède déjà les octets : `URL.createObjectURL(file)` fabrique
 * une URL `blob:` qui pointe vers eux, en mémoire, sans aucun réseau.
 *
 * Ce mecanisme ne vaut que pour une image : un PDF n'a rien a previsualiser,
 * et `URL.createObjectURL` sur un PDF produit tout de meme une URL `blob:`
 * valide — que rien n'affichera, et qui retiendrait quand meme les octets en
 * memoire pour rien. `previewUrl` reste donc `null` pour un document.
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
  /** `null` pour un document : rien a previsualiser localement (voir l'en-tete du fichier). */
  previewUrl: string | null;
  status: PendingUploadStatus;
  /** Renseigné dès la pré-signature, donc avant même que les octets soient partis. */
  mediaId: string | null;
};

/** Ce qu'une piece jointe emporte avec elle quand elle part dans un message. */
export type TakenMedia = { mediaId: string; previewUrl: string | null; fileName: string };

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

      // Ce que le backend acceptera reellement — voir declaredType.ts. Ni
      // `file.type` (peu fiable pour les documents) ni une devinette locale
      // ne remplacent la mesure serveur ; ceci evite juste un aller-retour
      // reseau voue a finir en 422.
      const contentType = declaredTypeFor(file);

      // Un apercu local n'a de sens que pour une image : voir l'en-tete du
      // fichier. Pour un document refuse (contentType null), il n'y en a pas
      // non plus.
      const previewUrl =
        contentType !== null && contentType.startsWith('image/') ? URL.createObjectURL(file) : null;

      // L'entrée apparaît AVANT tout appel réseau : l'utilisateur voit sa
      // vignette dès qu'il a choisi son fichier, pas trois secondes plus tard.
      // Un type refuse part directement `failed` : inutile de passer par
      // `uploading` pour un fichier qu'on sait deja rejete.
      replace((previous) => [
        ...previous,
        {
          localId,
          fileName: file.name,
          previewUrl,
          status: contentType === null ? 'failed' : 'uploading',
          mediaId: null,
        },
      ]);

      if (contentType === null) {
        // Refus LOCAL : aucun appel reseau pour un fichier qu'on sait refuse
        // (par ex. un `.zip`, ou un dossier glisse qui arrive sans type).
        // Le message precis importe peu ici : la vignette affiche deja
        // « Echec », comme tout autre transfert rate.
        return;
      }

      try {
        const ticket = await api.presignUpload(file.name, contentType, file.size);

        // Les octets vont DIRECTEMENT au stockage : ils ne traversent jamais
        // notre backend.
        await putBytes(ticket.upload_url, file, contentType);

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
        // On garde la vignette, marquee en erreur, plutot que de la faire
        // disparaitre : l'utilisateur doit comprendre que CE fichier n'est pas
        // parti, et pouvoir le retirer lui-meme.
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

      // `previewUrl` est `null` pour un document : rien a revoquer, il n'y a
      // jamais eu de `blob:` URL creee pour lui.
      if (target !== undefined && target.previewUrl !== null) {
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
      .map((item) => ({ mediaId: item.mediaId, previewUrl: item.previewUrl, fileName: item.fileName }));

    // Les entrées en échec partent aussi : le compositeur repart vide, et
    // l'utilisateur n'hérite pas des vignettes du message précédent. Elles, en
    // revanche, n'ont jamais quitté ce hook — on révoque donc leurs aperçus.
    currentRef.current
      .filter((item) => item.status !== 'uploaded')
      .forEach((item) => {
        if (item.previewUrl !== null) {
          URL.revokeObjectURL(item.previewUrl);
        }
      });

    replace(() => []);

    return taken;
  }, [replace]);

  // Dépendances vides : ce nettoyage ne doit tourner qu'au démontage. Il lit la
  // liste par le `ref`, donc il voit toujours l'état courant sans avoir besoin
  // de se réabonner.
  useEffect(
    () => () => {
      currentRef.current.forEach((item) => {
        if (item.previewUrl !== null) {
          URL.revokeObjectURL(item.previewUrl);
        }
      });
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
