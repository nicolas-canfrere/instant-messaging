import type { RealtimeToken } from '../api/types';

export type EventSourceLike = {
  onmessage: ((event: { data: string; lastEventId: string }) => void) | null;
  onerror: (() => void) | null;
  close(): void;
};

export type RealtimeEvent = { type: string; payload: Record<string, unknown> };

export type Options = {
  fetchToken: () => Promise<RealtimeToken>;
  createEventSource: (url: string) => EventSourceLike;
  onEvent: (event: RealtimeEvent) => void;
  onError?: (cause: unknown) => void;
  /** Le JWT vit 15 min ; on le renouvelle avant, pour ne jamais subir l'expiration. */
  refreshIntervalMs?: number;
};

/**
 * Unique proprietaire de l'EventSource de l'application.
 *
 * Centraliser la propriete de la connexion est ce qui rend verifiable
 * l'invariant "jamais deux flux ouverts" : en React, l'ouverture serait
 * dispersee dans des effets qui se remontent (StrictMode) et se recreent.
 */
export class RealtimeClient {
  private source: EventSourceLike | null = null;
  private timer: ReturnType<typeof setInterval> | null = null;
  private stopped = false;
  /** Serialise start/resubscribe : deux appels concurrents ne peuvent pas ouvrir deux flux. */
  private pending: Promise<void> = Promise.resolve();

  constructor(private readonly options: Options) {}

  start(): Promise<void> {
    this.stopped = false;

    return this.enqueue(async () => {
      if (this.source !== null) {
        return;
      }

      await this.open();
      this.scheduleRefresh();
    });
  }

  /** A appeler apres avoir cree ou rejoint une conversation, ou sur membership.changed. */
  resubscribe(): Promise<void> {
    return this.enqueue(async () => {
      if (this.stopped) {
        return;
      }

      this.closeSource();
      await this.open();
    });
  }

  stop(): void {
    this.stopped = true;
    this.closeSource();

    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  private enqueue(task: () => Promise<void>): Promise<void> {
    this.pending = this.pending.then(task, task);

    return this.pending;
  }

  private async open(): Promise<void> {
    const token = await this.options.fetchToken();

    if (this.stopped) {
      return;
    }

    const url = new URL(token.hub_url);
    for (const topic of token.topics) {
      url.searchParams.append('topic', topic);
    }

    const source = this.options.createEventSource(url.toString());

    source.onmessage = (event) => {
      try {
        const parsed = JSON.parse(event.data) as RealtimeEvent;
        this.options.onEvent(parsed);
      } catch (cause) {
        // Une charge utile illisible ne doit pas tuer le flux : on la jette.
        this.options.onError?.(cause);
      }
    };

    source.onerror = () => {
      if (this.stopped) {
        return;
      }

      // Le navigateur reconnecte seul un EventSource. On ne rouvre donc pas
      // ici : le faire creerait exactement le second flux qu'on veut interdire.
      this.options.onError?.(new Error('Flux temps reel interrompu'));
    };

    this.source = source;
  }

  private scheduleRefresh(): void {
    if (this.timer !== null) {
      return;
    }

    this.timer = setInterval(
      () => void this.resubscribe(),
      this.options.refreshIntervalMs ?? 13 * 60 * 1000,
    );
  }

  private closeSource(): void {
    this.source?.close();
    this.source = null;
  }
}
