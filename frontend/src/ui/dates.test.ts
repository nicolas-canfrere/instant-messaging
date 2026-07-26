import { describe, expect, it } from 'vitest';
import { dayKey, formatDaySeparator, formatRelative } from './dates';

/**
 * Le fuseau est un PARAMETRE de ces fonctions, jamais une globale lue a
 * l'interieur : c'est ce qui permet de verifier Tokyo et New York dans le meme
 * fichier, sans toucher a `process.env.TZ`.
 */
describe('dayKey', () => {
  // 2026-07-26T22:00:00Z, c'est deja le 27 a Tokyo (UTC+9) et encore le 26 a
  // New York (UTC-4). Correct et attendu.
  const instant = '2026-07-26T22:00:00+00:00';

  it('rend la date du jour dans le fuseau du lecteur', () => {
    expect(dayKey(instant, 'Asia/Tokyo')).toBe('2026-07-27');
    expect(dayKey(instant, 'America/New_York')).toBe('2026-07-26');
  });

  it('rend une chaine vide pour une date illisible', () => {
    expect(dayKey('pas une date', 'Europe/Paris')).toBe('');
  });
});

describe('formatDaySeparator', () => {
  const now = new Date('2026-07-26T12:00:00+00:00');

  it('dit « Aujourd’hui » pour le jour courant du lecteur', () => {
    expect(formatDaySeparator('2026-07-26T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now)).toBe(
      "Aujourd'hui",
    );
  });

  it('dit « Hier » pour la veille du lecteur', () => {
    expect(formatDaySeparator('2026-07-25T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now)).toBe(
      'Hier',
    );
  });

  // Le meme instant, deux jours differents selon le lecteur : c'est le
  // comportement correct, pas un bug a corriger.
  it('classe le meme instant sous deux jours differents selon le fuseau', () => {
    const instant = '2026-07-25T22:00:00+00:00';

    expect(formatDaySeparator(instant, 'Asia/Tokyo', 'fr-FR', now)).toBe("Aujourd'hui");
    expect(formatDaySeparator(instant, 'America/New_York', 'fr-FR', now)).toBe('Hier');
  });

  it('rend une date complete au-dela de la veille', () => {
    const label = formatDaySeparator('2026-07-01T08:00:00+00:00', 'Europe/Paris', 'fr-FR', now);

    expect(label).toContain('juillet');
  });
});

describe('formatRelative', () => {
  const now = new Date('2026-07-26T12:00:00+00:00');

  // Valeurs attendues ecrites en dur (verifiees dans le conteneur avec l'ICU
  // reellement embarque) : recalculer l'attendu avec la meme fonction Intl que
  // le code sous test ne prouverait rien, notamment sur le signe.
  it('rend un temps relatif dans le passe en minutes', () => {
    expect(formatRelative('2026-07-26T11:55:00+00:00', now, 'fr-FR')).toBe('il y a 5 minutes');
  });

  // Cas symetrique dans le futur : c'est precisement l'inversion de signe
  // qu'un `toContain('5')` ne peut pas detecter (« il y a » et « dans »
  // contiennent tous les deux le chiffre).
  it('rend un temps relatif dans le futur en minutes', () => {
    expect(formatRelative('2026-07-26T12:05:00+00:00', now, 'fr-FR')).toBe('dans 5 minutes');
  });
});
