export class ProblemError extends Error {
  constructor(
    readonly status: number,
    readonly type: string,
    readonly detail: string,
    readonly correlationId: string | null,
  ) {
    super(`${status} ${type}: ${detail}`);
    this.name = 'ProblemError';
  }
}

export async function toProblemError(response: Response): Promise<ProblemError> {
  try {
    const body = (await response.json()) as Record<string, unknown>;

    return new ProblemError(
      typeof body.status === 'number' ? body.status : response.status,
      typeof body.type === 'string' ? body.type : 'about:blank',
      typeof body.detail === 'string' ? body.detail : response.statusText,
      typeof body.correlation_id === 'string' ? body.correlation_id : null,
    );
  } catch {
    // Corps illisible (HTML d'un proxy en panne, reponse vide) : on degrade
    // proprement plutot que de laisser remonter une erreur de parsing.
    return new ProblemError(response.status, 'about:blank', response.statusText, null);
  }
}
