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
    // Le navigateur parle a Caddy sur 8080, pas a Vite sur 5173.
    // Sans cette ligne, le client HMR tenterait de se connecter au mauvais port
    // et le rechargement a chaud ne fonctionnerait jamais.
    hmr: { clientPort: 8080 },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
