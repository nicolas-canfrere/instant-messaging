import { describe, expect, it } from 'vitest';
import { emptyPresenceState, presenceReducer } from './presenceReducer';

describe('presenceReducer', () => {
  it('remplace la presence au lieu de la fusionner', () => {
    const first = presenceReducer(emptyPresenceState(), {
      type: 'presence/refreshed',
      onlineUserIds: ['alice', 'bob'],
    });

    const second = presenceReducer(first, {
      type: 'presence/refreshed',
      onlineUserIds: ['alice'],
    });

    // C'est l'invariant du reducer : fusionner ferait qu'un utilisateur passe
    // hors ligne ne disparaitrait jamais de la liste.
    expect([...second.onlineUserIds]).toEqual(['alice']);
  });

  it('rend un ensemble vide quand plus personne n est en ligne', () => {
    const state = presenceReducer(emptyPresenceState(), {
      type: 'presence/refreshed',
      onlineUserIds: [],
    });

    expect(state.onlineUserIds.size).toBe(0);
  });
});
