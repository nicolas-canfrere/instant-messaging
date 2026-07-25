import { describe, expect, it } from 'vitest';
import {
  emptyReceiptsState,
  receiptsReducer,
  selectReadCount,
  selectStatusFor,
} from './receiptsReducer';

const CONVERSATION = 'conv-1';
const OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
const NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

describe('receiptsReducer', () => {
  it('remplace l etat d un membre au lieu de le fusionner', () => {
    let state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    // Un evenement arrive dans le desordre porte l'etat COMPLET : il ecrase.
    state = receiptsReducer(state, {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: OLDER,
      lastReadMessageId: null,
    });

    expect(selectStatusFor(state, CONVERSATION, OLDER, 'alice')).toBe('delivered');
  });

  it('rend « sent » tant que personne n a rien recu', () => {
    expect(selectStatusFor(emptyReceiptsState(), CONVERSATION, NEWER, 'alice')).toBe('sent');
  });

  it('rend « read » des qu un autre membre a lu jusqu au message', () => {
    const state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    expect(selectStatusFor(state, CONVERSATION, NEWER, 'alice')).toBe('read');
  });

  it('ignore son propre watermark dans le calcul du statut', () => {
    const state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'alice',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    // Alice a lu son propre message : cela ne fait pas une coche bleue.
    expect(selectStatusFor(state, CONVERSATION, NEWER, 'alice')).toBe('sent');
  });

  it('compte les lecteurs d un message pour l affichage de groupe', () => {
    let state = receiptsReducer(emptyReceiptsState(), {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'bob',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: NEWER,
    });

    state = receiptsReducer(state, {
      type: 'receipt/updated',
      conversationId: CONVERSATION,
      userId: 'carol',
      lastDeliveredMessageId: NEWER,
      lastReadMessageId: OLDER,
    });

    expect(selectReadCount(state, CONVERSATION, NEWER, 'alice')).toBe(1);
    expect(selectReadCount(state, CONVERSATION, OLDER, 'alice')).toBe(2);
  });
});
