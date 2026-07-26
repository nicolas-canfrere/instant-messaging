import type { Me } from '../api/types';
import { PresenceDot } from './PresenceDot';

type Props = {
  me: Me;
  onLogout: () => void;
};

/**
 * Bandeau d'identite, en pied de la colonne de gauche.
 *
 * Aussi bete que ses voisins : il n'appelle pas l'API et ne connait pas la
 * session. Il affiche ce qu'on lui passe et remonte le clic — c'est `App` qui
 * possede l'etat de session, donc `App` qui deconnecte.
 *
 * La pastille est verte en dur, et c'est un choix assume : elle dit « vous etes
 * connecte », pas « votre flux SSE est vivant ». La presence du porteur de la
 * session n'existe pas cote front (`onlineUserIds` ne contient que les pairs) ;
 * la deduire du RealtimeClient serait une autre fonctionnalite.
 */
export function CurrentUserBar({ me, onLogout }: Props) {
  return (
    <div className="flex items-center gap-2 border-t border-slate-200 px-4 py-3">
      <PresenceDot online />

      {/*
        `min-w-0` : sans lui, un nom long refuserait de se tronquer et pousserait
        le bouton hors de la colonne. C'est la contrepartie obligee de `truncate`
        dans un conteneur flex.
      */}
      <span className="flex min-w-0 flex-col">
        <span className="truncate text-sm font-medium text-slate-900">{me.display_name}</span>
        <span className="truncate text-xs text-slate-500">@{me.username}</span>
      </span>

      <button
        type="button"
        onClick={onLogout}
        className="ml-auto shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"
      >
        Se déconnecter
      </button>
    </div>
  );
}
