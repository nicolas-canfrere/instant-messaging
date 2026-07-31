import { describe, expect, it } from 'vitest';
import {
  emptyMessagesState,
  messagesReducer,
  selectThread,
  type StoredMedia,
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
    media: [],
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
    media: [],
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
        media: [],
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
        media: [],
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
        media: [],
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
        media: [],
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

  /**
   * SSE ne garantit aucun ordre entre deux evenements distincts : un echo
   * d'edition peut arriver APRES un echo de suppression. La retractation est
   * definitive — le message doit rester un tombstone, sans quoi l'etat du store
   * contredirait « record soft, payload hard » (le rendu, lui, masquait le
   * probleme : la branche tombstone passe avant).
   */
  it('laisse intact un message deja supprime', () => {
    const received = messagesReducer(emptyMessagesState(), {
      type: 'message/received',
      message: aMessage({ id: '01J0000000000000000000000A', content: 'bonjour' }),
    });

    const deleted = messagesReducer(received, {
      type: 'message/deleted',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      deletedAt: '2026-07-26T09:10:00+00:00',
    });

    const next = messagesReducer(deleted, {
      type: 'message/edited',
      conversationId: 'c1',
      id: '01J0000000000000000000000A',
      content: 'ressuscite',
      editedAt: '2026-07-26T09:05:00+00:00',
    });

    const item = at(selectThread(next, 'c1').items, 0);
    expect(item.content).toBeNull();
    expect(item.deletedAt).toBe('2026-07-26T09:10:00+00:00');
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

  describe('media/ready', () => {
    const MESSAGE_ID = '01J0000000000000000000000A';
    const MEDIA_ID = '01JQZ0000000000000000050AA';
    const AUTRE_MEDIA_ID = '01JQZ0000000000000000051AA';

    function processing(id: string): StoredMedia {
      return {
        id,
        status: 'processing',
        mimeType: null,
        url: null,
        thumbnailUrl: null,
        width: null,
        height: null,
        previewUrl: `blob:local/${id}`,
        filename: 'photo.jpg',
      };
    }

    function ready(id: string): StoredMedia {
      return {
        id,
        status: 'ready',
        mimeType: 'image/jpeg',
        url: `https://stockage.test/${id}?X-Amz-Signature=abc`,
        thumbnailUrl: `https://stockage.test/${id}-thumb?X-Amz-Signature=abc`,
        width: 1600,
        height: 900,
        previewUrl: null,
        filename: 'photo.jpg',
      };
    }

    /** Un fil charge, avec un message portant DEUX images encore en traitement. */
    function threadWithTwoMedia() {
      return messagesReducer(emptyMessagesState(), {
        type: 'message/received',
        message: aMessage({
          id: MESSAGE_ID,
          media: [processing(MEDIA_ID), processing(AUTRE_MEDIA_ID)],
        }),
      });
    }

    it('remplace le media concerne et laisse les autres intacts', () => {
      const before = threadWithTwoMedia();

      const after = messagesReducer(before, {
        type: 'media/ready',
        conversationId: 'c1',
        messageId: MESSAGE_ID,
        media: ready(MEDIA_ID),
      });

      const media = selectThread(after, 'c1').items[0]?.media ?? [];

      expect(media[0]).toEqual(ready(MEDIA_ID));
      // Le second n'a pas bouge : un evenement porte UNE image, pas la liste.
      expect(media[1]).toEqual(processing(AUTRE_MEDIA_ID));
    });

    /**
     * Le cas qui compte : l'evenement arrive pour TOUTES les conversations
     * auxquelles on est abonne, y compris celles dont le fil n'est pas charge.
     * On verifie la REFERENCE, pas l'egalite — un nouvel objet ferait re-rendre
     * toute la liste pour une image qui n'est affichee nulle part.
     */
    it('rend l etat inchange, a la reference pres, pour un fil non charge', () => {
      const before = threadWithTwoMedia();

      const after = messagesReducer(before, {
        type: 'media/ready',
        conversationId: 'conversation-jamais-ouverte',
        messageId: MESSAGE_ID,
        media: ready(MEDIA_ID),
      });

      expect(after).toBe(before);
    });

    it('rend l etat inchange pour un message absent du fil', () => {
      const before = threadWithTwoMedia();

      const after = messagesReducer(before, {
        type: 'media/ready',
        conversationId: 'c1',
        messageId: '01J0000000000000000000000Z',
        media: ready(MEDIA_ID),
      });

      expect(after).toBe(before);
    });

    it('est idempotent : un redelivrage donne le meme etat', () => {
      const action = {
        type: 'media/ready' as const,
        conversationId: 'c1',
        messageId: MESSAGE_ID,
        media: ready(MEDIA_ID),
      };

      const once = messagesReducer(threadWithTwoMedia(), action);

      expect(messagesReducer(once, action)).toEqual(once);
    });

    it('applique aussi un refus, qui est une issue comme une autre', () => {
      const rejected: StoredMedia = {
        id: MEDIA_ID,
        status: 'rejected',
        mimeType: null,
        url: null,
        thumbnailUrl: null,
        width: null,
        height: null,
        previewUrl: null,
        filename: 'photo.jpg',
      };

      const after = messagesReducer(threadWithTwoMedia(), {
        type: 'media/ready',
        conversationId: 'c1',
        messageId: MESSAGE_ID,
        media: rejected,
      });

      expect(selectThread(after, 'c1').items[0]?.media[0]).toEqual(rejected);
    });

    /**
     * Regression trouvee au navigateur, pas en test : chez un DESTINATAIRE, le
     * message arrive sans aucun media — `message.created` n'en porte pas. Une
     * implementation qui se contente de remplacer une entree existante ne
     * trouve alors rien a remplacer, et l'image n'apparait jamais.
     */
    it('ajoute le media quand le message n en portait aucun, cas du destinataire', () => {
      const before = messagesReducer(emptyMessagesState(), {
        type: 'message/received',
        message: aMessage({ id: MESSAGE_ID, content: null, media: [] }),
      });

      const after = messagesReducer(before, {
        type: 'media/ready',
        conversationId: 'c1',
        messageId: MESSAGE_ID,
        media: ready(MEDIA_ID),
      });

      expect(selectThread(after, 'c1').items[0]?.media).toEqual([ready(MEDIA_ID)]);
    });
  });

  /**
   * L'autre moitie de la meme regression, cote EXPEDITEUR : son echo SSE
   * arrive avec `media: []` et ecrasait les apercus locaux au moment meme ou le
   * serveur confirmait l'envoi. La bulle se vidait sous ses yeux.
   */
  describe('echo SSE d un message porteur de medias', () => {
    it('conserve les apercus locaux quand l echo n en porte aucun', () => {
      const preview: StoredMedia = {
        id: '01JQZ0000000000000000070AA',
        status: 'processing',
        mimeType: null,
        url: null,
        thumbnailUrl: null,
        width: null,
        height: null,
        previewUrl: 'blob:local/1',
        filename: 'photo.jpg',
      };

      const optimistic = messagesReducer(emptyMessagesState(), {
        type: 'message/optimistic',
        message: aMessage({ id: null, clientMessageId: 'c-envoi', content: null, media: [preview] }),
      });

      const after = messagesReducer(optimistic, {
        type: 'message/received',
        message: aMessage({
          id: '01J0000000000000000000000B',
          clientMessageId: 'c-envoi',
          content: null,
          media: [],
        }),
      });

      const item = selectThread(after, 'c1').items[0];

      expect(item?.status).toBe('sent');
      expect(item?.media).toEqual([preview]);
    });

    /** Une page d'historique, elle, fait autorite : ses medias remplacent tout. */
    it('laisse le serveur gagner quand il porte reellement des medias', () => {
      const stale: StoredMedia = {
        id: '01JQZ0000000000000000071AA',
        status: 'processing',
        mimeType: null,
        url: null,
        thumbnailUrl: null,
        width: null,
        height: null,
        previewUrl: 'blob:local/2',
        filename: 'photo.jpg',
      };

      // Un `ready` porte TOUJOURS ses URLs : le CHECK `media_ready_is_measured`
      // l'impose en base. C'est ce qui rend l'apercu local inutile.
      const fresh: StoredMedia = {
        ...stale,
        status: 'ready',
        url: 'https://stockage.test/original?X-Amz-Signature=abc',
        thumbnailUrl: 'https://stockage.test/thumb?X-Amz-Signature=abc',
        previewUrl: null,
      };

      const optimistic = messagesReducer(emptyMessagesState(), {
        type: 'message/optimistic',
        message: aMessage({ id: null, clientMessageId: 'c-envoi', content: null, media: [stale] }),
      });

      const after = messagesReducer(optimistic, {
        type: 'page/loaded',
        conversationId: 'c1',
        items: [
          aMessage({
            id: '01J0000000000000000000000B',
            clientMessageId: 'c-envoi',
            content: null,
            media: [fresh],
          }),
        ],
        nextBefore: null,
      });

      expect(selectThread(after, 'c1').items[0]?.media).toEqual([fresh]);
    });

    /**
     * Depuis que `message.created` porte les medias, l'echo arrive avec la vue
     * SERVEUR — souvent encore `processing`, donc sans aucune URL. Recopier
     * cette vue telle quelle remplacerait l'image de l'expediteur par un
     * placeholder a l'instant meme ou le serveur accuse reception.
     */
    it('reporte l apercu local sur le media que l echo decrit', () => {
      const preview: StoredMedia = {
        id: '01JQZ0000000000000000072AA',
        status: 'processing',
        mimeType: null,
        url: null,
        thumbnailUrl: null,
        width: null,
        height: null,
        previewUrl: 'blob:local/3',
        filename: 'photo.jpg',
      };

      const optimistic = messagesReducer(emptyMessagesState(), {
        type: 'message/optimistic',
        message: aMessage({ id: null, clientMessageId: 'c-envoi', content: null, media: [preview] }),
      });

      const after = messagesReducer(optimistic, {
        type: 'message/received',
        message: aMessage({
          id: '01J0000000000000000000000B',
          clientMessageId: 'c-envoi',
          content: null,
          // Ce que le serveur sait : le media existe, il est en traitement.
          media: [{ ...preview, previewUrl: null }],
        }),
      });

      expect(selectThread(after, 'c1').items[0]?.media[0]?.previewUrl).toBe('blob:local/3');
    });
  });
});
