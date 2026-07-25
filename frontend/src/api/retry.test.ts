import { describe, expect, it, vi } from 'vitest';
import { ProblemError } from './problem';
import { retryWithBackoff } from './retry';

/**
 * Meme helper que dans les autres suites : `noUncheckedIndexedAccess` type tout
 * acces par index en `number | undefined`. Plutot que de le masquer avec un `!`,
 * on fait echouer le test explicitement quand l'element manque.
 */
function at(values: number[], index: number): number {
  const value = values[index];

  if (value === undefined) {
    throw new Error(`Aucun delai a l'index ${index} (liste de ${values.length} element(s)).`);
  }

  return value;
}

describe('retryWithBackoff', () => {
  it('reussit sans attendre si le premier essai passe', async () => {
    const sleep = vi.fn().mockResolvedValue(undefined);
    const task = vi.fn().mockResolvedValue('ok');

    await expect(retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 })).resolves.toBe('ok');
    expect(sleep).not.toHaveBeenCalled();
  });

  it('reessaie avec des delais croissants', async () => {
    const delays: number[] = [];
    const sleep = vi.fn(async (ms: number) => {
      delays.push(ms);
    });

    const task = vi
      .fn()
      .mockRejectedValueOnce(new Error('reseau'))
      .mockRejectedValueOnce(new Error('reseau'))
      .mockResolvedValue('ok');

    await expect(retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 })).resolves.toBe('ok');

    expect(delays).toHaveLength(2);
    expect(at(delays, 1)).toBeGreaterThan(at(delays, 0));
  });

  it('remonte immediatement une erreur non rejouable, sans attendre', async () => {
    // Un 422 (contenu trop long) ou un 404 (non-membre) ne deviendra jamais un
    // succes : le rejouer trois fois ne fait que retarder de plusieurs secondes
    // le passage de la bulle en « echec ».
    const sleep = vi.fn().mockResolvedValue(undefined);
    const task = vi
      .fn()
      .mockRejectedValue(new ProblemError(422, 'about:blank', 'Contenu trop long.', null));

    await expect(
      retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 }),
    ).rejects.toBeInstanceOf(ProblemError);

    expect(task).toHaveBeenCalledTimes(1);
    expect(sleep).not.toHaveBeenCalled();
  });

  it('rejoue un 429 et un 5xx, qui peuvent encore reussir', async () => {
    const sleep = vi.fn().mockResolvedValue(undefined);
    const task = vi
      .fn()
      .mockRejectedValueOnce(new ProblemError(503, 'about:blank', 'Indisponible.', null))
      .mockRejectedValueOnce(new ProblemError(429, 'about:blank', 'Trop de requetes.', null))
      .mockResolvedValue('ok');

    await expect(retryWithBackoff(task, { attempts: 3, sleep, random: () => 0.5 })).resolves.toBe(
      'ok',
    );

    expect(task).toHaveBeenCalledTimes(3);
  });

  it('propage l erreur apres epuisement des tentatives', async () => {
    const task = vi.fn().mockRejectedValue(new Error('reseau'));

    await expect(
      retryWithBackoff(task, { attempts: 2, sleep: async () => {}, random: () => 0.5 }),
    ).rejects.toThrow('reseau');

    expect(task).toHaveBeenCalledTimes(2);
  });
});
