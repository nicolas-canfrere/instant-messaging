// `defineConfig` vient de 'vitest/config' et non de 'vite' : c'est la meme
// fonction, avec en plus la cle `test` dans son type. Importee depuis 'vite',
// TypeScript refuserait le bloc `test` plus bas alors que Vitest le lit tres
// bien a l'execution.
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Scrutation par sondage, et non par inotify. Le code source arrive dans le
    // conteneur par un bind mount, or les evenements inotify traversent mal
    // cette frontiere sous Linux : ils se perdent notamment quand un fichier est
    // REMPLACE plutot que modifie en place — ce que fait git a chaque `checkout`,
    // `merge`, `rebase` ou `stash`, puisqu'il ecrit a cote puis renomme, ce qui
    // change l'inode que Vite surveillait.
    //
    // Vite gardait alors sa transformation en cache et servait l'ANCIEN module,
    // sans que rien ne le signale : le disque et le conteneur etaient a jour,
    // seul le serveur etait en retard, et aucun rechargement force du navigateur
    // n'y changeait quoi que ce soit. On relit donc les dates de modification
    // toutes les 300 ms — un peu de CPU contre un cache qui ne ment jamais.
    watch: { usePolling: true, interval: 300 },
    // Le navigateur parle a Caddy sur 8080, pas a Vite sur 5173.
    // Sans cette ligne, le client HMR tenterait de se connecter au mauvais port
    // et le rechargement a chaud ne fonctionnerait jamais.
    hmr: { clientPort: 8080 },
  },
  test: {
    // `jsdom` et non `node` : les tests de composants ont besoin d'un DOM. Les
    // tests purs (reducer, retry, client temps reel) n'en souffrent pas — ils
    // ignorent simplement le document qu'on leur fournit.
    environment: 'jsdom',
    // `.tsx` en plus de `.ts`, sinon les tests de rendu ne sont jamais collectes
    // et la suite passe au vert en n'executant rien.
    include: ['src/**/*.test.{ts,tsx}'],
    setupFiles: ['src/test-setup.ts'],
  },
});
