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
  /**
   * Delai avant de retenter une connexion qui a echoue.
   *
   * 5 s, et non l'intervalle de renouvellement (13 min) : une panne de
   * `/api/realtime/token` est le plus souvent breve (redemarrage du backend,
   * hoquet reseau), et laisser l'utilisateur 13 min sans temps reel alors que
   * le reste de l'interface repond reviendrait a une panne silencieuse. Assez
   * long, en revanche, pour ne pas marteler un serveur deja en difficulte.
   */
  retryDelayMs?: number;
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
  private retryTimer: ReturnType<typeof setTimeout> | null = null;
  private stopped = false;
  /** Serialise start/resubscribe : deux appels concurrents ne peuvent pas ouvrir deux flux. */
  private pending: Promise<void> = Promise.resolve();

  constructor(private readonly options: Options) {}

  /**
   * Ne rejette jamais : l'appelant fait `void client.start()`, un rejet y serait
   * avale sans trace et l'utilisateur verrait une interface complete mais muette.
   * Un echec est donc signale par `onError` et suivi d'un reessai programme.
   */
  start(): Promise<void> {
    this.stopped = false;

    return this.enqueue(async () => {
      if (this.source !== null) {
        return;
      }

      await this.connectSafely();
    });
  }

  /**
   * A appeler apres avoir cree ou rejoint une conversation, ou sur membership.changed.
   * Ne rejette jamais non plus (appelee en `void` par le timer de renouvellement).
   */
  resubscribe(): Promise<void> {
    return this.enqueue(() => this.connectSafely());
  }

  stop(): void {
    this.stopped = true;
    this.closeSource();

    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }

    if (this.retryTimer !== null) {
      clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
  }

  private enqueue(task: () => Promise<void>): Promise<void> {
    this.pending = this.pending.then(task, task);

    return this.pending;
  }

  /** Enveloppe de `connect()` qui transforme tout echec en signal + reessai. */
  private async connectSafely(): Promise<void> {
    if (this.stopped) {
      return;
    }

    try {
      await this.connect();
      this.scheduleRefresh();
    } catch (cause) {
      this.options.onError?.(cause);
      this.scheduleRetry();
    }
  }

  /**
   * Le jeton est recupere AVANT toute fermeture : si `fetchToken` echoue, le flux
   * en cours continue de vivre. Fermer d'abord aurait laisse l'application
   * totalement hors ligne jusqu'au prochain tic, pour une panne passagere.
   *
   * L'invariant « jamais deux EventSource ouverts » tient toujours : `enqueue`
   * serialise les appels, et entre `closeSource()` et l'exposition de la nouvelle
   * source il n'y a aucun `await` — donc aucun point d'entrelacement.
   */
  private async connect(): Promise<void> {
    const token = await this.options.fetchToken();

    if (this.stopped) {
      return;
    }

    this.closeSource();

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

  /**
   * Un seul reessai en attente a la fois : `setTimeout` et non `setInterval`,
   * pour que la tentative suivante ne soit programmee qu'apres l'echec de la
   * precedente (une tentative lente ne s'empile donc jamais sur elle-meme).
   */
  private scheduleRetry(): void {
    if (this.retryTimer !== null || this.stopped) {
      return;
    }

    this.retryTimer = setTimeout(() => {
      this.retryTimer = null;

      void this.enqueue(() => this.connectSafely());
    }, this.options.retryDelayMs ?? 5000);
  }

  private closeSource(): void {
    this.source?.close();
    this.source = null;
  }
}
