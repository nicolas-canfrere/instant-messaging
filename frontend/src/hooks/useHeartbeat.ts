import { useEffect } from 'react';
import { api } from '../api/client';

/** 20 s pour un TTL serveur de 30 s : une marge d'un battement manque. */
const HEARTBEAT_INTERVAL_MS = 20_000;

/**
 * Bat toutes les 20 s et remonte qui est en ligne.
 *
 * Pourquoi un sondage et non un evenement pousse : l'expiration d'une cle Redis
 * n'est PAS un evenement. Personne ne peut publier « untel vient de passer hors
 * ligne » au moment ou sa cle expire. Ne pousser que la transition inverse
 * donnerait un statut qui monte et ne redescend jamais — exactement le booleen
 * `is_online` perime qu'on cherche a eviter.
 *
 * Le battement est suspendu quand l'onglet est cache : un onglet en arriere-plan
 * n'a pas besoin de se declarer en ligne, et le navigateur brimerait de toute
 * facon ses minuteurs.
 */
export function useHeartbeat(onOnlineUserIds: (ids: string[]) => void): void {
  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | null = null;

    const beat = () => {
      void api
        .heartbeat()
        .then((response) => onOnlineUserIds(response.online_user_ids))
        .catch(() => {
          // Un battement manque n'est pas un incident : le suivant corrige. On
          // ne journalise pas, sous peine d'une ligne toutes les 20 s hors ligne.
        });
    };

    const start = () => {
      if (timer !== null) return;

      beat();
      timer = setInterval(beat, HEARTBEAT_INTERVAL_MS);
    };

    const stop = () => {
      if (timer === null) return;

      clearInterval(timer);
      timer = null;
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') start();
      else stop();
    };

    onVisibilityChange();
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange);
      stop();
    };
  }, [onOnlineUserIds]);
}
