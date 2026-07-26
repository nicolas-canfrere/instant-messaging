import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { ulid } from 'ulid';
import { api } from '../api/client';
import { ProblemError } from '../api/problem';
import { retryWithBackoff } from '../api/retry';
import type { ApiMessage, ConversationSummary, Me, UserSummary } from '../api/types';
import { RealtimeClient, type EventSourceLike } from '../realtime/RealtimeClient';
import {
  emptyMessagesState,
  messagesReducer,
  selectThread,
  type MessagesState,
  type StoredMessage,
} from '../store/messagesReducer';
import { emptyPresenceState, presenceReducer } from '../store/presenceReducer';
import {
  emptyReceiptsState,
  receiptsReducer,
  type ReceiptsState,
} from '../store/receiptsReducer';
import { useReadWatermark } from './useReadWatermark';
import { emptyTypingState, typingReducer, type TypingState } from '../store/typingReducer';
import { useHeartbeat } from './useHeartbeat';
import { useTyping } from './useTyping';

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
    editedAt: message.edited_at,
    deletedAt: message.deleted_at,
    status: 'sent',
  };
}

/**
 * Types d'evenements SSE nommes emis par le backend. Mercure recopie le `type`
 * de l'Update dans le champ `event:` du flux ; or `EventSource.onmessage` ne se
 * declenche QUE pour les evenements sans nom. Sans ces ecoutes explicites, le
 * front resterait muet alors que le hub diffuse correctement.
 */
const NAMED_EVENTS = [
  'message.created',
  'membership.changed',
  'typing.started',
  'receipt.updated',
  'message.deleted',
  'message.edited',
];

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

/** Comme `readString`, mais un champ absent ou nul reste `null` — un curseur jamais atteint. */
function readNullableString(payload: Record<string, unknown>, key: string): string | null {
  const value = payload[key];

  return typeof value === 'string' ? value : null;
}

/**
 * Charge utile reellement publiee par le backend pour `message.created`
 * (voir `backend/src/Realtime/Application/EventListener/PublishMessageWasSentListener.php`) :
 * `{ id, conversation_id, sender_id, content, client_message_id, created_at }`.
 *
 * Le `client_message_id` transporte est celui que l'expediteur a genere avant
 * son envoi : c'est lui qui rend effective la PREMIERE passe de deduplication
 * du reducer. Sans lui, un expediteur dont l'echo SSE arrive avant la reponse
 * du POST verrait son propre message deux fois — une fois en optimiste, une
 * fois en recu — jusqu'a l'acquittement.
 *
 * Un message venu d'un autre utilisateur porte un `client_message_id` qui ne
 * correspond a aucun envoi local : la passe 1 ne matche rien, la passe 2 (par
 * `id` serveur) suffit. Le repli sur l'ULID du message ne sert donc que si le
 * champ manquait, ce que le backend ne fait plus.
 */
function toStoredMessage(payload: Record<string, unknown>): StoredMessage {
  const id = readString(payload, 'id');
  const clientMessageId = readString(payload, 'client_message_id');

  return {
    id,
    clientMessageId: clientMessageId === '' ? id : clientMessageId,
    conversationId: readString(payload, 'conversation_id'),
    senderId: readString(payload, 'sender_id'),
    content: readNullableString(payload, 'content'),
    createdAt: readString(payload, 'created_at'),
    editedAt: readNullableString(payload, 'edited_at'),
    deletedAt: readNullableString(payload, 'deleted_at'),
    status: 'sent',
  };
}

/**
 * Delai d'anti-rebond du rafraichissement de la liste des conversations.
 *
 * Chaque `message.created` change le dernier message affiche dans la colonne de
 * gauche, mais une rafale de vingt messages dans un groupe actif n'a pas besoin
 * de vingt `GET /api/conversations` : un seul, une fois la rafale retombee,
 * donne le meme resultat. 300 ms est sous le seuil de perception.
 */
const CONVERSATIONS_REFRESH_DEBOUNCE_MS = 300;

/**
 * Le navigateur n'a pas de logger : `console.error` est ici l'equivalent d'un
 * `error` PSR-3, et introduire une dependance de journalisation pour trois
 * appels n'aurait aucun sens.
 *
 * Comme cote backend, on ne journalise que des faits et des identifiants :
 * jamais le contenu d'un message, jamais la charge utile brute d'un evenement.
 * D'ou le tri ci-dessous : le `detail` d'un ProblemError vient du serveur et est
 * sur, tandis que le message d'un `SyntaxError` de `JSON.parse` recopie un
 * morceau de la charge utile — donc, potentiellement, du texte d'un message.
 * Dans ce cas on ne garde que le nom de l'erreur.
 */
function reportRealtimeIssue(reason: string, cause: unknown): void {
  let detail = 'cause inconnue';

  if (cause instanceof ProblemError) {
    detail = `${cause.status} ${cause.detail}`;
  } else if (cause instanceof Error) {
    detail = cause.name;
  }

  console.error(`[temps reel] ${reason} : ${detail}`);
}

export type AppState = {
  me: Me;
  users: Record<string, UserSummary>;
  /** conversationId -> identifiant de l'interlocuteur, pour les conversations directes. */
  peers: Record<string, string>;
  conversations: ConversationSummary[];
  selectedId: string | null;
  messagesState: MessagesState;
  onlineUserIds: Set<string>;
  typingState: TypingState;
  receiptsState: ReceiptsState;
  notifyTyping: (conversationId: string) => void;
  selectConversation: (conversationId: string) => void;
  loadOlder: () => void;
  refreshConversations: () => Promise<void>;
  send: (conversationId: string, content: string) => Promise<void>;
  deleteMessage: (conversationId: string, messageId: string) => Promise<void>;
  editMessage: (conversationId: string, messageId: string, content: string) => Promise<void>;
  createDirect: (peerId: string) => Promise<void>;
  createGroup: (title: string, memberIds: string[]) => Promise<void>;
};

export function useAppState(me: Me): AppState {
  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [users, setUsers] = useState<Record<string, UserSummary>>({});
  const [peers, setPeers] = useState<Record<string, string>>({});
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [messagesState, dispatch] = useReducer(messagesReducer, undefined, emptyMessagesState);
  const [presenceState, dispatchPresence] = useReducer(
    presenceReducer,
    undefined,
    emptyPresenceState,
  );
  const [typingState, dispatchTyping] = useReducer(typingReducer, undefined, emptyTypingState);
  const [receiptsState, dispatchReceipts] = useReducer(
    receiptsReducer,
    undefined,
    emptyReceiptsState,
  );
  const notifyTyping = useTyping();

  const clientRef = useRef<RealtimeClient | null>(null);
  /**
   * Deux gardes de concurrence, en `ref` et non en `state` : les modifier ne doit
   * declencher aucun rendu, et un `state` serait lu en retard par un appel qui
   * survient dans la meme frame (typiquement deux evenements `scroll` d'affilee).
   */
  const loadingRef = useRef<Set<string>>(new Set());
  /** Conversations directes dont le detail a deja ete demande (en vol ou abouti). */
  const peerRequestsRef = useRef<Set<string>>(new Set());
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

  const send = useCallback(
    async (conversationId: string, content: string): Promise<void> => {
      // L'identifiant est genere AVANT le premier envoi et reutilise a l'identique
      // a chaque tentative : c'est la cle d'idempotence. Le regenerer entre deux
      // essais creerait un second message a chaque reessai reussi.
      const clientMessageId = ulid();

      dispatch({
        type: 'message/optimistic',
        message: {
          id: null,
          clientMessageId,
          conversationId,
          senderId: me.id,
          content,
          createdAt: new Date().toISOString(),
          editedAt: null,
          deletedAt: null,
          status: 'pending',
        },
      });

      try {
        const { id } = await retryWithBackoff(
          () => api.sendMessage(conversationId, clientMessageId, content),
          { attempts: 3 },
        );

        dispatch({ type: 'message/acknowledged', conversationId, clientMessageId, serverId: id });
      } catch {
        // Le message reste affiche, marque `failed` : le perdre silencieusement
        // apres que l'utilisateur l'a vu partir serait le pire des comportements.
        dispatch({ type: 'message/failed', conversationId, clientMessageId });
      }
    },
    [me.id],
  );

  const deleteMessage = useCallback(
    async (conversationId: string, messageId: string) => {
      await api.deleteMessage(conversationId, messageId);
      // Pas de dispatch ici : l'echo SSE pose l'etat, et il est idempotent.
      // Si le hub est injoignable, le rechargement de l'historique corrigera.
    },
    [],
  );

  const editMessage = useCallback(
    async (conversationId: string, messageId: string, content: string) => {
      const updated = await api.editMessage(conversationId, messageId, content);

      // La reponse porte le meme etat final que l'echo SSE : l'appliquer ici
      // aussi rend l'edition visible meme si le hub est injoignable, et
      // l'operation est idempotente donc le doublon est sans consequence.
      dispatch({
        type: 'message/edited',
        conversationId,
        id: messageId,
        content: updated.content ?? '',
        editedAt: updated.edited_at ?? '',
      });
    },
    [],
  );

  /**
   * Trois gestes indissociables apres une creation, d'ou cette fonction commune :
   *  1. `resubscribe()` — le JWT courant a ete emis AVANT que la conversation
   *     existe, il ne couvre donc pas son topic. Sans ce renouvellement, aucun
   *     message n'arriverait en temps reel dans la conversation qu'on vient de
   *     creer, jusqu'au rafraichissement periodique du jeton (13 min).
   *  2. rafraichir la liste — sinon la nouvelle conversation n'apparait pas.
   *  3. la selectionner — sans quoi l'utilisateur aurait cree dans le vide et
   *     devrait la retrouver lui-meme dans la colonne de gauche.
   *
   * Aucun de ces trois gestes ne peut faire echouer la creation : quand on
   * arrive ici, le POST a deja repondu, la conversation EXISTE. Laisser le rejet
   * remonter afficherait « Creation impossible. » a l'utilisateur, qui
   * recliquerait — et creerait un second groupe identique, les groupes n'etant
   * pas idempotents cote serveur, contrairement aux directs. On degrade donc :
   * on selectionne quand meme, et l'anomalie part dans la console.
   */
  const afterCreated = useCallback(
    async (conversationId: string): Promise<void> => {
      try {
        // `resubscribe()` ne rejette plus (il signale par `onError`) ; c'est
        // `refreshConversations()` qui peut encore echouer ici.
        await clientRef.current?.resubscribe();
        await refreshConversations();
      } catch (cause) {
        reportRealtimeIssue('gestes de suivi apres creation echoues', cause);
      }

      // Hors du `try` : la selection doit avoir lieu dans tous les cas.
      selectConversation(conversationId);
    },
    [refreshConversations, selectConversation],
  );

  const createDirect = useCallback(
    async (peerId: string): Promise<void> => {
      const { id } = await api.createDirect(peerId);

      await afterCreated(id);
    },
    [afterCreated],
  );

  const createGroup = useCallback(
    async (title: string, memberIds: string[]): Promise<void> => {
      const { id } = await api.createGroup(title, memberIds);

      await afterCreated(id);
    },
    [afterCreated],
  );

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
   *
   * La garde est une `ref` et non l'etat `peers`, pour deux raisons :
   *  - `peers` ne connait que les requetes TERMINEES. Les requetes EN VOL y sont
   *    invisibles, donc chaque nouveau rendu de la liste les relancait toutes ;
   *  - l'effet ecrivait `peers` et en dependait, ce qui le faisait re-tourner a
   *    chaque reponse. Avec vingt directs, cela donnait de l'ordre de 200
   *    `GET /api/conversations/{id}` redondants.
   * `peers` disparait donc des dependances : le seul declencheur legitime est
   * l'arrivee d'une nouvelle liste de conversations.
   */
  useEffect(() => {
    // Pas de drapeau `cancelled` ici, contrairement au chargement initial : la
    // ref rend chaque appel unique pour toute la vie du hook, et ignorer une
    // reponse tardive parce que la liste a change entre-temps la perdrait pour
    // de bon (en StrictMode, le second montage sauterait l'appel deja marque).
    // L'ecriture est idempotente et indexee par conversation : rien a annuler.
    for (const conversation of conversations) {
      // Meme motif que `loadingRef` dans `loadPage`, mais avec sa PROPRE ref :
      // les deux Set sont indexes par `conversationId`, les partager ferait
      // qu'un chargement de page bloquerait la resolution de l'interlocuteur.
      if (conversation.type !== 'direct' || peerRequestsRef.current.has(conversation.id)) {
        continue;
      }

      peerRequestsRef.current.add(conversation.id);

      void api
        .conversation(conversation.id)
        .then((detail) => {
          const peer = detail.members.find((member) => member.user_id !== me.id);
          if (peer === undefined) return;

          setPeers((current) => ({ ...current, [detail.id]: peer.user_id }));
        })
        .catch((cause: unknown) => {
          // On retire la marque : la conversation reste sans nom d'interlocuteur,
          // mais un prochain rafraichissement de la liste retentera. Sans cela,
          // une seule erreur reseau la condamnerait pour toute la session.
          peerRequestsRef.current.delete(conversation.id);
          reportRealtimeIssue('interlocuteur non resolu', cause);
        });
    }
  }, [conversations, me.id]);

  useEffect(() => {
    /**
     * Anti-rebond du rafraichissement de la liste : chaque message recu
     * repousse l'appel plutot que d'en declencher un. Vingt messages d'affilee
     * dans un groupe actif donnent ainsi un seul `GET /api/conversations` au
     * lieu de vingt. Le timer vit dans l'effet (et non dans une ref) parce que
     * sa duree de vie est exactement celle de la connexion temps reel.
     */
    let refreshTimer: ReturnType<typeof setTimeout> | null = null;

    const scheduleConversationsRefresh = () => {
      if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
      }

      refreshTimer = setTimeout(() => {
        refreshTimer = null;

        // Le `.catch` n'est pas decoratif : sans lui, un `GET` en echec devient
        // un rejet non gere, invisible autrement que dans la console du
        // navigateur — et sans indiquer d'ou il vient.
        void refreshConversations().catch((cause: unknown) => {
          reportRealtimeIssue('rafraichissement de la liste echoue', cause);
        });
      }, CONVERSATIONS_REFRESH_DEBOUNCE_MS);
    };

    const client = new RealtimeClient({
      fetchToken: api.realtimeToken,
      createEventSource: createBrowserEventSource,
      onEvent: (event) => {
        if (event.type === 'message.created') {
          dispatch({ type: 'message/received', message: toStoredMessage(event.payload) });
          scheduleConversationsRefresh();

          // Le message est arrive : son auteur n'ecrit plus. Cela remplace un
          // evenement `typing.stopped` que le backend n'emet volontairement pas.
          dispatchTyping({
            type: 'typing/cleared',
            conversationId: readString(event.payload, 'conversation_id'),
            userId: readString(event.payload, 'sender_id'),
          });

          // L'ACK « distribue » se declenche a la RECEPTION SSE, pour TOUTE
          // conversation — y compris celles qu'on n'a pas ouvertes. C'est
          // pourquoi il vit ici, au niveau du client temps reel global, et non
          // dans ConversationView : la vue ne verrait que le fil affiche, et
          // marquerait donc « distribue » une seule conversation sur N.
          const incomingId = readString(event.payload, 'id');
          const incomingConversationId = readString(event.payload, 'conversation_id');

          if (readString(event.payload, 'sender_id') !== me.id && incomingId !== '') {
            void api.receipts(incomingConversationId, { deliveredUpTo: incomingId }).catch(() => {
              // Un ACK perdu se rattrape au message suivant : le curseur est
              // monotone, donc un seul ACK reussi rattrape tous les manques.
            });
          }

          return;
        }

        if (event.type === 'message.deleted') {
          dispatch({
            type: 'message/deleted',
            conversationId: readString(event.payload, 'conversation_id'),
            id: readString(event.payload, 'id'),
            deletedAt: readString(event.payload, 'deleted_at'),
          });

          // L'apercu de la colonne de gauche a change lui aussi.
          scheduleConversationsRefresh();

          return;
        }

        if (event.type === 'message.edited') {
          dispatch({
            type: 'message/edited',
            conversationId: readString(event.payload, 'conversation_id'),
            id: readString(event.payload, 'id'),
            content: readString(event.payload, 'content'),
            editedAt: readString(event.payload, 'edited_at'),
          });

          scheduleConversationsRefresh();

          return;
        }

        if (event.type === 'receipt.updated') {
          dispatchReceipts({
            type: 'receipt/updated',
            conversationId: readString(event.payload, 'conversation_id'),
            userId: readString(event.payload, 'user_id'),
            lastDeliveredMessageId: readNullableString(event.payload, 'last_delivered_message_id'),
            lastReadMessageId: readNullableString(event.payload, 'last_read_message_id'),
          });

          return;
        }

        if (event.type === 'typing.started') {
          const userId = readString(event.payload, 'user_id');

          // Sa propre frappe revient par le hub : l'afficher ferait apparaitre
          // « vous ecrivez… » dans sa propre fenetre.
          if (userId !== me.id) {
            dispatchTyping({
              type: 'typing/started',
              conversationId: readString(event.payload, 'conversation_id'),
              userId,
              now: Date.now(),
            });
          }

          return;
        }

        if (event.type === 'membership.changed') {
          // Quelqu un nous a ajoute (ou retire) : notre JWT ne couvre pas encore
          // ce topic. On en redemande un et on rouvre le flux.
          void client.resubscribe();
          scheduleConversationsRefresh();
        }
      },
      // Sans ce branchement, un flux interrompu ou une charge utile illisible
      // etaient avales sans la moindre trace : l'interface restait parfaite et
      // silencieuse. On ne journalise ni le contenu ni la charge utile brute
      // (voir `reportRealtimeIssue`).
      onError: (cause) => reportRealtimeIssue('flux temps reel en defaut', cause),
    });

    void client.start();
    clientRef.current = client;

    // Le nettoyage ferme l'EventSource. En mode strict, React monte l'effet deux
    // fois : sans ce `stop()`, le premier flux resterait ouvert pour toujours.
    // `[]` (avec `refreshConversations` stable) est volontaire — un tableau de
    // dependances changeant fermerait et rouvrirait le flux a chaque rendu.
    return () => {
      if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
      }

      client.stop();
    };
  }, [refreshConversations, me.id]);

  // `useCallback([])` : `dispatchPresence` est stable, le hook ne doit donc pas
  // se remonter a chaque rendu — sinon le battement repartirait de zero.
  const onOnlineUserIds = useCallback((ids: string[]) => {
    dispatchPresence({ type: 'presence/refreshed', onlineUserIds: ids });
  }, []);

  useHeartbeat(onOnlineUserIds);

  // Dernier message SERVEUR du fil ouvert : un envoi optimiste n'a pas encore
  // d'id, et un curseur ne peut pas designer un message que le serveur ignore.
  const lastServerMessageId =
    selectedId === null
      ? null
      : (selectThread(messagesState, selectedId)
          .items.filter((item) => item.id !== null)
          .at(-1)?.id ?? null);

  useReadWatermark(selectedId, lastServerMessageId);

  return {
    me,
    users,
    peers,
    conversations,
    selectedId,
    messagesState,
    onlineUserIds: presenceState.onlineUserIds,
    typingState,
    receiptsState,
    notifyTyping,
    selectConversation,
    loadOlder,
    refreshConversations,
    send,
    deleteMessage,
    editMessage,
    createDirect,
    createGroup,
  };
}
