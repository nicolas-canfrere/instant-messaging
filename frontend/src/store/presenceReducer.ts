/**
 * Presence en ligne, reçue en bloc a chaque battement de coeur.
 *
 * Le reducer REMPLACE l'ensemble, il ne le fusionne jamais : le serveur rend la
 * liste complete des pairs presents, donc fusionner ferait qu'un utilisateur
 * passe hors ligne resterait affiche en ligne pour toujours.
 */
export type PresenceState = { onlineUserIds: Set<string> };

export type PresenceAction = { type: 'presence/refreshed'; onlineUserIds: string[] };

export function emptyPresenceState(): PresenceState {
  return { onlineUserIds: new Set() };
}

export function presenceReducer(_state: PresenceState, action: PresenceAction): PresenceState {
  switch (action.type) {
    case 'presence/refreshed':
      return { onlineUserIds: new Set(action.onlineUserIds) };
  }
}
