/**
 * Reducer pur : aucune dependance a React, donc testable en appelant une fonction.
 *
 * Deux invariants portent toute la complexite :
 *  - la deduplication se fait en DEUX passes (client_message_id puis id serveur),
 *    parce que l'expediteur recoit son propre message par la reponse HTTP ET par SSE ;
 *  - les messages sont tries par ULID croissant, les envois en attente restant
 *    en fin de liste puisqu'ils n'ont pas encore d'identifiant serveur.
 */

export type MessageStatus = 'pending' | 'sent' | 'failed';

export type StoredMessage = {
  id: string | null;
  clientMessageId: string;
  conversationId: string;
  senderId: string;
  content: string | null;
  createdAt: string;
  editedAt: string | null;
  deletedAt: string | null;
  status: MessageStatus;
};

export type Thread = {
  items: StoredMessage[];
  nextBefore: string | null;
  loaded: boolean;
};

export type MessagesState = { threads: Record<string, Thread> };

export type MessagesAction =
  | { type: 'page/loaded'; conversationId: string; items: StoredMessage[]; nextBefore: string | null }
  | { type: 'message/optimistic'; message: StoredMessage }
  | { type: 'message/acknowledged'; conversationId: string; clientMessageId: string; serverId: string }
  | { type: 'message/failed'; conversationId: string; clientMessageId: string }
  | { type: 'message/received'; message: StoredMessage }
  | { type: 'message/deleted'; conversationId: string; id: string; deletedAt: string };

const EMPTY_THREAD: Thread = { items: [], nextBefore: null, loaded: false };

export function emptyMessagesState(): MessagesState {
  return { threads: {} };
}

export function selectThread(state: MessagesState, conversationId: string): Thread {
  return state.threads[conversationId] ?? EMPTY_THREAD;
}

/** Les messages en attente n'ont pas d'id serveur : ils passent toujours en dernier. */
function compare(a: StoredMessage, b: StoredMessage): number {
  if (a.id === null && b.id === null) return a.clientMessageId.localeCompare(b.clientMessageId);
  if (a.id === null) return 1;
  if (b.id === null) return -1;

  return a.id.localeCompare(b.id);
}

function upsert(items: StoredMessage[], incoming: StoredMessage): StoredMessage[] {
  // Passe 1 : le message correspond-il a un envoi optimiste en cours ?
  const byClientId = items.findIndex((item) => item.clientMessageId === incoming.clientMessageId);

  if (byClientId !== -1) {
    const merged = [...items];
    merged[byClientId] = { ...incoming, status: 'sent' };

    return merged.sort(compare);
  }

  // Passe 2 : deja recu par un autre canal ?
  if (incoming.id !== null && items.some((item) => item.id === incoming.id)) {
    return items;
  }

  return [...items, incoming].sort(compare);
}

function patchThread(
  state: MessagesState,
  conversationId: string,
  patch: (thread: Thread) => Thread,
): MessagesState {
  const current = state.threads[conversationId] ?? EMPTY_THREAD;

  return { threads: { ...state.threads, [conversationId]: patch(current) } };
}

export function messagesReducer(state: MessagesState, action: MessagesAction): MessagesState {
  switch (action.type) {
    case 'page/loaded':
      return patchThread(state, action.conversationId, (thread) => ({
        items: action.items.reduce(upsert, thread.items),
        nextBefore: action.nextBefore,
        loaded: true,
      }));

    case 'message/optimistic':
    case 'message/received':
      return patchThread(state, action.message.conversationId, (thread) => ({
        ...thread,
        items: upsert(thread.items, action.message),
      }));

    case 'message/acknowledged':
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items
          .map((item) =>
            item.clientMessageId === action.clientMessageId
              ? { ...item, id: action.serverId, status: 'sent' as const }
              : item,
          )
          .sort(compare),
      }));

    case 'message/failed':
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.clientMessageId === action.clientMessageId
            ? { ...item, status: 'failed' as const }
            : item,
        ),
      }));

    case 'message/deleted':
      // Applique par `id` SERVEUR : contrairement a l'envoi, il n'y a pas de
      // passe `client_message_id` a faire — le message est deja persiste, donc
      // la cle de reconciliation existe.
      //
      // Un `id` absent du fil ne declenche rien : le message n'a jamais ete
      // charge, et la page d'historique qui le contiendra le lira deja a jour.
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.id === action.id
            ? { ...item, content: null, deletedAt: action.deletedAt }
            : item,
        ),
      }));
  }
}
