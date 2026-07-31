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

export type MediaStatus = 'pending' | 'processing' | 'ready' | 'rejected';

/**
 * Une image portee par un message, cote store.
 *
 * `previewUrl` n'a pas d'equivalent serveur : c'est la `blob:` URL locale de
 * l'expediteur, la seule chose affichable tant que le worker n'a pas produit de
 * miniature. Elle est nulle chez tous les AUTRES membres du fil, qui n'ont pas
 * les octets — eux voient un emplacement en attente jusqu'a `message.media_ready`.
 */
export type StoredMedia = {
  id: string;
  status: MediaStatus;
  url: string | null;
  thumbnailUrl: string | null;
  width: number | null;
  height: number | null;
  previewUrl: string | null;
};

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
  /** Vide pour un message texte-seul. */
  media: StoredMedia[];
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
  | { type: 'message/deleted'; conversationId: string; id: string; deletedAt: string }
  | {
      type: 'message/edited';
      conversationId: string;
      id: string;
      content: string;
      // Nullable, parce que le serveur peut legitimement le renvoyer nul : une
      // edition qui ne change rien est un no-op cote agregat, `edited_at` reste
      // donc `null` en base. Un type `string` obligeait l'appelant a inventer
      // une chaine vide, que `MessageList` lisait comme « modifie » puisqu'elle
      // n'est pas `null`. Le type doit pouvoir dire ce que le serveur dit.
      editedAt: string | null;
    }
  | {
      type: 'media/ready';
      conversationId: string;
      messageId: string;
      /**
       * La vue COMPLETE du media, telle que le serveur la resigne au moment de
       * pousser : elle remplace l'entree existante en bloc plutot que de la
       * rapiecer champ par champ. Un media passe de `processing` a `ready` ou
       * `rejected` d'un seul coup, jamais a moitie.
       */
      media: StoredMedia;
    };

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
    const existing = items[byClientId];
    const merged = [...items];

    merged[byClientId] = {
      ...incoming,
      status: 'sent',
      // Les medias de l'envoi optimiste SURVIVENT a l'echo SSE. La charge utile
      // de `message.created` ne les porte pas — au moment ou elle part, le
      // worker n'a rien inspecte — donc la recopier telle quelle effacerait les
      // apercus locaux au moment meme ou le serveur confirme l'envoi.
      //
      // Une liste NON vide gagne toujours : c'est le cas d'une page
      // d'historique rechargee, ou le serveur fait autorite.
      media: incoming.media.length > 0 ? incoming.media : (existing?.media ?? []),
    };

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

    case 'media/ready': {
      // Pas de `patchThread` ici, et c'est le point de ce cas : cet evenement
      // arrive pour TOUTES les conversations auxquelles on est abonne, y
      // compris celles dont le fil n'a jamais ete charge. `patchThread`
      // fabriquerait un fil vide pour chacune d'elles, et rendrait surtout un
      // `state` neuf a chaque fois — donc un re-rendu de toute la liste pour
      // une image qui n'est affichee nulle part.
      const thread = state.threads[action.conversationId];

      if (thread === undefined) {
        return state;
      }

      let changed = false;

      const items = thread.items.map((item) => {
        // Par `id` SERVEUR : le message est deja persiste quand cet evenement
        // part, il n'y a donc pas de passe `client_message_id` a faire.
        if (item.id !== action.messageId) {
          return item;
        }

        const known = item.media.some((existing) => existing.id === action.media.id);

        changed = true;

        // Le media n'est pas force d'etre deja la, et c'est le cas NORMAL chez
        // les destinataires : `message.created` ne porte aucun media, leur
        // bulle arrive donc vide. C'est cet evenement-ci qui la remplit.
        //
        // L'ordre est celui d'arrivee, faute de mieux : la charge utile ne
        // transporte pas `position`. Avec plusieurs images d'un meme message,
        // elles peuvent donc s'afficher dans un autre ordre que chez
        // l'expediteur, jusqu'au prochain chargement d'historique qui remet la
        // liste dans l'ordre du serveur.
        const media = known
          ? item.media.map((existing) => (existing.id === action.media.id ? action.media : existing))
          : [...item.media, action.media];

        return { ...item, media };
      });

      // Meme REFERENCE quand l'evenement ne concerne rien d'affiche : message
      // absent du fil, ou media absent de ce message. React ne re-rend alors
      // pas. Un media retrouve est en revanche remplace meme s'il est
      // identique — comparer champ a champ pour economiser un rendu de plus
      // couterait plus cher que le rendu evite.
      return changed
        ? { threads: { ...state.threads, [action.conversationId]: { ...thread, items } } }
        : state;
    }

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

    case 'message/edited':
      // Meme motif que `message/deleted` : reconciliation par `id` SERVEUR,
      // et un `id` absent du fil est ignore silencieusement (le message n'a
      // jamais ete charge dans ce thread).
      //
      // `deletedAt !== null` laisse l'item INTACT, et c'est un invariant, pas
      // une optimisation : une retractation ne se defait pas. SSE ne garantit
      // aucun ordre entre deux evenements distincts, un echo d'edition peut
      // donc arriver apres un echo de suppression — remettre `content` ferait
      // alors ressusciter dans le store une charge utile que le serveur a
      // reellement effacee, contredisant « record soft, payload hard ».
      return patchThread(state, action.conversationId, (thread) => ({
        ...thread,
        items: thread.items.map((item) =>
          item.id === action.id && item.deletedAt === null
            ? { ...item, content: action.content, editedAt: action.editedAt }
            : item,
        ),
      }));
  }
}
