import { useEffect, useRef } from 'react';
import { api } from '../api/client';

/** Rafales de messages regroupees : un seul POST par salve. */
const DEBOUNCE_MS = 500;

/**
 * Avance le curseur « lu » quand la conversation est ouverte ET que l'onglet est
 * visible.
 *
 * La condition de visibilite n'est pas negociable : sans elle, un onglet ouvert
 * et oublie en arriere-plan marquerait tout comme lu pendant des heures.
 * L'accuse deviendrait un mensonge — exactement le defaut que la fonctionnalite
 * est censee eviter.
 *
 * Le dernier curseur envoye est memorise pour ne pas rejouer un watermark deja
 * atteint. Le backend s'en protege deja par son `WHERE`, mais chaque retour de
 * focus produirait sinon une requete HTTP pour rien.
 */
export function useReadWatermark(
  conversationId: string | null,
  lastMessageId: string | null,
): void {
  const sentRef = useRef<Record<string, string>>({});

  useEffect(() => {
    if (conversationId === null || lastMessageId === null) return;

    let timer: ReturnType<typeof setTimeout> | null = null;

    const push = () => {
      if (document.visibilityState !== 'visible') return;
      if ((sentRef.current[conversationId] ?? '') >= lastMessageId) return;

      sentRef.current[conversationId] = lastMessageId;

      void api
        .receipts(conversationId, { deliveredUpTo: lastMessageId, readUpTo: lastMessageId })
        .catch(() => {
          // L'echec est rattrapable : on oublie la marque pour reessayer au
          // prochain declencheur, plutot que de figer le curseur pour la session.
          delete sentRef.current[conversationId];
        });
    };

    const schedule = () => {
      if (timer !== null) clearTimeout(timer);
      timer = setTimeout(push, DEBOUNCE_MS);
    };

    schedule();
    document.addEventListener('visibilitychange', schedule);

    return () => {
      if (timer !== null) clearTimeout(timer);
      document.removeEventListener('visibilitychange', schedule);
    };
  }, [conversationId, lastMessageId]);
}
