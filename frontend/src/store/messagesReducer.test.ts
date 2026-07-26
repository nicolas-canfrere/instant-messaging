import { describe, expect, it } from 'vitest';
import {
  emptyMessagesState,
  messagesReducer,
  selectThread,
  type StoredMessage,
} from './messagesReducer';

const CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';

function serverMessage(id: string, clientMessageId: string, content = 'texte'): StoredMessage {
  return {
    id,
    clientMessageId,
    conversationId: CONVERSATION,
    senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
    content,
    createdAt: '2026-07-25T10:00:00+00:00',
    editedAt: null,
    deletedAt: null,
    status: 'sent',
  };
}

/**
 * `noUncheckedIndexedAccess` donne le type `StoredMessage | undefined` a tout
 * acces par index. Plutot que de le masquer avec un `!`, ce helper fait echouer
 * le test explicitement quand l'element manque : l'assertion qui suit reste
 * exactement aussi stricte, et le message d'erreur reste lisible.
 */
function at(items: StoredMessage[], index: number): StoredMessage {
  const item = items[index];

  if (item === undefined) {
    throw new Error(`Aucun message a l'index ${index} (liste de ${items.length} element(s)).`);
  }

  return item;
}

/**
 * Fabrique generique pour les tests qui n'ont pas besoin de composer un id
 * serveur et un client_message_id distincts comme `serverMessage` : un
 * `StoredMessage` complet, avec des valeurs par defaut plausibles, fusionne
 * avec les `overrides` fournis par le test.
 */
function aMessage(overrides: Partial<StoredMessage> = {}): StoredMessage {
  return {
    id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    clientMessageId: 'c1',
    conversationId: 'c1',
    senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
    content: 'texte',
    createdAt: '2026-07-25T10:00:00+00:00',
    editedAt: null,
    deletedAt: null,
    status: 'sent',
    ...overrides,
  };
}

describe('messagesReducer', () => {
  it('ordonne les messages par ULID croissant', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c1'),
    });
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'c2'),
    });

    expect(selectThread(state, CONVERSATION).items.map((m) => m.id)).toEqual([
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    ]);
  });

  it('ignore un message deja present, identifie par son id serveur', () => {
    let state = emptyMessagesState();
    const message = serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c1');

    state = messagesReducer(state, { type: 'message/received', message });
    state = messagesReducer(state, { type: 'message/received', message });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
  });

  it('remplace le message optimiste par le message serveur via client_message_id', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        editedAt: null,
        deletedAt: null,
        status: 'pending',
      },
    });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
    expect(at(selectThread(state, CONVERSATION).items, 0).status).toBe('pending');

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'client-1', 'bonjour'),
    });

    const items = selectThread(state, CONVERSATION).items;

    expect(items).toHaveLength(1);
    expect(at(items, 0).id).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAC');
    expect(at(items, 0).status).toBe('sent');
  });

  it('ne duplique pas quand l ACK HTTP et le SSE arrivent tous les deux', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        editedAt: null,
        deletedAt: null,
        status: 'pending',
      },
    });

    // 1. La reponse HTTP arrive.
    state = messagesReducer(state, {
      type: 'message/acknowledged',
      conversationId: CONVERSATION,
      clientMessageId: 'client-1',
      serverId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    });

    // 2. Le meme message revient par SSE.
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'client-1', 'bonjour'),
    });

    expect(selectThread(state, CONVERSATION).items).toHaveLength(1);
  });

  it('insere une page ancienne en tete sans doublon', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c3'),
    });

    state = messagesReducer(state, {
      type: 'page/loaded',
      conversationId: CONVERSATION,
      // L'API renvoie du plus recent au plus ancien, et la page chevauche
      // volontairement le message deja recu par SSE.
      items: [
        serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c3'),
        serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'c2'),
      ],
      nextBefore: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
    });

    const thread = selectThread(state, CONVERSATION);

    expect(thread.items.map((m) => m.id)).toEqual([
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      '01J9ZQ7X8K3M4N5P6Q7R8S9TAC',
    ]);
    expect(thread.nextBefore).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAB');
  });

  it('marque un envoi en echec sans le supprimer', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'bonjour',
        createdAt: '2026-07-25T10:00:00+00:00',
        editedAt: null,
        deletedAt: null,
        status: 'pending',
      },
    });

    state = messagesReducer(state, {
      type: 'message/failed',
      conversationId: CONVERSATION,
      clientMessageId: 'client-1',
    });

    // Le message reste affiche : l'utilisateur doit pouvoir reessayer,
    // et le meme client_message_id garantit l'absence de doublon serveur.
    expect(at(selectThread(state, CONVERSATION).items, 0).status).toBe('failed');
  });

  it('garde les messages en attente en fin de liste', () => {
    let state = emptyMessagesState();

    state = messagesReducer(state, {
      type: 'message/optimistic',
      message: {
        id: null,
        clientMessageId: 'client-1',
        conversationId: CONVERSATION,
        senderId: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
        content: 'en attente',
        createdAt: '2026-07-25T10:00:00+00:00',
        editedAt: null,
        deletedAt: null,
        status: 'pending',
      },
    });
    state = messagesReducer(state, {
      type: 'message/received',
      message: serverMessage('01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'c9'),
    });

    const items = selectThread(state, CONVERSATION).items;

    expect(at(items, items.length - 1).status).toBe('pending');
  });
});

describe('message/deleted', () => {
  it('efface le contenu du message vise et le marque supprime', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'a effacer' }),
    });

    const next = messagesReducer(state, {
      type: 'message/deleted',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      deletedAt: '2026-07-26T11:00:00+00:00',
    });

    const item = at(selectThread(next, 'c1').items, 0);
    expect(item.content).toBeNull();
    expect(item.deletedAt).toBe('2026-07-26T11:00:00+00:00');
  });

  // Le message n'a jamais ete charge : la page d'historique qui le contiendra
  // le lira deja a jour. Ne rien faire est le comportement correct.
  it('ignore un identifiant absent du fil', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'intact' }),
    });

    const next = messagesReducer(state, {
      type: 'message/deleted',
      conversationId: 'c1',
      id: '01J0000000000000000000000Z',
      deletedAt: '2026-07-26T11:00:00+00:00',
    });

    expect(at(selectThread(next, 'c1').items, 0).content).toBe('intact');
  });

  // L'echo SSE arrive avant la reponse du DELETE : appliquer deux fois le meme
  // etat complet doit donner le meme resultat.
  it('est idempotent', () => {
    const base = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'a effacer' }),
    });

    const action = {
      type: 'message/deleted' as const,
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      deletedAt: '2026-07-26T11:00:00+00:00',
    };

    expect(messagesReducer(messagesReducer(base, action), action)).toEqual(
      messagesReducer(base, action),
    );
  });
});

describe('message/edited', () => {
  it('remplace le contenu et marque l instant d edition', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjor' }),
    });

    const next = messagesReducer(state, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'bonjour',
      editedAt: '2026-07-26T09:05:00+00:00',
    });

    const item = at(selectThread(next, 'c1').items, 0);
    expect(item.content).toBe('bonjour');
    expect(item.editedAt).toBe('2026-07-26T09:05:00+00:00');
  });

  /**
   * Le serveur dit la verite, le front la transporte telle quelle. Un `PATCH`
   * sans modification est un no-op cote agregat : la vue revient avec
   * `edited_at: null`, et ce `null` doit arriver intact jusqu'au message. Le
   * reduire a la chaine vide afficherait « · modifie » sur un message jamais
   * modifie, `MessageList` testant `editedAt !== null`.
   */
  it('transporte un editedAt nul sans le transformer en chaine vide', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjour' }),
    });

    const next = messagesReducer(state, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'bonjour',
      editedAt: null,
    });

    expect(at(selectThread(next, 'c1').items, 0).editedAt).toBeNull();
  });

  it('ignore un identifiant absent du fil', () => {
    const state = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'intact' }),
    });

    const next = messagesReducer(state, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000Z',
      content: 'ailleurs',
      editedAt: '2026-07-26T09:05:00+00:00',
    });

    expect(at(selectThread(next, 'c1').items, 0).content).toBe('intact');
  });

  // L'echo SSE et la reponse du PATCH portent le MEME etat final : les
  // appliquer dans n'importe quel ordre doit donner le meme resultat.
  it('donne le meme resultat quel que soit l ordre d arrivee', () => {
    const base = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjor' }),
    });

    const action = {
      type: 'message/edited' as const,
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'bonjour',
      editedAt: '2026-07-26T09:05:00+00:00',
    };

    expect(messagesReducer(messagesReducer(base, action), action)).toEqual(
      messagesReducer(base, action),
    );
  });
});
