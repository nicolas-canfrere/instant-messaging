import type { ConversationSummary, UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import { conversationTitle } from './labels';
import { MessageList } from './MessageList';

type Props = {
  conversation: ConversationSummary;
  thread: Thread;
  users: Record<string, UserSummary>;
  peers: Record<string, string>;
  meId: string;
  onLoadOlder: () => void;
};

export function ConversationView({
  conversation,
  thread,
  users,
  peers,
  meId,
  onLoadOlder,
}: Props) {
  return (
    <section className="flex flex-1 flex-col bg-slate-50">
      <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
        <h2 className="truncate font-semibold text-slate-900">
          {conversationTitle(conversation, users, peers)}
        </h2>

        {/*
          Le panneau des membres est la tache 17. On garde l'emplacement, mais
          le bouton est desactive : afficher un bouton cliquable qui ne fait
          rien serait mentir a l'utilisateur, le retirer ferait bouger la mise
          en page a la tache suivante.
        */}
        {conversation.type === 'group' && (
          <button
            type="button"
            disabled
            title="Disponible prochainement"
            className="rounded border border-slate-300 px-2 py-1 text-sm text-slate-500 disabled:opacity-50"
          >
            Membres
          </button>
        )}
      </header>

      {/*
        `key` force le remontage au changement de conversation : c'est ce qui
        remet a zero l'ancre de scroll et le suivi du bas de fil, tous deux
        stockes en `ref` dans MessageList.
      */}
      <MessageList
        key={conversation.id}
        thread={thread}
        users={users}
        meId={meId}
        onLoadOlder={onLoadOlder}
      />

      {/* Le Composer (saisie et envoi optimiste) arrive a la tache 17. */}
    </section>
  );
}
