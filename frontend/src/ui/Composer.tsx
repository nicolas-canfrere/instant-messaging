import { useRef, useState, type ChangeEvent, type KeyboardEvent } from 'react';
import { ACCEPT_ATTRIBUTE } from '../api/declaredType';
import { useMediaUpload, type TakenMedia } from '../hooks/useMediaUpload';

type Props = {
  /** Rend la promesse de l'envoi, mais le composant ne l'attend pas : voir `submit`. */
  onSend: (content: string, media: TakenMedia[]) => Promise<void>;
  /** Signale la frappe. L'etranglement vit dans `useTyping`, pas ici : ce composant reste bete. */
  onTyping: () => void;
};

/**
 * Zone de saisie. Volontairement bete : elle ne connait ni le reseau, ni le
 * `client_message_id`, ni le reessai — tout cela vit dans `useAppState`. Elle
 * ne sait que deux choses : quand l'utilisateur veut envoyer, et quoi.
 *
 * Le televersement fait exception, et c'est assume : il commence des le choix
 * du fichier, bien avant l'envoi du message. Il ne pouvait donc pas etre
 * declenche par `onSend`. Toute sa mecanique reste malgre tout hors du
 * composant, dans `useMediaUpload` — ici on n'affiche que ce qu'il rend.
 */
export function Composer({ onSend, onTyping }: Props) {
  const [content, setContent] = useState('');
  const uploads = useMediaUpload();

  // Reference sur l'`<input type="file">` pour deux raisons : le declencher
  // depuis un vrai bouton (l'input natif est masque, son rendu par defaut
  // n'etant pas stylable), et le remettre a zero apres chaque choix — sans quoi
  // rechoisir LE MEME fichier ne declencherait aucun `change`.
  const fileInputRef = useRef<HTMLInputElement>(null);

  // On envoie le contenu debarrasse de ses espaces de bord : un message fait
  // uniquement d'espaces n'a pas de sens, et le serveur le refuserait.
  const trimmed = content.trim();

  const readyMedia = uploads.pending.filter((item) => item.status === 'uploaded');

  // Un message peut desormais n'etre QUE des images : du texte OU au moins une
  // image suffit. En revanche on bloque tant qu'un transfert est en cours,
  // plutot que de laisser partir un message ampute de l'image qui n'a pas eu le
  // temps d'arriver.
  const canSend = (trimmed !== '' || readyMedia.length > 0) && !uploads.isUploading;

  function submit() {
    if (!canSend) return;

    // `takeUploaded()` vide la rangee de vignettes et transfere la propriete des
    // apercus au message : ils resteront affiches dans la bulle jusqu'a ce que
    // le serveur ait une vraie miniature.
    const media = uploads.takeUploaded();

    // Le champ est vide AVANT que le serveur ait repondu, et l'on n'attend pas
    // la promesse : c'est le principe de l'envoi optimiste. L'utilisateur peut
    // enchainer un second message sans attendre le premier, et l'echec eventuel
    // se lit sur la bulle du message (statut `failed`), pas ici.
    setContent('');
    void onSend(trimmed, media);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    // Convention des messageries : Entree envoie, Maj+Entree passe a la ligne.
    // `event.preventDefault()` est indispensable, sinon le saut de ligne est
    // insere en plus de l'envoi et le champ vide se retrouve avec un "\n".
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      submit();
    }
  }

  function handleFiles(event: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);

    // Chaque fichier part pour son propre compte : deux images ne s'attendent
    // pas l'une l'autre, et l'echec de l'une n'empeche pas l'autre d'aboutir.
    files.forEach((file) => void uploads.add(file));

    // Remise a zero : sans elle, rechoisir le meme fichier juste apres l'avoir
    // retire ne declencherait plus rien — la valeur de l'input n'aurait pas
    // change, donc pas d'evenement `change`.
    event.target.value = '';
  }

  return (
    <div className="border-t border-slate-200 bg-white px-4 py-3">
      {uploads.pending.length > 0 && (
        <ul className="mb-3 flex flex-wrap gap-2">
          {uploads.pending.map((item) => (
            <li key={item.localId} className="relative">
              {item.previewUrl !== null ? (
                <img
                  src={item.previewUrl}
                  alt={item.fileName}
                  className={`h-20 w-20 rounded border border-slate-300 object-cover ${
                    item.status === 'uploaded' ? '' : 'opacity-50'
                  }`}
                />
              ) : (
                // Un document n'a pas d'apercu visuel (voir useMediaUpload) :
                // on montre son nom a la place d'une vignette qui n'existerait pas.
                <div
                  className={`flex h-20 w-20 flex-col items-center justify-center gap-1 rounded border border-slate-300 bg-slate-50 px-1 text-center ${
                    item.status === 'uploaded' ? '' : 'opacity-50'
                  }`}
                >
                  <span aria-hidden="true" className="text-lg">
                    📄
                  </span>
                  <span className="w-full truncate text-[10px] text-slate-700">{item.fileName}</span>
                </div>
              )}

              {item.status === 'uploading' && (
                <span className="absolute inset-0 flex items-center justify-center text-xs text-slate-700">
                  Envoi…
                </span>
              )}

              {item.status === 'failed' && (
                <span className="absolute inset-0 flex items-center justify-center rounded bg-red-50/80 text-xs text-red-700">
                  Échec
                </span>
              )}

              <button
                type="button"
                onClick={() => uploads.remove(item.localId)}
                aria-label={`Retirer ${item.fileName}`}
                className="absolute -right-1 -top-1 h-5 w-5 rounded-full bg-slate-900 text-xs leading-none text-white"
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="flex items-end gap-2">
        <input
          ref={fileInputRef}
          type="file"
          // Commodite du selecteur seulement : ce n'est pas une validation,
          // declaredTypeFor() reste la porte reelle (voir useMediaUpload).
          accept={ACCEPT_ATTRIBUTE}
          multiple
          onChange={handleFiles}
          className="hidden"
          aria-label="Ajouter des fichiers"
        />

        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          className="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          Fichier
        </button>

        <textarea
          value={content}
          onChange={(event) => {
            setContent(event.target.value);
            onTyping();
          }}
          onKeyDown={handleKeyDown}
          rows={2}
          placeholder="Votre message… (Entrée pour envoyer, Maj+Entrée pour une nouvelle ligne)"
          aria-label="Votre message"
          className="flex-1 resize-none rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
        />

        <button
          type="button"
          onClick={submit}
          disabled={!canSend}
          className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-40"
        >
          Envoyer
        </button>
      </div>
    </div>
  );
}
