/**
 * Pastille de presence. `aria-hidden` n'est PAS pose : l'information n'existe
 * nulle part ailleurs dans l'interface, un lecteur d'ecran doit donc l'entendre.
 */
export function PresenceDot({ online }: { online: boolean }) {
  return (
    <span
      className={`inline-block h-2 w-2 rounded-full ${online ? 'bg-emerald-500' : 'bg-slate-300'}`}
      title={online ? 'En ligne' : 'Hors ligne'}
      role="img"
      aria-label={online ? 'En ligne' : 'Hors ligne'}
    />
  );
}
