import { useEffect, useState } from 'react';
import { api } from './api/client';
import type { Me } from './api/types';
import { useAppState } from './hooks/useAppState';
import { selectThread } from './store/messagesReducer';
import { ConversationList } from './ui/ConversationList';
import { ConversationView } from './ui/ConversationView';
import { LoginScreen } from './ui/LoginScreen';
import { NewConversationDialog } from './ui/NewConversationDialog';

// Trois etats et non deux : tant que /api/me n'a pas repondu, on ne SAIT pas si
// la session existe. Afficher l'ecran de connexion pendant ce laps de temps le
// ferait clignoter a chaque rechargement d'un utilisateur deja connecte.
type Session = { state: 'loading' } | { state: 'anonymous' } | { state: 'authenticated'; me: Me };

export default function App() {
  const [session, setSession] = useState<Session>({ state: 'loading' });

  useEffect(() => {
    // La session vit dans un cookie que JavaScript ne peut pas lire (HttpOnly) :
    // le seul moyen de savoir si l'on est connecte est de poser la question au
    // serveur. Un 401 remonte en ProblemError, ce qui vaut "anonyme".
    let cancelled = false;

    api
      .me()
      .then((me) => {
        if (!cancelled) setSession({ state: 'authenticated', me });
      })
      .catch(() => {
        if (!cancelled) setSession({ state: 'anonymous' });
      });

    // En mode strict, React monte l'effet deux fois : ce drapeau evite qu'une
    // reponse tardive du premier montage n'ecrase l'etat du second.
    return () => {
      cancelled = true;
    };
  }, []);

  if (session.state === 'loading') {
    return <main className="mt-24 text-center text-slate-500">Chargement…</main>;
  }

  if (session.state === 'anonymous') {
    return <LoginScreen onAuthenticated={(me) => setSession({ state: 'authenticated', me })} />;
  }

  return <Workspace me={session.me} />;
}

/**
 * Composant separe et non un simple bloc de `App` : `useAppState` ouvre le flux
 * temps reel, or les hooks ne peuvent pas etre appeles conditionnellement. Le
 * monter seulement une fois authentifie garantit qu'aucun EventSource n'est
 * ouvert avant que la session soit connue.
 */
function Workspace({ me }: { me: Me }) {
  const {
    users,
    peers,
    conversations,
    selectedId,
    messagesState,
    onlineUserIds,
    typingState,
    receiptsState,
    notifyTyping,
    selectConversation,
    loadOlder,
    send,
    createDirect,
    createGroup,
  } = useAppState(me);

  // Ouverture du dialogue de creation : etat d'affichage, il ne concerne que
  // cet ecran et n'a rien a faire dans `useAppState`.
  const [creating, setCreating] = useState(false);

  const selected = conversations.find((conversation) => conversation.id === selectedId) ?? null;

  return (
    <div className="flex h-screen text-slate-900">
      <ConversationList
        conversations={conversations}
        users={users}
        peers={peers}
        onlineUserIds={onlineUserIds}
        selectedId={selectedId}
        onSelect={selectConversation}
        onNewConversation={() => setCreating(true)}
      />

      {selected === null ? (
        <main className="flex flex-1 items-center justify-center bg-slate-50 text-slate-400">
          Choisissez une conversation.
        </main>
      ) : (
        <ConversationView
          conversation={selected}
          thread={selectThread(messagesState, selected.id)}
          users={users}
          peers={peers}
          meId={me.id}
          typingState={typingState}
          receiptsState={receiptsState}
          onLoadOlder={loadOlder}
          onSend={(content) => send(selected.id, content)}
          onTyping={() => notifyTyping(selected.id)}
        />
      )}

      {creating && (
        <NewConversationDialog
          users={users}
          meId={me.id}
          onCreateDirect={createDirect}
          onCreateGroup={createGroup}
          onClose={() => setCreating(false)}
        />
      )}
    </div>
  );
}
