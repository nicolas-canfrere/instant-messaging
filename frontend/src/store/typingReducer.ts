/**
 * « En train d'ecrire » : etat ephemere, jamais persiste nulle part.
 *
 * Le reducer stocke une DATE D'EXPIRATION par frappeur, et `now` lui est
 * toujours passe en argument — il ne lit jamais l'horloge lui-meme. C'est ce
 * qui le rend testable sans faux minuteurs : une fonction pure de ses entrees.
 *
 * Il n'existe pas d'evenement « a arrete d'ecrire ». Un contre-evenement
 * doublerait le trafic pour une information deductible, et introduirait un mode
 * d'echec propre : un `stopped` perdu laisserait l'indicateur affiche pour
 * toujours. Une expiration est autoreparatrice par construction.
 */
const TYPING_TTL_MS = 5_000;

export type TypingState = {
  /** conversationId -> (userId -> instant d'expiration en ms) */
  byConversation: Record<string, Record<string, number>>;
};

export type TypingAction =
  | { type: 'typing/started'; conversationId: string; userId: string; now: number }
  | { type: 'typing/cleared'; conversationId: string; userId: string };

export function emptyTypingState(): TypingState {
  return { byConversation: {} };
}

export function typingReducer(state: TypingState, action: TypingAction): TypingState {
  switch (action.type) {
    case 'typing/started': {
      const current = state.byConversation[action.conversationId] ?? {};

      return {
        byConversation: {
          ...state.byConversation,
          [action.conversationId]: { ...current, [action.userId]: action.now + TYPING_TTL_MS },
        },
      };
    }

    case 'typing/cleared': {
      const current = state.byConversation[action.conversationId];
      if (current === undefined || !(action.userId in current)) return state;

      const { [action.userId]: _removed, ...rest } = current;

      return { byConversation: { ...state.byConversation, [action.conversationId]: rest } };
    }
  }
}

/** Frappeurs encore valides a l'instant `now`. Le tri rend l'affichage stable. */
export function selectTypists(state: TypingState, conversationId: string, now: number): string[] {
  const entries = state.byConversation[conversationId] ?? {};

  return Object.entries(entries)
    .filter(([, expiresAt]) => expiresAt > now)
    .map(([userId]) => userId)
    .sort();
}

/** Y a-t-il au moins un frappeur actif, toutes conversations confondues ? */
export function hasActiveTypists(state: TypingState, now: number): boolean {
  return Object.values(state.byConversation).some((entries) =>
    Object.values(entries).some((expiresAt) => expiresAt > now),
  );
}
