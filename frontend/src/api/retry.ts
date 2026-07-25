type Options = {
  attempts: number;
  baseDelayMs?: number;
  sleep?: (ms: number) => Promise<void>;
  random?: () => number;
};

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

  let lastError: unknown;

  for (let attempt = 0; attempt < options.attempts; attempt++) {
    try {
      return await task();
    } catch (cause) {
      lastError = cause;

      if (attempt === options.attempts - 1) break;

      const exponential = base * 2 ** attempt;
      await sleep(exponential * (0.5 + random() * 0.5));
    }
  }

  throw lastError;
}
