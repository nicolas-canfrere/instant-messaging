import { useEffect, useState } from 'react';
import { api } from './api/client';
import type { Me } from './api/types';
import { LoginScreen } from './ui/LoginScreen';

// Trois etats et non deux : tant que /api/me n'a pas repondu, on ne SAIT pas si
// la session existe. Afficher l'ecran de connexion pendant ce laps de temps le
// ferait clignoter a chaque rechargement d'un utilisateur deja connecte.
type Session = { state: 'loading' } | { state: 'anonymous' } | { state: 'authenticated'; me: Me };

export default function App() {
  const [session, setSession] = useState<Session>({ state: 'loading' });

  useEffect(() => {
    // La session vit dans un cookie que JavaScript ne peut pas lire (HttpOnly) :
    // le seul moyen de savoir si l'on est connecte est de poser la question au
    // serveur. Un 401 remonte en ProblemError, ce qui vaut "anonyme".
    let cancelled = false;

    api
      .me()
      .then((me) => {
        if (!cancelled) setSession({ state: 'authenticated', me });
      })
      .catch(() => {
        if (!cancelled) setSession({ state: 'anonymous' });
      });

    // En mode strict, React monte l'effet deux fois : ce drapeau evite qu'une
    // reponse tardive du premier montage n'ecrase l'etat du second.
    return () => {
      cancelled = true;
    };
  }, []);

  if (session.state === 'loading') {
    return <main className="mt-24 text-center text-slate-500">Chargement…</main>;
  }

  if (session.state === 'anonymous') {
    return <LoginScreen onAuthenticated={(me) => setSession({ state: 'authenticated', me })} />;
  }

  // Placeholder assume : la liste de conversations et le fil de messages
  // arrivent a la tache 16 du plan. On ne les anticipe pas ici.
  return (
    <main className="mx-auto mt-24 w-80 text-center">
      <h1 className="text-xl font-semibold">Instant Messaging</h1>
      <p className="mt-2 text-slate-600">Connecte en tant que {session.me.display_name}.</p>
    </main>
  );
}
