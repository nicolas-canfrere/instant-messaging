import { useEffect, useState } from 'react';
import { api } from './api/client';
import { ProblemError } from './api/problem';
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

  // La deconnexion vit ici et non dans `Workspace` : c'est `App` qui possede
  // l'etat de session, et lui seul peut repasser en anonyme. Demonter
  // `Workspace` ferme l'`EventSource` par le cleanup de `useAppState` — il n'y
  // a rien de plus a fermer a la main.
  async function logout() {
    try {
      await api.logout();
      setSession({ state: 'anonymous' });
    } catch (cause) {
      // On RESTE connecte sur un echec : la session serveur est encore valide.
      // Basculer en anonyme afficherait l'ecran de connexion alors que le
      // cookie authentifie toujours, et le moindre rechargement reconnecterait
      // tout seul — plus deroutant que l'erreur elle-meme.
      window.alert(cause instanceof ProblemError ? cause.detail : 'Deconnexion impossible.');
    }
  }

  return <Workspace me={session.me} onLogout={() => void logout()} />;
}

/**
 * Composant separe et non un simple bloc de `App` : `useAppState` ouvre le flux
 * temps reel, or les hooks ne peuvent pas etre appeles conditionnellement. Le
 * monter seulement une fois authentifie garantit qu'aucun EventSource n'est
 * ouvert avant que la session soit connue.
 */
function Workspace({ me, onLogout }: { me: Me; onLogout: () => void }) {
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
    deleteMessage,
    editMessage,
    createDirect,
    createGroup,
    leaveConversation,
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
        me={me}
        onSelect={selectConversation}
        onNewConversation={() => setCreating(true)}
        onLogout={onLogout}
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
          onSend={(content, media) => send(selected.id, content, media)}
          // Pas d'`await` ici : la suppression est declenchee depuis un
          // `onClick`, qui ne peut pas attendre une promesse. Mais contrairement
          // a l'ACK de livraison (qui se rattrape au message suivant, voir
          // `api.receipts` dans `useAppState`), une suppression est une action
          // EXPLICITE de l'utilisateur : un echec avale en silence le laisserait
          // croire que son message a disparu pour tout le monde alors que ce
          // n'est pas le cas. On l'avertit donc via une alerte plutot que
          // d'ignorer le rejet.
          onDeleteMessage={(messageId) => {
            void deleteMessage(selected.id, messageId).catch((cause: unknown) => {
              window.alert(
                cause instanceof ProblemError ? cause.detail : 'Suppression impossible.',
              );
            });
          }}
          // Meme motif que la suppression ci-dessus : une edition est un geste
          // explicite (l'utilisateur vient de taper un texte corrige). L'avaler
          // en silence laisserait croire que la correction est visible de tous
          // alors qu'elle n'a jamais quitte le navigateur — pire que de ne rien
          // avoir change.
          onEditMessage={(messageId, content) => {
            void editMessage(selected.id, messageId, content).catch((cause: unknown) => {
              window.alert(
                cause instanceof ProblemError ? cause.detail : 'Modification impossible.',
              );
            });
          }}
          onTyping={() => notifyTyping(selected.id)}
          // Pas de `window.alert` ici, contrairement a `onDeleteMessage` et
          // `onEditMessage` ci-dessus : la promesse est rendue telle quelle au
          // panneau, qui affiche l'echec dans son propre `role="alert"`.
          onLeave={() => leaveConversation(selected.id)}
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
