import { describe, expect, it } from 'vitest';
import { canStillEdit, EDIT_WINDOW_MS } from './labels';

/**
 * Trois lignes, mais une erreur d'unite (ms contre s) casserait la garde en
 * silence : l'action « Modifier » resterait offerte des heures apres l'envoi, et
 * l'utilisateur ne decouvrirait le probleme que par un 403. On verrouille donc
 * la borne EXACTE des deux cotes.
 *
 * `EDIT_WINDOW_MS` doit rester egal a `Message::EDIT_WINDOW_SECONDS = 900` cote
 * backend : le test l'affirme, pour que le decouplage des deux valeurs se voie.
 */
describe('canStillEdit', () => {
  const sentAt = Date.parse('2026-07-26T12:00:00+00:00');

  it('reste couple a la fenetre de 15 minutes du backend', () => {
    expect(EDIT_WINDOW_MS).toBe(900 * 1000);
  });

  it('autorise l edition a la borne exacte', () => {
    expect(canStillEdit('2026-07-26T12:00:00+00:00', sentAt + EDIT_WINDOW_MS)).toBe(true);
  });

  it('refuse l edition une milliseconde apres la borne', () => {
    expect(canStillEdit('2026-07-26T12:00:00+00:00', sentAt + EDIT_WINDOW_MS + 1)).toBe(false);
  });

  it('autorise l edition juste apres l envoi', () => {
    expect(canStillEdit('2026-07-26T12:00:00+00:00', sentAt)).toBe(true);
  });

  /** Une date illisible ne doit pas ouvrir la fenetre par accident. */
  it('refuse l edition sur une date illisible', () => {
    expect(canStillEdit('pas une date', sentAt)).toBe(false);
  });
});
