import { ACCEPTED_DESCRIPTION } from '../api/declaredType';

/**
 * Voile affiche par-dessus la conversation pendant un glisser-depose.
 *
 * `pointer-events-none` est indispensable : sans lui, c'est CE voile qui
 * recevrait les evenements `dragleave`/`drop` des que le curseur passe
 * au-dessus de lui, pas le conteneur qui porte les handlers de
 * `useFileDrop`. Le depot serait alors silencieusement perdu — le fichier
 * relache au-dessus du texte ne declencherait jamais `onDrop`.
 */
export function DropOverlay() {
  return (
    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-1 rounded border-2 border-dashed border-slate-400 bg-slate-900/10 text-center">
      <p className="text-base font-semibold text-slate-900">Déposez pour joindre</p>
      <p className="text-sm text-slate-700">{ACCEPTED_DESCRIPTION}</p>
    </div>
  );
}
