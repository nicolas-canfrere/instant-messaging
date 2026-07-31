import { useState } from 'react';
import type { ConversationSummary, UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import type { ReceiptsState } from '../store/receiptsReducer';
import type { TypingState } from '../store/typingReducer';
import { useFileDrop } from '../hooks/useFileDrop';
import { useMediaUpload, type TakenMedia } from '../hooks/useMediaUpload';
import { Composer } from './Composer';
import { conversationTitle } from './labels';
import { DropOverlay } from './DropOverlay';
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
  onSend: (content: string, media: TakenMedia[]) => Promise<void>;
  onDeleteMessage: (messageId: string) => void;
  onEditMessage: (messageId: string, content: string) => void;
  onMediaExpired: () => void;
  onTyping: () => void;
  onLeave: () => Promise<void>;
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
  onDeleteMessage,
  onEditMessage,
  onMediaExpired,
  onTyping,
  onLeave,
}: Props) {
  // Etat purement local d'affichage : personne d'autre n'a besoin de savoir si
  // le panneau des membres est ouvert. Il vit donc ici, pas dans `useAppState`.
  const [showMembers, setShowMembers] = useState(false);

  return (
    <>
      {/*
        `key` force le remontage COMPLET au changement de conversation. C'est
        indispensable pour `useMediaUpload` : ce hook vit maintenant dans
        `ConversationBody` (voir plus bas pourquoi), et sans ce remontage ses
        pieces jointes en attente suivraient l'utilisateur d'une conversation
        a l'autre, exactement le bug qu'on evite deja pour le texte du
        brouillon.
      */}
      <ConversationBody
        key={conversation.id}
        conversation={conversation}
        thread={thread}
        users={users}
        peers={peers}
        meId={meId}
        typingState={typingState}
        receiptsState={receiptsState}
        onLoadOlder={onLoadOlder}
        onSend={onSend}
        onDeleteMessage={onDeleteMessage}
        onEditMessage={onEditMessage}
        onMediaExpired={onMediaExpired}
        onTyping={onTyping}
        showMembers={showMembers}
        onToggleMembers={() => setShowMembers((open) => !open)}
      />

      {conversation.type === 'group' && showMembers && (
        <MembersPanel
          conversationId={conversation.id}
          users={users}
          meId={meId}
          onLeave={onLeave}
          onClose={() => setShowMembers(false)}
        />
      )}
    </>
  );
}

type BodyProps = Omit<Props, 'onLeave'> & {
  showMembers: boolean;
  onToggleMembers: () => void;
};

/**
 * Contenu d'une conversation : en-tete, fil, saisie. Separe de
 * `ConversationView` uniquement pour que `key={conversation.id}` ci-dessus
 * le remonte entierement a chaque changement de conversation — un simple
 * `key` sur un `<section>` DOM ne suffirait pas, les hooks appeles par le
 * COMPOSANT PARENT (ici `ConversationView`) ne redemarrent jamais avec lui.
 * Il faut un composant a part entiere.
 */
function ConversationBody({
  conversation,
  thread,
  users,
  peers,
  meId,
  typingState,
  receiptsState,
  onLoadOlder,
  onSend,
  onDeleteMessage,
  onEditMessage,
  onMediaExpired,
  onTyping,
  showMembers,
  onToggleMembers,
}: BodyProps) {
  // Instancie ICI, pas dans `Composer` : le glisser-depose vise toute la
  // conversation (le `<section>` ci-dessous), le selecteur de fichiers vit
  // dans le compositeur plus bas dans l'arbre. Les deux doivent alimenter le
  // MEME etat, sans quoi un fichier depose et un fichier choisi finiraient
  // dans deux listes distinctes — deux chemins d'envoi pour un seul besoin.
  const uploads = useMediaUpload();

  // Le conteneur entier de la conversation est la cible du depot, pas le
  // compositeur : l'utilisateur qui lache un fichier ne vise pas une petite
  // zone, il lache n'importe ou sur le fil. `onFiles` delegue directement a
  // `uploads.add`, le meme point d'entree que le bouton « Fichier » du
  // compositeur.
  const { isDragging, handlers: dropHandlers } = useFileDrop({
    onFiles: (files) => {
      files.forEach((file) => void uploads.add(file));
    },
  });

  return (
    <section
      data-testid="conversation"
      // `relative` : le voile de depot (`DropOverlay`) se positionne en
      // `absolute inset-0` par rapport a CE conteneur, pas a la fenetre.
      className="relative flex flex-1 flex-col bg-slate-50"
      {...dropHandlers}
    >
      {isDragging && <DropOverlay />}

      <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
        <h2 className="truncate font-semibold text-slate-900">
          {conversationTitle(conversation, users, peers)}
        </h2>

        {/* Seul un groupe a des membres a montrer : un direct en a toujours deux. */}
        {conversation.type === 'group' && (
          <button
            type="button"
            onClick={onToggleMembers}
            aria-expanded={showMembers}
            className="rounded border border-slate-300 px-2 py-1 text-sm text-slate-600 hover:bg-slate-50"
          >
            Membres
          </button>
        )}
      </header>

      <MessageList
        thread={thread}
        users={users}
        meId={meId}
        conversationId={conversation.id}
        receiptsState={receiptsState}
        isGroup={conversation.type === 'group'}
        onLoadOlder={onLoadOlder}
        onDeleteMessage={onDeleteMessage}
        onEditMessage={onEditMessage}
        onMediaExpired={onMediaExpired}
      />

      {/*
        Entre le fil et la saisie, comme dans toutes les messageries : la ligne
        apparait et disparait sans jamais deplacer le champ de saisie.
      */}
      <TypingIndicator
        typingState={typingState}
        conversationId={conversation.id}
        users={users}
      />

      <Composer onSend={onSend} onTyping={onTyping} uploads={uploads} />
    </section>
  );
}
