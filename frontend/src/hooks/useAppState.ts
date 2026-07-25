import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { api } from '../api/client';
import type { ApiMessage, ConversationSummary, Me, UserSummary } from '../api/types';
import { RealtimeClient, type EventSourceLike } from '../realtime/RealtimeClient';
import {
  emptyMessagesState,
  messagesReducer,
  selectThread,
  type MessagesState,
  type StoredMessage,
} from '../store/messagesReducer';

/**
 * Un message d'historique est deja accepte par le serveur : son statut est
 * `sent`, jamais `pending`. C'est aussi le seul endroit ou l'on connait le
 * vrai `client_message_id`, ce qui permet au reducer de reconcilier un envoi
 * optimiste avec sa version persistee.
 */
function fromApiMessage(message: ApiMessage): StoredMessage {
  return {
    id: message.id,
    clientMessageId: message.client_message_id,
    conversationId: message.conversation_id,
    senderId: message.sender_id,
    content: message.content,
    createdAt: message.created_at,
    status: 'sent',
  };
}

/**
 * Types d'evenements SSE nommes emis par le backend. Mercure recopie le `type`
 * de l'Update dans le champ `event:` du flux ; or `EventSource.onmessage` ne se
 * declenche QUE pour les evenements sans nom. Sans ces ecoutes explicites, le
 * front resterait muet alors que le hub diffuse correctement.
 */
const NAMED_EVENTS = ['message.created', 'membership.changed'];

/**
 * Adaptateur entre l'EventSource du navigateur et le port minimal attendu par
 * RealtimeClient. Deux raisons de le poser ici plutot que de passer l'objet natif :
 *  - les signatures different (le natif recoit un MessageEvent complet) ;
 *  - c'est le seul endroit ou traduire « evenement nomme » en « onmessage »,
 *    ce qui garde RealtimeClient ignorant des subtilites du transport.
 */
function createBrowserEventSource(url: string): EventSourceLike {
  const source = new EventSource(url, { withCredentials: true });

  const adapter: EventSourceLike = {
    onmessage: null,
    onerror: null,
    close: () => source.close(),
  };

  const forward = (event: MessageEvent<string>) => {
    adapter.onmessage?.({ data: event.data, lastEventId: event.lastEventId });
  };

  // Un evenement donne arrive soit nomme, soit anonyme, jamais les deux :
  // ecouter les deux canaux ne peut pas produire de doublon.
  source.onmessage = forward;
  for (const name of NAMED_EVENTS) {
    source.addEventListener(name, forward);
  }

  source.onerror = () => adapter.onerror?.();

  return adapter;
}

/** La charge utile SSE est un `Record<string, unknown>` : on la retrecit ici, une seule fois. */
function readString(payload: Record<string, unknown>, key: string): string {
  const value = payload[key];

  return typeof value === 'string' ? value : '';
}

/**
 * Charge utile reellement publiee par le backend pour `message.created`
 * (voir `backend/src/Realtime/Application/EventListener/PublishMessageWasSentListener.php`) :
 * `{ id, conversation_id, sender_id, content, created_at }`.
 *
 * Elle ne transporte PAS de `client_message_id`. On retombe donc sur l'ULID du
 * message comme identifiant client : la premiere passe de deduplication du
 * reducer (par `clientMessageId`) ne peut pas matcher, mais la seconde (par
 * `id` serveur) le fait des que la reponse HTTP de l'envoi a ete acquittee.
 */
function toStoredMessage(payload: Record<string, unknown>): StoredMessage {
  const id = readString(payload, 'id');

  return {
    id,
    clientMessageId: id,
    conversationId: readString(payload, 'conversation_id'),
    senderId: readString(payload, 'sender_id'),
    content: readString(payload, 'content'),
    createdAt: readString(payload, 'created_at'),
    status: 'sent',
  };
}

export type AppState = {
  me: Me;
  users: Record<string, UserSummary>;
  /** conversationId -> identifiant de l'interlocuteur, pour les conversations directes. */
  peers: Record<string, string>;
  conversations: ConversationSummary[];
  selectedId: string | null;
  messagesState: MessagesState;
  selectConversation: (conversationId: string) => void;
  loadOlder: () => void;
  refreshConversations: () => Promise<void>;
};

export function useAppState(me: Me): AppState {
  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [users, setUsers] = useState<Record<string, UserSummary>>({});
  const [peers, setPeers] = useState<Record<string, string>>({});
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [messagesState, dispatch] = useReducer(messagesReducer, undefined, emptyMessagesState);

  const clientRef = useRef<RealtimeClient | null>(null);
  /**
   * Deux gardes de concurrence, en `ref` et non en `state` : les modifier ne doit
   * declencher aucun rendu, et un `state` serait lu en retard par un appel qui
   * survient dans la meme frame (typiquement deux evenements `scroll` d'affilee).
   */
  const loadingRef = useRef<Set<string>>(new Set());
  /** Lu par des callbacks dont l'identite doit rester stable : on passe par une ref. */
  const messagesStateRef = useRef(messagesState);
  messagesStateRef.current = messagesState;
  const selectedIdRef = useRef(selectedId);
  selectedIdRef.current = selectedId;

  // `useCallback([])` ici n'est pas de la micro-optimisation : c'est ce qui rend
  // honnete le `[]` de l'effet temps reel plus bas. `api` et `setConversations`
  // sont stables, la fonction n'a donc aucune dependance a declarer.
  const refreshConversations = useCallback(async () => {
    setConversations(await api.conversations());
  }, []);

  const loadPage = useCallback(async (conversationId: string, before?: string) => {
    // Une page deja en vol pour cette conversation : on ne la redemande pas.
    // Sans cela, le scroll declencherait dix appels identiques d'affilee.
    if (loadingRef.current.has(conversationId)) {
      return;
    }

    loadingRef.current.add(conversationId);

    try {
      const page = await api.messages(conversationId, before);

      dispatch({
        type: 'page/loaded',
        conversationId,
        items: page.items.map(fromApiMessage),
        nextBefore: page.next_before,
      });
    } finally {
      loadingRef.current.delete(conversationId);
    }
  }, []);

  const selectConversation = useCallback(
    (conversationId: string) => {
      setSelectedId(conversationId);

      // On ne recharge pas un fil deja charge : le temps reel l'a maintenu a jour
      // pendant qu'on regardait ailleurs.
      if (!selectThread(messagesStateRef.current, conversationId).loaded) {
        void loadPage(conversationId);
      }
    },
    [loadPage],
  );

  const loadOlder = useCallback(() => {
    const conversationId = selectedIdRef.current;
    if (conversationId === null) return;

    const thread = selectThread(messagesStateRef.current, conversationId);

    // `nextBefore === null` signifie « plus rien avant » : c'est la fin de
    // l'historique, pas une erreur. On s'arrete la.
    if (thread.nextBefore === null) return;

    void loadPage(conversationId, thread.nextBefore);
  }, [loadPage]);

  // Chargement initial : la liste des conversations et l'annuaire des utilisateurs.
  // L'annuaire sert a nommer l'interlocuteur d'un direct et l'expediteur d'un
  // message — l'API ne renvoie que des identifiants, jamais des noms.
  useEffect(() => {
    void refreshConversations();

    void api.users().then((list) => {
      setUsers(Object.fromEntries(list.map((user) => [user.id, user])));
    });
  }, [refreshConversations]);

  /**
   * `GET /api/conversations` ne renvoie pas les membres : pour nommer une
   * conversation directe par son interlocuteur, il faut son detail. On ne le
   * demande qu'une fois par conversation directe, et jamais pour un groupe qui
   * porte deja un titre. (Un champ `peer_id` dans la liste supprimerait ces
   * appels — c'est un changement backend, hors du perimetre de cette tache.)
   */
  useEffect(() => {
    let cancelled = false;

    for (const conversation of conversations) {
      if (conversation.type !== 'direct' || peers[conversation.id] !== undefined) {
        continue;
      }

      void api.conversation(conversation.id).then((detail) => {
        const peer = detail.members.find((member) => member.user_id !== me.id);
        if (cancelled || peer === undefined) return;

        setPeers((current) => ({ ...current, [detail.id]: peer.user_id }));
      });
    }

    return () => {
      cancelled = true;
    };
  }, [conversations, peers, me.id]);

  useEffect(() => {
    const client = new RealtimeClient({
      fetchToken: api.realtimeToken,
      createEventSource: createBrowserEventSource,
      onEvent: (event) => {
        if (event.type === 'message.created') {
          dispatch({ type: 'message/received', message: toStoredMessage(event.payload) });
          void refreshConversations();
          return;
        }

        if (event.type === 'membership.changed') {
          // Quelqu un nous a ajoute (ou retire) : notre JWT ne couvre pas encore
          // ce topic. On en redemande un et on rouvre le flux.
          void client.resubscribe();
          void refreshConversations();
        }
      },
    });

    void client.start();
    clientRef.current = client;

    // Le nettoyage ferme l'EventSource. En mode strict, React monte l'effet deux
    // fois : sans ce `stop()`, le premier flux resterait ouvert pour toujours.
    // `[]` (avec `refreshConversations` stable) est volontaire — un tableau de
    // dependances changeant fermerait et rouvrirait le flux a chaque rendu.
    return () => client.stop();
  }, [refreshConversations]);

  return {
    me,
    users,
    peers,
    conversations,
    selectedId,
    messagesState,
    selectConversation,
    loadOlder,
    refreshConversations,
  };
}
