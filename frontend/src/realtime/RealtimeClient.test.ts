import { describe, expect, it, vi } from 'vitest';
import { RealtimeClient, type EventSourceLike, type Options } from './RealtimeClient';

/** Double de test : un EventSource observable, sans reseau ni DOM. */
class FakeEventSource implements EventSourceLike {
  static instances: FakeEventSource[] = [];

  onmessage: ((event: { data: string; lastEventId: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this);
  }

  close(): void {
    this.closed = true;
  }

  emit(payload: unknown, id = 'evt-1'): void {
    this.onmessage?.({ data: JSON.stringify(payload), lastEventId: id });
  }
}

/**
 * `noUncheckedIndexedAccess` donne le type `FakeEventSource | undefined` a tout
 * acces par index. Plutot que de le masquer avec un `!`, ce helper fait echouer
 * le test explicitement quand l'element manque : l'assertion qui suit reste
 * exactement aussi stricte, et le message d'erreur reste lisible.
 */
function at(items: FakeEventSource[], index: number): FakeEventSource {
  const item = items[index];

  if (item === undefined) {
    throw new Error(`Aucune connexion a l'index ${index} (liste de ${items.length} element(s)).`);
  }

  return item;
}

function build(overrides: Partial<Options> = {}) {
  FakeEventSource.instances = [];

  const onEvent = vi.fn();
  const onError = vi.fn();
  const fetchToken = vi.fn().mockResolvedValue({
    hub_url: 'http://localhost:8080/.well-known/mercure',
    topics: ['/conversations/A', '/users/U/system'],
  });

  const client = new RealtimeClient({
    fetchToken,
    createEventSource: (url: string) => new FakeEventSource(url),
    onEvent,
    onError,
    ...overrides,
  });

  return { client, onEvent, onError, fetchToken };
}

describe('RealtimeClient', () => {
  it('demande un token puis souscrit a tous les topics autorises', async () => {
    const { client, fetchToken } = build();

    await client.start();

    expect(fetchToken).toHaveBeenCalledTimes(1);

    const url = new URL(at(FakeEventSource.instances, 0).url);

    expect(url.pathname).toBe('/.well-known/mercure');
    expect(url.searchParams.getAll('topic')).toEqual(['/conversations/A', '/users/U/system']);

    client.stop();
  });

  it('n ouvre jamais deux connexions simultanement', async () => {
    const { client } = build();

    // StrictMode monte les effets deux fois en developpement : sans garde,
    // on se retrouverait avec deux flux et chaque message compte double.
    await client.start();
    await client.start();

    const open = FakeEventSource.instances.filter((source) => !source.closed);

    expect(open).toHaveLength(1);

    client.stop();
  });

  it('transmet les evenements decodes', async () => {
    const { client, onEvent } = build();

    await client.start();

    at(FakeEventSource.instances, 0).emit({
      type: 'message.created',
      payload: { id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB' },
    });

    expect(onEvent).toHaveBeenCalledWith({
      type: 'message.created',
      payload: { id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB' },
    });

    client.stop();
  });

  it('ignore une charge utile illisible sans casser le flux', async () => {
    const { client, onEvent } = build();

    await client.start();

    at(FakeEventSource.instances, 0).onmessage?.({ data: 'pas du json', lastEventId: 'evt-1' });

    expect(onEvent).not.toHaveBeenCalled();
    expect(at(FakeEventSource.instances, 0).closed).toBe(false);

    client.stop();
  });

  it('ferme l ancienne connexion avant d en ouvrir une nouvelle a la resouscription', async () => {
    const { client, fetchToken } = build();

    await client.start();
    // Cas declencheur : quelqu un vient de nous ajouter a un groupe, donc le
    // JWT courant n autorise pas encore son topic.
    await client.resubscribe();

    expect(fetchToken).toHaveBeenCalledTimes(2);
    expect(FakeEventSource.instances).toHaveLength(2);
    expect(at(FakeEventSource.instances, 0).closed).toBe(true);
    expect(FakeEventSource.instances.filter((source) => !source.closed)).toHaveLength(1);

    client.stop();
  });

  it('signale puis reessaie quand la premiere recuperation du jeton echoue', async () => {
    // Sans reessai, un seul 503 sur /api/realtime/token laisserait l'utilisateur
    // avec une interface parfaitement fonctionnelle mais SANS aucun message
    // live, et ce jusqu'au rechargement de la page : la panne serait invisible.
    vi.useFakeTimers();

    try {
      const { client, onError, fetchToken } = build({ retryDelayMs: 5000 });
      fetchToken.mockRejectedValueOnce(new Error('503 hub indisponible'));

      // `start()` ne doit PAS rejeter : l'appelant fait `void client.start()`,
      // un rejet y serait avale sans aucune trace.
      await client.start();

      expect(onError).toHaveBeenCalledTimes(1);
      expect(FakeEventSource.instances).toHaveLength(0);

      await vi.advanceTimersByTimeAsync(5000);

      expect(fetchToken).toHaveBeenCalledTimes(2);
      expect(FakeEventSource.instances).toHaveLength(1);
      expect(at(FakeEventSource.instances, 0).closed).toBe(false);

      client.stop();
    } finally {
      vi.useRealTimers();
    }
  });

  it('conserve le flux courant quand le renouvellement du jeton echoue', async () => {
    const { client, onError, fetchToken } = build();

    await client.start();

    fetchToken.mockRejectedValueOnce(new Error('503 hub indisponible'));

    // Ne doit pas rejeter non plus : les appels periodiques font `void resubscribe()`.
    await client.resubscribe();

    // Le flux d'origine reste ouvert : mieux vaut un abonnement incomplet
    // (il manque le topic tout juste cree) qu'aucun temps reel du tout.
    expect(FakeEventSource.instances).toHaveLength(1);
    expect(at(FakeEventSource.instances, 0).closed).toBe(false);
    expect(onError).toHaveBeenCalledTimes(1);

    client.stop();
  });

  it('stop ferme la connexion et empeche toute reouverture', async () => {
    const { client } = build();

    await client.start();
    client.stop();

    expect(at(FakeEventSource.instances, 0).closed).toBe(true);

    at(FakeEventSource.instances, 0).onerror?.();

    expect(FakeEventSource.instances).toHaveLength(1);
  });
});
