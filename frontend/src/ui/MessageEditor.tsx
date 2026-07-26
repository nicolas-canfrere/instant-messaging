import { useState, type KeyboardEvent } from 'react';

type Props = {
  initialContent: string;
  onSubmit: (content: string) => void;
  onCancel: () => void;
};

/**
 * Editeur en ligne dans la bulle. Le composant ne connait ni l'API ni le store :
 * il rend deux callbacks, ce qui le rend testable sans rien monter d'autre.
 */
export function MessageEditor({ initialContent, onSubmit, onCancel }: Props) {
  const [draft, setDraft] = useState(initialContent);

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Escape') {
      onCancel();

      return;
    }

    // Entree valide, Maj+Entree passe a la ligne : meme convention que le
    // composeur, pour ne pas avoir deux gestes differents dans la meme fenetre.
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();

      const trimmed = draft.trim();
      if (trimmed === '') return;

      // Rien n'a change : refermer suffit. Ce n'est pas une economie de requete
      // — le serveur traite ce cas en no-op — mais le no-op renvoie
      // `edited_at: null`, et c'est par ce chemin qu'un message jamais modifie
      // se retrouvait affuble de la mention « modifie ».
      if (trimmed === initialContent.trim()) {
        onCancel();

        return;
      }

      onSubmit(trimmed);
    }
  }

  return (
    <textarea
      autoFocus
      value={draft}
      onChange={(event) => setDraft(event.target.value)}
      onKeyDown={handleKeyDown}
      className="w-full rounded bg-white/10 p-1 text-sm"
      aria-label="Modifier le message"
    />
  );
}
