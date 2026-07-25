import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

/**
 * Demonte ce qui a ete rendu et vide le document entre deux tests.
 *
 * Testing Library sait le faire toute seule, mais uniquement quand Vitest tourne
 * en mode `globals: true` — c'est alors le `afterEach` global qu'elle accroche.
 * On ne l'active pas (importer explicitement ce qu'on utilise vaut mieux qu'une
 * variable magique), donc on enregistre le nettoyage nous-memes.
 *
 * Sans lui, chaque test rendrait par-dessus les precedents dans le meme
 * document : une assertion sur « un seul conteneur de messages » compterait ceux
 * du test d'avant et echouerait pour une raison qui n'a rien a voir avec le code
 * teste. Precisement le genre de faux positif qu'un test de non-regression sur
 * une duplication de noeuds ne peut pas se permettre.
 */
afterEach(cleanup);
