/**
 * Un instant est absolu : on le stocke et on le transporte en UTC, on le
 * convertit vers le fuseau local UNIQUEMENT a l'affichage, ici.
 *
 * Le fuseau et la locale sont des PARAMETRES, jamais des globales lues a
 * l'interieur : c'est ce qui rend ces fonctions testables sans bidouiller
 * `process.env.TZ`, et ce qui permet de verifier deux fuseaux dans un meme test.
 */

/** Clé de jour (`2026-07-26`) dans le fuseau du lecteur, pour comparer deux instants. */
export function dayKey(iso: string, timeZone: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';

  // `en-CA` rend `YYYY-MM-DD`, donc une clé triable et comparable telle quelle.
  // Ce n'est pas la locale de l'utilisateur : c'est un format interne.
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date);
}

/**
 * Sépare deux jours dans le fil. Un même message peut être « Aujourd'hui » pour
 * Tokyo et « Hier » pour New York : c'est correct et attendu.
 */
export function formatDaySeparator(
  iso: string,
  timeZone: string,
  locale: string,
  now: Date,
): string {
  const key = dayKey(iso, timeZone);
  if (key === '') return '';

  if (key === dayKey(now.toISOString(), timeZone)) return "Aujourd'hui";

  const yesterday = new Date(now.getTime() - 86_400_000);
  if (key === dayKey(yesterday.toISOString(), timeZone)) return 'Hier';

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    day: 'numeric',
    month: 'long',
  }).format(new Date(iso));
}

const MINUTE_MS = 60_000;
const HOUR_MS = 3_600_000;
const DAY_MS = 86_400_000;

/** « il y a 5 min » : calculé depuis l'instant absolu, donc juste dans tous les fuseaux. */
export function formatRelative(iso: string, now: Date, locale: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';

  const elapsed = date.getTime() - now.getTime();
  const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

  if (Math.abs(elapsed) < HOUR_MS) {
    return format.format(Math.round(elapsed / MINUTE_MS), 'minute');
  }

  if (Math.abs(elapsed) < DAY_MS) {
    return format.format(Math.round(elapsed / HOUR_MS), 'hour');
  }

  return format.format(Math.round(elapsed / DAY_MS), 'day');
}

/**
 * Nom IANA, toujours a jour et gerant le DST tout seul. Ne JAMAIS persister un
 * offset (`+02:00`) a la place : il change deux fois par an.
 */
export function viewerTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

export function viewerLocale(): string {
  return navigator.language;
}
