import { ProblemError } from './problem';

type Options = {
  attempts: number;
  baseDelayMs?: number;
  sleep?: (ms: number) => Promise<void>;
  random?: () => number;
  /** Par defaut `isRetryable` : voir ci-dessous. */
  shouldRetry?: (cause: unknown) => boolean;
};

/**
 * Un reessai n'a de sens que si la meme requete peut encore reussir.
 *
 *  - Une erreur qui n'est pas un `ProblemError` vient du `fetch` lui-meme
 *    (reseau coupe, DNS, serveur injoignable) : c'est le cas type a rejouer.
 *  - 5xx : le serveur a echoue, pas la requete. Rejouable.
 *  - 429 : on nous demande explicitement de ralentir, donc de revenir plus tard.
 *  - Tous les autres 4xx (422 contenu invalide, 404 non-membre, 401 session
 *    expiree) sont des verdicts sur la requete : la rejouer donnera exactement
 *    la meme reponse, trois fois, en faisant seulement attendre l'utilisateur
 *    plusieurs secondes avant que la bulle ne rougisse.
 */
export function isRetryable(cause: unknown): boolean {
  if (!(cause instanceof ProblemError)) {
    return true;
  }

  return cause.status >= 500 || cause.status === 429;
}

/**
 * Backoff exponentiel avec jitter.
 *
 * Le jitter n'est pas cosmetique : sans lui, tous les clients deconnectes par
 * la meme coupure reessaient exactement au meme instant et achevent le serveur
 * qui vient de revenir (thundering herd).
 *
 * Rejouer est sans danger : le meme client_message_id est reutilise, donc le
 * serveur renvoie le message existant au lieu d'en creer un second.
 */
export async function retryWithBackoff<T>(task: () => Promise<T>, options: Options): Promise<T> {
  const base = options.baseDelayMs ?? 300;
  const sleep = options.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
  const random = options.random ?? Math.random;
  const shouldRetry = options.shouldRetry ?? isRetryable;

  let lastError: unknown;

  for (let attempt = 0; attempt < options.attempts; attempt++) {
    try {
      return await task();
    } catch (cause) {
      lastError = cause;

      // Verdict definitif : on remonte tout de suite, sans meme dormir.
      if (!shouldRetry(cause)) break;

      if (attempt === options.attempts - 1) break;

      const exponential = base * 2 ** attempt;
      await sleep(exponential * (0.5 + random() * 0.5));
    }
  }

  throw lastError;
}
