import type { ConversationSummary, UserSummary } from '../api/types';
import { viewerLocale, viewerTimeZone } from './dates';
import { conversationTitle, formatListDate } from './labels';
import { PresenceDot } from './PresenceDot';

type Props = {
  conversations: ConversationSummary[];
  users: Record<string, UserSummary>;
  peers: Record<string, string>;
  /** Pairs en ligne, rafraichi a chaque battement de coeur. */
  onlineUserIds: Set<string>;
  selectedId: string | null;
  onSelect: (conversationId: string) => void;
  onNewConversation: () => void;
};

/**
 * Colonne de gauche. Composant volontairement bete : il ne charge rien, ne
 * decide rien, il affiche ce qu'on lui passe et remonte le clic. Toute la
 * logique (chargement, resolution des noms) vit dans `useAppState`.
 */
export function ConversationList({
  conversations,
  users,
  peers,
  onlineUserIds,
  selectedId,
  onSelect,
  onNewConversation,
}: Props) {
  // Resolus une fois par rendu de liste plutot qu'a chaque conversation : ces
  // appels Intl ne sont pas gratuits, et la valeur ne change pas en cours de
  // session.
  const timeZone = viewerTimeZone();
  const locale = viewerLocale();

  return (
    <nav className="flex w-72 shrink-0 flex-col overflow-y-auto border-r border-slate-200 bg-white">
      <div className="flex items-center justify-between px-4 py-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
          Conversations
        </h2>

        {/*
          Le composant reste bete : il ne cree rien, il signale l'intention.
          C'est `Workspace` qui ouvre le dialogue et `useAppState` qui cree.
        */}
        <button
          type="button"
          onClick={onNewConversation}
          aria-label="Nouvelle conversation"
          className="rounded border border-slate-300 px-2 py-1 text-sm text-slate-600 hover:bg-slate-50"
        >
          +
        </button>
      </div>

      {conversations.length === 0 && (
        <p className="px-4 py-2 text-sm text-slate-400">Aucune conversation.</p>
      )}

      <ul>
        {conversations.map((conversation) => {
          const active = conversation.id === selectedId;

          return (
            <li key={conversation.id}>
              <button
                type="button"
                onClick={() => onSelect(conversation.id)}
                aria-current={active ? 'true' : undefined}
                className={`flex w-full flex-col gap-1 border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50 ${
                  active ? 'bg-slate-100' : ''
                }`}
              >
                <span className="flex items-baseline justify-between gap-2">
                  <span className="flex min-w-0 items-center gap-1.5">
                    {/*
                      Uniquement sur un direct : dans un groupe, une pastille
                      unique ne saurait pas de quel membre elle parle.
                    */}
                    {conversation.type === 'direct' && (
                      <PresenceDot online={onlineUserIds.has(peers[conversation.id] ?? '')} />
                    )}
                    <span className="truncate font-medium text-slate-900">
                      {conversationTitle(conversation, users, peers)}
                    </span>
                  </span>
                  <span className="flex shrink-0 items-center gap-1 text-xs text-slate-400">
                    {conversation.last_message_at
                      ? formatListDate(conversation.last_message_at, timeZone, locale)
                      : ''}

                    {conversation.unread_count > 0 && (
                      <span
                        className="ml-2 rounded-full bg-sky-600 px-2 py-0.5 text-xs font-medium text-white"
                        aria-label={`${conversation.unread_count} messages non lus`}
                      >
                        {conversation.unread_count > 99 ? '99+' : conversation.unread_count}
                      </span>
                    )}
                  </span>
                </span>

                <span className="truncate text-sm text-slate-500">
                  {conversation.last_message_preview ?? 'Aucun message'}
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
