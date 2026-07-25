import { useLayoutEffect, useRef, type UIEvent } from 'react';
import type { UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import { formatTime, userName } from './labels';
import { useScrollAnchor } from './useScrollAnchor';

/** Distance au haut du conteneur en dessous de laquelle on charge la page precedente. */
const LOAD_OLDER_THRESHOLD_PX = 100;

/** Marge de tolerance pour considerer que l'utilisateur regarde bien le bas du fil. */
const AT_BOTTOM_TOLERANCE_PX = 40;

type Props = {
  thread: Thread;
  users: Record<string, UserSummary>;
  meId: string;
  onLoadOlder: () => void;
};

/**
 * Ce composant est monte avec `key={conversationId}` par `ConversationView` :
 * changer de conversation le REMONTE. C'est volontaire, et c'est ce qui remet
 * a zero la hauteur memorisee par `useScrollAnchor` — sinon la hauteur d'un
 * fil servirait d'ancre a un autre et le scroll sauterait au changement.
 */
export function MessageList({ thread, users, meId, onLoadOlder }: Props) {
  const container = useRef<HTMLDivElement | null>(null);
  /** L'utilisateur suivait-il le bas du fil juste avant ce rendu ? En ref : ne doit pas re-rendre. */
  const atBottom = useRef(true);

  // Corrige le saut de scroll provoque par l'insertion d'une page plus ancienne
  // EN TETE de la liste. Doit etre appele avant l'effet de suivi ci-dessous :
  // les effets de layout s'executent dans l'ordre de declaration, et le suivi
  // du bas doit pouvoir ecraser la correction quand les deux s'appliquent.
  useScrollAnchor(container, thread.items.length);

  useLayoutEffect(() => {
    const element = container.current;
    if (!element) return;

    // Au premier rendu (`atBottom` vaut `true`) on se place en bas : un fil de
    // discussion se lit par la fin. Ensuite, on ne suit les nouveaux messages
    // que si l'utilisateur etait deja en bas — sinon on le tirerait hors de la
    // portion d'historique qu'il est en train de lire.
    if (atBottom.current) {
      element.scrollTop = element.scrollHeight;
    }
  }, [thread.items.length]);

  function handleScroll(event: UIEvent<HTMLDivElement>) {
    const element = event.currentTarget;

    atBottom.current =
      element.scrollHeight - element.scrollTop - element.clientHeight < AT_BOTTOM_TOLERANCE_PX;

    // `nextBefore === null` veut dire « debut de l'historique atteint » : on
    // arrete de demander. La garde anti-doublon vit dans `useAppState`, ce
    // handler peut donc se permettre d'etre appele a chaque pixel de scroll.
    if (element.scrollTop < LOAD_OLDER_THRESHOLD_PX && thread.nextBefore !== null) {
      onLoadOlder();
    }
  }

  return (
    <div ref={container} onScroll={handleScroll} className="flex-1 overflow-y-auto px-4 py-3">
      {thread.nextBefore === null && thread.loaded && thread.items.length > 0 && (
        <p className="pb-3 text-center text-xs text-slate-400">Debut de la conversation</p>
      )}

      {thread.loaded && thread.items.length === 0 && (
        <p className="text-center text-sm text-slate-400">Aucun message pour l'instant.</p>
      )}

      <ul className="flex flex-col gap-2">
        {thread.items.map((message) => (
          // La cle est l'identifiant client : c'est le seul qui existe des le
          // rendu optimiste, avant que le serveur n'attribue un ULID. Utiliser
          // `id` ferait remonter le composant a l'acquittement.
          <li
            key={message.clientMessageId}
            className={`max-w-[75%] rounded px-3 py-2 ${
              message.senderId === meId ? 'self-end bg-slate-900 text-white' : 'bg-white text-slate-900'
            } ${message.status === 'failed' ? 'opacity-70 ring-1 ring-red-400' : ''}`}
          >
            <p className="text-xs opacity-60">
              {userName(users, message.senderId)} · {formatTime(message.createdAt)}
            </p>
            <p className="whitespace-pre-wrap break-words">{message.content}</p>

            {message.status === 'pending' && <p className="text-xs opacity-60">envoi…</p>}
            {message.status === 'failed' && (
              <p className="text-xs text-red-500">échec — réessayer</p>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
