import { useState, type FormEvent } from 'react';
import { api } from '../api/client';
import { ProblemError } from '../api/problem';
import type { Me } from '../api/types';

export function LoginScreen({ onAuthenticated }: { onAuthenticated: (me: Me) => void }) {
  const [username, setUsername] = useState('alice');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.login(username, password);
      onAuthenticated(await api.me());
    } catch (cause) {
      setError(cause instanceof ProblemError ? cause.detail : 'Connexion impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="mx-auto mt-24 flex w-80 flex-col gap-4">
      <h1 className="text-xl font-semibold">Connexion</h1>

      <input
        className="rounded border border-slate-300 px-3 py-2"
        value={username}
        onChange={(event) => setUsername(event.target.value)}
        placeholder="Identifiant"
        autoComplete="username"
      />
      <input
        className="rounded border border-slate-300 px-3 py-2"
        type="password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        placeholder="Mot de passe"
        autoComplete="current-password"
      />

      {error && <p role="alert" className="text-sm text-red-600">{error}</p>}

      <button
        type="submit"
        disabled={busy}
        className="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
      >
        {busy ? 'Connexion…' : 'Se connecter'}
      </button>
    </form>
  );
}
