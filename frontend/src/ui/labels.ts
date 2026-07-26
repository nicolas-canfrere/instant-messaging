import type { ConversationSummary, UserSummary } from '../api/types';
import { dayKey } from './dates';

/**
 * Pur, sans React : ce sont des regles d'affichage, pas du rendu. Les garder
 * ici evite que trois composants reinventent chacun leur variante du meme
 * libelle — et permet de les tester un jour sans monter de composant.
 */

/** Nom lisible d'un utilisateur, ou son identifiant tant que l'annuaire n'est pas charge. */
export function userName(users: Record<string, UserSummary>, userId: string): string {
  return users[userId]?.display_name ?? userId;
}

/**
 * Un groupe porte un titre ; un direct n'en a pas et s'affiche sous le nom de
 * l'interlocuteur. Tant que le detail de la conversation n'est pas revenu, on
 * affiche un libelle neutre plutot qu'un identifiant brut.
 */
export function conversationTitle(
  conversation: ConversationSummary,
  users: Record<string, UserSummary>,
  peers: Record<string, string>,
): string {
  if (conversation.type === 'group') {
    return conversation.title ?? 'Groupe sans titre';
  }

  const peerId = peers[conversation.id];

  return peerId === undefined ? 'Conversation' : userName(users, peerId);
}

/** Heure locale « 14:32 » : format court, suffisant dans un fil de messages. */
export function formatTime(isoDate: string, timeZone: string, locale: string): string {
  const date = new Date(isoDate);

  return Number.isNaN(date.getTime())
    ? ''
    : new Intl.DateTimeFormat(locale, {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
      }).format(date);
}

/**
 * Dans la liste des conversations, l'heure seule est ambigue au-dela du jour
 * courant : on bascule alors sur la date. Le « jour courant » est celui du
 * LECTEUR, pas celui du serveur.
 */
export function formatListDate(isoDate: string, timeZone: string, locale: string): string {
  const date = new Date(isoDate);
  if (Number.isNaN(date.getTime())) return '';

  const sameDay = dayKey(isoDate, timeZone) === dayKey(new Date().toISOString(), timeZone);

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    ...(sameDay
      ? { hour: '2-digit', minute: '2-digit' }
      : { day: '2-digit', month: '2-digit' }),
  }).format(date);
}

/**
 * Le serveur ne dit jamais « ce message a ete supprime » : il dit qu'il n'y a
 * plus de charge utile. Le libelle est de la presentation, il vit donc ici — et
 * pourra etre traduit sans toucher a l'API.
 */
export const deletedMessageLabel = 'Ce message a été supprimé';

/**
 * Doit rester egal a `Message::EDIT_WINDOW_SECONDS` cote backend.
 *
 * C'est du CONFORT, pas de la securite : le serveur reste l'autorite et
 * repondra 403 `/problems/edit-window-expired` a un appel forge. On masque
 * seulement une action qui echouerait.
 */
export const EDIT_WINDOW_MS = 900_000;

export function canStillEdit(createdAt: string, now: number): boolean {
  const sentAt = new Date(createdAt).getTime();

  return !Number.isNaN(sentAt) && now - sentAt <= EDIT_WINDOW_MS;
}

export const editedMessageLabel = 'modifié';
