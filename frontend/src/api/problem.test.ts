import { describe, expect, it } from 'vitest';
import { ProblemError, toProblemError } from './problem';

describe('toProblemError', () => {
  it('extrait les champs du document Problem Details', async () => {
    const response = new Response(
      JSON.stringify({
        type: '/problems/validation-failed',
        title: 'Requete invalide',
        status: 422,
        detail: 'Un message ne peut pas etre vide.',
        correlation_id: '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
      }),
      { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
    );

    const error = await toProblemError(response);

    expect(error).toBeInstanceOf(ProblemError);
    expect(error.status).toBe(422);
    expect(error.type).toBe('/problems/validation-failed');
    expect(error.detail).toBe('Un message ne peut pas etre vide.');
    expect(error.correlationId).toBe('01J9ZQ7X8K3M4N5P6Q7R8S9TAB');
  });

  it('reste utilisable si le corps n est pas un JSON exploitable', async () => {
    const response = new Response('<html>502</html>', { status: 502 });

    const error = await toProblemError(response);

    expect(error.status).toBe(502);
    expect(error.type).toBe('about:blank');
  });
});
