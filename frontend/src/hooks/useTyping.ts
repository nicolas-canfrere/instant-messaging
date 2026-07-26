import { useCallback, useRef } from 'react';
import { api } from '../api/client';

/**
 * Au plus un POST toutes les 3 s pendant la frappe.
 *
 * Sans etranglement, chaque touche produirait une requete : une phrase de
 * quarante caracteres inonderait le hub de quarante evenements identiques. 3 s
 * pour un indicateur qui vit 5 s cote destinataire : l'affichage ne clignote
 * jamais entre deux envois.
 */
const TYPING_THROTTLE_MS = 3_000;

export function useTyping(): (conversationId: string) => void {
  // `ref` et non `state` : modifier cette date ne doit declencher aucun rendu.
  const lastSentAtRef = useRef<Record<string, number>>({});

  return useCallback((conversationId: string) => {
    const now = Date.now();

    if (now - (lastSentAtRef.current[conversationId] ?? 0) < TYPING_THROTTLE_MS) {
      return;
    }

    lastSentAtRef.current[conversationId] = now;

    void api.typing(conversationId).catch(() => {
      // Une frappe non signalee n'a aucune consequence : on n'en fait rien.
    });
  }, []);
}
