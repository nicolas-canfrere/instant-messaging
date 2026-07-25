import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
// La feuille de style est importee depuis le JavaScript : c'est Vite qui la
// prend en charge, il n'y a donc aucun <link> a ecrire dans index.html.
import './index.css';

const container = document.getElementById('root');

// TypeScript considere que getElementById peut rendre null. Plutot que de
// forcer le type, on echoue bruyamment : si ce div disparait de index.html,
// on veut le savoir tout de suite, pas debugger un ecran blanc.
if (container === null) {
  throw new Error("L'element #root est introuvable dans index.html");
}

// StrictMode monte, demonte puis remonte chaque composant en developpement.
// Ce double montage est volontaire : il revele les effets mal nettoyes. C'est
// exactement ce qui nous servira quand l'EventSource de Mercure arrivera.
createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
