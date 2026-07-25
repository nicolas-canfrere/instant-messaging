import { useState, type KeyboardEvent } from 'react';

type Props = {
  /** Rend la promesse de l'envoi, mais le composant ne l'attend pas : voir `submit`. */
  onSend: (content: string) => Promise<void>;
  /** Signale la frappe. L'etranglement vit dans `useTyping`, pas ici : ce composant reste bete. */
  onTyping: () => void;
};

/**
 * Zone de saisie. Volontairement bete : elle ne connait ni le reseau, ni le
 * `client_message_id`, ni le reessai — tout cela vit dans `useAppState`. Elle
 * ne sait que deux choses : quand l'utilisateur veut envoyer, et quoi.
 */
export function Composer({ onSend, onTyping }: Props) {
  const [content, setContent] = useState('');

  // On envoie le contenu debarrasse de ses espaces de bord : un message fait
  // uniquement d'espaces n'a pas de sens, et le serveur le refuserait.
  const trimmed = content.trim();
  const canSend = trimmed !== '';

  function submit() {
    if (!canSend) return;

    // Le champ est vide AVANT que le serveur ait repondu, et l'on n'attend pas
    // la promesse : c'est le principe de l'envoi optimiste. L'utilisateur peut
    // enchainer un second message sans attendre le premier, et l'echec eventuel
    // se lit sur la bulle du message (statut `failed`), pas ici.
    setContent('');
    void onSend(trimmed);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    // Convention des messageries : Entree envoie, Maj+Entree passe a la ligne.
    // `event.preventDefault()` est indispensable, sinon le saut de ligne est
    // insere en plus de l'envoi et le champ vide se retrouve avec un "\n".
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      submit();
    }
  }

  return (
    <div className="flex items-end gap-2 border-t border-slate-200 bg-white px-4 py-3">
      <textarea
        value={content}
        onChange={(event) => {
          setContent(event.target.value);
          onTyping();
        }}
        onKeyDown={handleKeyDown}
        rows={2}
        placeholder="Votre message… (Entrée pour envoyer, Maj+Entrée pour une nouvelle ligne)"
        aria-label="Votre message"
        className="flex-1 resize-none rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
      />

      <button
        type="button"
        onClick={submit}
        disabled={!canSend}
        className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-40"
      >
        Envoyer
      </button>
    </div>
  );
}
