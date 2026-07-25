import { useLayoutEffect, useRef } from 'react';

/**
 * Insérer une page plus ancienne EN TETE de la liste augmente la hauteur totale
 * du conteneur : sans correction, le contenu visible saute vers le bas et
 * l'utilisateur perd sa place. On memorise la hauteur avant l'insertion et on
 * decale le scroll de la difference apres, ce qui donne l'illusion que rien
 * n'a bouge. C'est le bug classique de toute messagerie.
 *
 * useLayoutEffect et non useEffect : la correction doit avoir lieu avant que
 * le navigateur peigne la frame, sinon le saut reste visible.
 */
export function useScrollAnchor(container: React.RefObject<HTMLElement | null>, dependency: number) {
  const previousHeight = useRef(0);

  useLayoutEffect(() => {
    const element = container.current;
    if (!element) return;

    const delta = element.scrollHeight - previousHeight.current;

    if (previousHeight.current !== 0 && delta > 0) {
      element.scrollTop += delta;
    }

    previousHeight.current = element.scrollHeight;
  }, [container, dependency]);
}
