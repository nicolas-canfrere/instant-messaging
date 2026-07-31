import { useCallback, useRef, useState, type DragEvent } from 'react';

/**
 * Glisser-depose sur un conteneur entier : le voile qui apparait, et la
 * transmission des fichiers laches vers `onFiles`.
 *
 * `onFiles` doit deleguer au MEME point d'entree que le selecteur de
 * fichiers (`useMediaUpload().add`) : ce hook ne cree aucun second chemin
 * d'envoi, il ne fait qu'attraper les fichiers avant de les remettre a
 * l'existant.
 *
 * Trois pieges de l'API `DragEvent`, tous contre-intuitifs et faciles a
 * regresser silencieusement :
 *
 * 1. `preventDefault()` sur `dragover` ET sur `drop`. Par defaut, le
 *    navigateur REFUSE le depot : sans `preventDefault()` sur `dragover`,
 *    l'evenement `drop` ne se declenche jamais, meme si `onDrop` fait tout
 *    bien. Et sans `preventDefault()` sur `drop` lui-meme, le navigateur
 *    navigue vers le fichier lache comme s'il l'ouvrait depuis le disque :
 *    l'onglet entier — et le brouillon en cours — disparait. C'est la
 *    regression la plus couteuse de ce fichier.
 *
 * 2. Un COMPTEUR de profondeur plutot qu'un simple booleen pour savoir si on
 *    est « en train de glisser ». `dragenter`/`dragleave` se declenchent
 *    aussi quand le curseur traverse un enfant du conteneur (le voile
 *    lui-meme, ou un message) : un booleen naif repasserait a `false` des
 *    que le curseur touche un enfant, et le voile clignoterait sans arret
 *    pendant le survol. Le compteur, lui, ne repasse a zero que quand on
 *    sort vraiment de la derniere couche.
 *
 * 3. Le compteur doit etre remis a zero explicitement au `drop` : sans
 *    cela, un `dragenter` compte sans `dragleave` correspondant (le depot
 *    lui-meme n'emet pas de `dragleave`) laisserait le compteur au-dessus de
 *    zero, et le voile resterait affiche apres un depot pourtant reussi.
 */

type UseFileDropOptions = {
  onFiles: (files: File[]) => void;
};

type FileDropHandlers = {
  onDragEnter: (event: DragEvent) => void;
  onDragOver: (event: DragEvent) => void;
  onDragLeave: (event: DragEvent) => void;
  onDrop: (event: DragEvent) => void;
};

export function useFileDrop({ onFiles }: UseFileDropOptions): {
  isDragging: boolean;
  handlers: FileDropHandlers;
} {
  const [isDragging, setIsDragging] = useState(false);

  // Le compteur vit dans un `useRef`, pas un `useState` : il change a
  // chaque `dragenter`/`dragleave`, et le mettre en `useState` provoquerait
  // un rendu a chaque passage sur un enfant. Seul le passage 0 <-> >0 doit
  // provoquer un rendu, et c'est `isDragging` qui en est charge.
  const depthRef = useRef(0);

  const onDragEnter = useCallback((event: DragEvent) => {
    event.preventDefault();
    depthRef.current += 1;
    setIsDragging(true);
  }, []);

  const onDragOver = useCallback((event: DragEvent) => {
    // Voir le piege 1 en tete de fichier : sans ce `preventDefault`,
    // l'evenement `drop` ne se declenche jamais du tout.
    event.preventDefault();
  }, []);

  const onDragLeave = useCallback((event: DragEvent) => {
    event.preventDefault();
    depthRef.current = Math.max(0, depthRef.current - 1);

    if (depthRef.current === 0) {
      setIsDragging(false);
    }
  }, []);

  const onDrop = useCallback(
    (event: DragEvent) => {
      // Voir le piege 1 en tete de fichier : sans ce `preventDefault`, le
      // navigateur navigue vers le fichier lache et l'onglet est perdu.
      event.preventDefault();
      event.stopPropagation();

      // Voir le piege 3 en tete de fichier : remise a zero systematique,
      // que des fichiers aient ete recus ou non.
      depthRef.current = 0;
      setIsDragging(false);

      onFiles(Array.from(event.dataTransfer.files));
    },
    [onFiles],
  );

  return { isDragging, handlers: { onDragEnter, onDragOver, onDragLeave, onDrop } };
}
