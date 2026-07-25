import { useLayoutEffect, useRef } from 'react';

/**
 * Inserer une page plus ancienne EN TETE de la liste augmente la hauteur totale
 * du conteneur : sans correction, le contenu visible saute vers le bas et
 * l'utilisateur perd sa place. On memorise la hauteur avant l'insertion et on
 * decale le scroll de la difference apres, ce qui donne l'illusion que rien
 * n'a bouge. C'est le bug classique de toute messagerie.
 *
 * MAIS la hauteur augmente aussi quand un message arrive EN BAS (SSE, ou notre
 * propre envoi optimiste). Corriger dans ce cas la produit exactement le saut
 * qu'on cherche a eviter : le lecteur d'historique verrait la fenetre bouger a
 * chaque message recu. La croissance de `scrollHeight` ne dit donc pas OU
 * l'insertion a eu lieu — il faut le savoir autrement.
 *
 * D'ou `topKey` : l'identite du PREMIER element de la liste. Elle ne change que
 * si quelque chose s'est insere avant lui, c'est-a-dire lors d'un prepend. Un
 * ajout en bas, une reconciliation optimiste ou un acquittement la laissent
 * intacte, et la correction ne se declenche pas.
 *
 * `itemCount` n'est pas une condition de correction : il ne sert qu'a faire
 * tourner l'effet a CHAQUE changement de la liste, pour que la hauteur memorisee
 * reste a jour. Sans lui, la hauteur retenue daterait du dernier prepend et la
 * correction suivante integrerait a tort tous les messages ajoutes entre-temps.
 *
 * useLayoutEffect et non useEffect : la correction doit avoir lieu avant que
 * le navigateur peigne la frame, sinon le saut reste visible.
 */
export function useScrollAnchor(
  container: React.RefObject<HTMLElement | null>,
  topKey: string | null,
  itemCount: number,
) {
  const previousHeight = useRef(0);
  const previousTopKey = useRef<string | null>(null);

  useLayoutEffect(() => {
    const element = container.current;
    if (!element) return;

    // `previousTopKey.current === null` = premier remplissage du fil (il etait
    // vide) : il n'y a aucune place a preserver, et c'est l'effet de suivi du
    // bas, dans MessageList, qui doit avoir le dernier mot.
    const isPrepend =
      previousTopKey.current !== null && topKey !== null && topKey !== previousTopKey.current;

    const delta = element.scrollHeight - previousHeight.current;

    if (isPrepend && delta > 0) {
      element.scrollTop += delta;
    }

    previousHeight.current = element.scrollHeight;
    previousTopKey.current = topKey;
  }, [container, topKey, itemCount]);
}
