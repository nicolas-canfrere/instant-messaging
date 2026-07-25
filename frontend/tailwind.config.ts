import type { Config } from 'tailwindcss';

// Tailwind ne genere que les classes qu'il voit ecrites dans ces fichiers :
// `content` n'est pas une liste de sources a compiler, c'est la liste des
// endroits ou chercher des noms de classes. Un fichier oublie ici donne des
// styles absents en production sans la moindre erreur.
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {},
  },
  plugins: [],
} satisfies Config;
