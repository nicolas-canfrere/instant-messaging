import { useEffect, useState } from 'react';
import { selectTypists, type TypingState } from '../store/typingReducer';
import type { UserSummary } from '../api/types';

/**
 * Un indicateur qui expire tout seul n'entraine AUCUN rendu.
 *
 * Le store sait que la frappe d'Alice expire dans 5 s, mais React ne le sait
 * pas : sans reveil periodique, la ligne resterait affichee indefiniment. D'ou
 * ce tic d'une seconde — et il ne tourne QUE tant qu'il reste un frappeur, sinon
 * l'application entiere se reveillerait chaque seconde pour rien.
 */
export function TypingIndicator({
  typingState,
  conversationId,
  users,
}: {
  typingState: TypingState;
  conversationId: string;
  users: Record<string, UserSummary>;
}) {
  const [now, setNow] = useState(() => Date.now());

  const typists = selectTypists(typingState, conversationId, now);

  useEffect(() => {
    if (typists.length === 0) return;

    const timer = setInterval(() => setNow(Date.now()), 1_000);

    return () => clearInterval(timer);
  }, [typists.length]);

  if (typists.length === 0) return null;

  const names = typists.map((id) => users[id]?.display_name ?? 'Quelqu un');
  const label = names.length === 1 ? `${names[0]} écrit…` : `${names.join(', ')} écrivent…`;

  return (
    <p className="px-4 py-1 text-sm italic text-slate-500" aria-live="polite">
      {label}
    </p>
  );
}
