import { useState } from 'react';
import type { ConversationSummary, UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import type { ReceiptsState } from '../store/receiptsReducer';
import type { TypingState } from '../store/typingReducer';
import { Composer } from './Composer';
import { conversationTitle } from './labels';
import { MembersPanel } from './MembersPanel';
import { MessageList } from './MessageList';
import { TypingIndicator } from './TypingIndicator';

type Props = {
  conversation: ConversationSummary;
  thread: Thread;
  users: Record<string, UserSummary>;
  peers: Record<string, string>;
  meId: string;
  typingState: TypingState;
  receiptsState: ReceiptsState;
  onLoadOlder: () => void;
  onSend: (content: string) => Promise<void>;
  onTyping: () => void;
};

export function ConversationView({
  conversation,
  thread,
  users,
  peers,
  meId,
  typingState,
  receiptsState,
  onLoadOlder,
  onSend,
  onTyping,
}: Props) {
  // Etat purement local d'affichage : personne d'autre n'a besoin de savoir si
  // le panneau des membres est ouvert. Il vit donc ici, pas dans `useAppState`.
  const [showMembers, setShowMembers] = useState(false);

  return (
    <>
      <section className="flex flex-1 flex-col bg-slate-50">
        <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
          <h2 className="truncate font-semibold text-slate-900">
            {conversationTitle(conversation, users, peers)}
          </h2>

          {/* Seul un groupe a des membres a montrer : un direct en a toujours deux. */}
          {conversation.type === 'group' && (
            <button
              type="button"
              onClick={() => setShowMembers((open) => !open)}
              aria-expanded={showMembers}
              className="rounded border border-slate-300 px-2 py-1 text-sm text-slate-600 hover:bg-slate-50"
            >
              Membres
            </button>
          )}
        </header>

        {/*
          `key` force le remontage au changement de conversation : c'est ce qui
          remet a zero l'ancre de scroll et le suivi du bas de fil, tous deux
          stockes en `ref` dans MessageList.

          Le prefixe n'est pas cosmetique. `<section>` a plusieurs enfants
          statiques : React les reconcilie comme un TABLEAU, ou les `key` doivent
          etre uniques ENTRE FRERES. Ce composant et le Composer plus bas ont tous
          deux besoin de se remonter au changement de conversation, donc tous deux
          d'une `key` qui en depend — mais la meme des deux cotes rendait
          l'appariement ancienne/nouvelle liste indetermine : React retrouvait la
          fibre du Composer la ou il cherchait celle-ci, ne marquait jamais
          l'ancienne MessageList pour suppression, et en montait une seconde a
          cote. Chaque aller-retour entre deux conversations empilait ainsi un fil
          de plus dans le DOM.
        */}
        <MessageList
          key={`messages-${conversation.id}`}
          thread={thread}
          users={users}
          meId={meId}
          conversationId={conversation.id}
          receiptsState={receiptsState}
          isGroup={conversation.type === 'group'}
          onLoadOlder={onLoadOlder}
        />

        {/*
          `key` ici aussi : changer de conversation doit vider la zone de saisie.
          Sans cela, un brouillon commence dans un fil suivrait l'utilisateur dans
          le suivant et partirait au mauvais destinataire.

          Prefixe different de celui du MessageList ci-dessus : voir l'explication
          la-bas. Deux freres ne peuvent pas porter la meme `key`.
        */}
        {/*
          Entre le fil et la saisie, comme dans toutes les messageries : la ligne
          apparait et disparait sans jamais deplacer le champ de saisie.
        */}
        <TypingIndicator
          typingState={typingState}
          conversationId={conversation.id}
          users={users}
        />

        <Composer key={`composer-${conversation.id}`} onSend={onSend} onTyping={onTyping} />
      </section>

      {conversation.type === 'group' && showMembers && (
        <MembersPanel
          conversationId={conversation.id}
          users={users}
          onClose={() => setShowMembers(false)}
        />
      )}
    </>
  );
}
