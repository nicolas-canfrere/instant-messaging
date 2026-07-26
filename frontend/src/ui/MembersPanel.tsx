import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { ProblemError } from '../api/problem';
import type { ConversationMember, UserSummary } from '../api/types';
import { userName } from './labels';

type Props = {
  conversationId: string;
  users: Record<string, UserSummary>;
  meId: string;
  onLeave: () => Promise<void>;
  onClose: () => void;
};

/**
 * Panneau des membres d'un groupe.
 *
 * C'est le seul composant qui appelle l'API directement, et c'est assume : la
 * liste des membres n'interesse aucun autre ecran, la remonter jusqu'a
 * `useAppState` y ajouterait un etat global pour une donnee locale et ephemere.
 * Le detail est donc charge a l'ouverture et rechargee apres chaque ajout.
 */
export function MembersPanel({ conversationId, users, meId, onLeave, onClose }: Props) {
  const [members, setMembers] = useState<ConversationMember[] | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // `reloadToken` change a chaque ajout reussi : c'est ce qui redeclenche
  // l'effet de chargement sans dupliquer l'appel a `api.conversation`.
  const [reloadToken, setReloadToken] = useState(0);

  useEffect(() => {
    // Meme garde qu'ailleurs : en mode strict React monte l'effet deux fois, et
    // une reponse tardive du premier montage ne doit pas ecraser le second.
    let cancelled = false;

    api
      .conversation(conversationId)
      .then((detail) => {
        if (!cancelled) setMembers(detail.members);
      })
      .catch(() => {
        if (!cancelled) setError('Membres indisponibles.');
      });

    return () => {
      cancelled = true;
    };
  }, [conversationId, reloadToken]);

  const memberIds = new Set((members ?? []).map((member) => member.user_id));
  // On ne propose que les personnes qui ne sont pas deja dans le groupe. Tant
  // que la liste n'est pas chargee, `memberIds` est vide : on n'affiche donc
  // rien pour ne pas proposer d'ajouter quelqu'un qui y est deja.
  const candidates =
    members === null ? [] : Object.values(users).filter((user) => !memberIds.has(user.id));

  // Un admin ne peut pas partir tant qu'il n'a pas transfere ses droits : le
  // serveur repond 409. On ne lui montre donc pas le bouton, plutot que de lui
  // proposer une action qu'on sait refusee. Tant que la liste n'est pas
  // chargee, on ne sait pas quel est notre role : on n'affiche rien non plus.
  const canLeave = (members ?? []).some(
    (member) => member.user_id === meId && member.role === 'member',
  );

  // Miroir exact de la regle ci-dessus : seul un admin modifie la composition
  // du groupe, le serveur repond 403 aux autres — et le voter le journalise en
  // `warning`, precisement pour signaler une interface qui propose une action
  // interdite. Meme prudence sur la liste non chargee : on n'affiche rien tant
  // qu'on ignore son propre role.
  const canAddMembers = (members ?? []).some(
    (member) => member.user_id === meId && member.role === 'admin',
  );

  async function leave() {
    if (!window.confirm('Quitter ce groupe ? Vous ne le verrez plus.')) return;

    setBusy(true);
    setError(null);

    try {
      await onLeave();
      // Pas de `setBusy(false)` au succes : la conversation disparait de la
      // liste, `selected` repasse a `null` et tout ce sous-arbre est demonte.
      // Toucher l'etat d'un composant demonte n'aurait aucun effet.
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Depart impossible.');
      setBusy(false);
    }
  }

  function toggle(userId: string) {
    setSelected((current) =>
      current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId],
    );
  }

  async function add() {
    if (selected.length === 0) return;

    setBusy(true);
    setError(null);

    try {
      await api.addMembers(conversationId, selected);

      // Pas besoin de `resubscribe()` ici : c'est le NOUVEAU membre dont le JWT
      // ne couvre pas le topic, et il l'apprend par `membership.changed` sur son
      // canal systeme. Notre propre abonnement, lui, n'a pas change.
      setSelected([]);
      setReloadToken((token) => token + 1);
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Ajout impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <aside className="flex w-72 shrink-0 flex-col gap-3 overflow-y-auto border-l border-slate-200 bg-white p-4">
      <header className="flex items-center justify-between">
        <h3 className="font-semibold text-slate-900">Membres</h3>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fermer le panneau des membres"
          className="rounded px-2 text-slate-500 hover:bg-slate-100"
        >
          ×
        </button>
      </header>

      {members === null ? (
        <p className="text-sm text-slate-400">Chargement…</p>
      ) : (
        <ul className="flex flex-col gap-1 text-sm">
          {members.map((member) => (
            <li key={member.user_id} className="flex items-baseline justify-between gap-2">
              <span className="truncate">{userName(users, member.user_id)}</span>
              <span className="shrink-0 text-xs text-slate-400">{member.role}</span>
            </li>
          ))}
        </ul>
      )}

      {canAddMembers && candidates.length > 0 && (
        <>
          <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Ajouter</h4>

          <ul className="flex flex-col">
            {candidates.map((user) => (
              <li key={user.id}>
                <label className="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-slate-50">
                  <input
                    type="checkbox"
                    checked={selected.includes(user.id)}
                    onChange={() => toggle(user.id)}
                  />
                  <span className="truncate">{user.display_name}</span>
                </label>
              </li>
            ))}
          </ul>

          <button
            type="button"
            onClick={() => void add()}
            disabled={selected.length === 0 || busy}
            className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-40"
          >
            {busy ? 'Ajout…' : 'Ajouter au groupe'}
          </button>
        </>
      )}

      {canLeave && (
        <button
          type="button"
          onClick={() => void leave()}
          disabled={busy}
          className="mt-auto rounded border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-40"
        >
          Quitter le groupe
        </button>
      )}

      {error !== null && (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      )}
    </aside>
  );
}
