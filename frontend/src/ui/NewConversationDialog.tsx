import { useState } from 'react';
import { ProblemError } from '../api/problem';
import type { UserSummary } from '../api/types';

type Props = {
  /**
   * Annuaire deja charge par `useAppState` (il vient de `api.users()`). On le
   * recoit plutot que de le redemander : c'est exactement la meme donnee, et le
   * dialogue n'a alors ni etat de chargement ni second appel reseau a gerer.
   */
  users: Record<string, UserSummary>;
  meId: string;
  onCreateDirect: (peerId: string) => Promise<void>;
  onCreateGroup: (title: string, memberIds: string[]) => Promise<void>;
  onClose: () => void;
};

/**
 * Le type de conversation n'est pas choisi par l'utilisateur : il se deduit du
 * nombre de personnes selectionnees. Une seule → direct, plusieurs → groupe.
 * Un choix explicite serait une question de plus a poser pour une information
 * qu'on possede deja.
 */
export function NewConversationDialog({
  users,
  meId,
  onCreateDirect,
  onCreateGroup,
  onClose,
}: Props) {
  const [selected, setSelected] = useState<string[]>([]);
  const [title, setTitle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // On ne se propose jamais soi-meme : le backend refuserait, et l'idee d'une
  // conversation avec soi n'a pas de sens dans cette tranche.
  const candidates = Object.values(users).filter((user) => user.id !== meId);

  const isGroup = selected.length > 1;
  // Le titre n'est obligatoire que pour un groupe : un direct s'affiche sous le
  // nom de l'interlocuteur et n'en porte pas.
  const canSubmit = selected.length > 0 && (!isGroup || title.trim() !== '');

  function toggle(userId: string) {
    setSelected((current) =>
      current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId],
    );
  }

  async function submit() {
    // `selected[0]` est `string | undefined` pour TypeScript (acces par index) :
    // on le nomme une fois plutot que de forcer le type avec un `!`.
    const [first] = selected;
    if (!canSubmit || first === undefined) return;

    setBusy(true);
    setError(null);

    try {
      // La creation, le renouvellement du jeton temps reel et la selection sont
      // du ressort de `useAppState` : ce composant se contente de fermer une
      // fois l'operation reussie.
      if (isGroup) {
        await onCreateGroup(title.trim(), selected);
      } else {
        await onCreateDirect(first);
      }

      onClose();
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Création impossible.');
      setBusy(false);
    }
  }

  return (
    // `fixed inset-0` : le voile couvre toute la fenetre et capte les clics
    // exterieurs, ce qui donne au dialogue son comportement modal sans
    // bibliotheque tierce.
    <div className="fixed inset-0 z-10 flex items-center justify-center bg-slate-900/40 p-4">
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Nouvelle conversation"
        className="flex max-h-[80vh] w-96 flex-col gap-3 rounded bg-white p-4 shadow-lg"
      >
        <h2 className="font-semibold text-slate-900">Nouvelle conversation</h2>

        {candidates.length === 0 && (
          <p className="text-sm text-slate-400">Aucun autre utilisateur.</p>
        )}

        <ul className="flex-1 overflow-y-auto">
          {candidates.map((user) => (
            <li key={user.id}>
              <label className="flex cursor-pointer items-center gap-2 rounded px-2 py-2 text-sm hover:bg-slate-50">
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

        {isGroup && (
          <input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="Titre du groupe (obligatoire)"
            aria-label="Titre du groupe"
            className="rounded border border-slate-300 px-3 py-2 text-sm"
          />
        )}

        {error !== null && (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 disabled:opacity-50"
          >
            Annuler
          </button>
          <button
            type="button"
            onClick={() => void submit()}
            disabled={!canSubmit || busy}
            className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-40"
          >
            {busy ? 'Création…' : isGroup ? 'Créer le groupe' : 'Ouvrir la conversation'}
          </button>
        </div>
      </div>
    </div>
  );
}
