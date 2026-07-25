import { describe, expect, it } from 'vitest';
import { emptyTypingState, selectTypists, typingReducer } from './typingReducer';

const CONVERSATION = 'conv-1';

describe('typingReducer', () => {
  it('retient un frappeur pendant la duree de vie de l indicateur', () => {
    const state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    expect(selectTypists(state, CONVERSATION, 1_000)).toEqual(['alice']);
  });

  it('oublie un frappeur une fois son delai ecoule', () => {
    const state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    // 5 s plus tard, plus une milliseconde : l'indicateur a expire.
    expect(selectTypists(state, CONVERSATION, 6_001)).toEqual([]);
  });

  it('repousse l expiration a chaque nouvelle frappe', () => {
    let state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    state = typingReducer(state, {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 4_000,
    });

    expect(selectTypists(state, CONVERSATION, 6_001)).toEqual(['alice']);
  });

  it('efface le frappeur des que son message arrive', () => {
    let state = typingReducer(emptyTypingState(), {
      type: 'typing/started',
      conversationId: CONVERSATION,
      userId: 'alice',
      now: 1_000,
    });

    state = typingReducer(state, {
      type: 'typing/cleared',
      conversationId: CONVERSATION,
      userId: 'alice',
    });

    expect(selectTypists(state, CONVERSATION, 1_100)).toEqual([]);
  });
});
