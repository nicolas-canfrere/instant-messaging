type Props = {
  onDelete: () => void;
};

/**
 * Actions au survol, sur ses propres messages vivants uniquement. Le menu est
 * rendu en permanence dans le DOM et revele par `group-hover` : le monter au
 * survol ferait sauter la hauteur de la bulle.
 */
export function MessageActions({ onDelete }: Props) {
  return (
    <div className="absolute right-1 top-1 hidden gap-1 group-hover:flex">
      <button
        type="button"
        onClick={onDelete}
        className="rounded bg-white/10 px-1 text-xs hover:bg-white/20"
        aria-label="Supprimer le message"
      >
        Supprimer
      </button>
    </div>
  );
}
