// Vite passe chaque feuille de style dans PostCSS. Tailwind est un plugin
// PostCSS : c'est ici qu'il est branche, et autoprefixer derriere lui ajoute
// les prefixes navigateurs.
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
