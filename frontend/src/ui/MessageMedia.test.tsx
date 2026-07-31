import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import type { StoredMedia } from '../store/messagesReducer';
import { MessageMedia } from './MessageMedia';

const MEDIA_ID = '01JQZ0000000000000000060AA';

function media(overrides: Partial<StoredMedia> = {}): StoredMedia {
  return {
    id: MEDIA_ID,
    status: 'processing',
    // `null` : c'est l'etat normal tant que le worker n'a pas tranche. Les
    // tests d'image ci-dessous n'en ont pas besoin, `isDocument` se rabattant
    // sur l'extension de `filename`, qui reste une image par defaut.
    mimeType: null,
    url: null,
    thumbnailUrl: null,
    width: null,
    height: null,
    previewUrl: null,
    filename: 'photo.jpg',
    ...overrides,
  };
}

/** Un document PDF pret, avec son lien de telechargement. */
function documentReady(overrides: Partial<StoredMedia> = {}): StoredMedia {
  return media({
    status: 'ready',
    mimeType: 'application/pdf',
    url: 'https://stockage.test/rapport.pdf?X-Amz-Signature=abc',
    filename: 'rapport.pdf',
    ...overrides,
  });
}

/** Un document encore en cours d'inspection par le worker. */
function documentProcessing(overrides: Partial<StoredMedia> = {}): StoredMedia {
  return media({
    status: 'processing',
    filename: 'rapport.pdf',
    ...overrides,
  });
}

describe('MessageMedia', () => {
  it('affiche la miniature dans un lien vers l image entiere quand elle est prete', () => {
    render(
      <MessageMedia
        media={media({
          status: 'ready',
          url: 'https://stockage.test/original?X-Amz-Signature=abc',
          thumbnailUrl: 'https://stockage.test/thumb?X-Amz-Signature=abc',
          width: 1600,
          height: 900,
        })}
        onExpired={vi.fn()}
      />,
    );

    const image = screen.getByRole('img');
    expect(image.getAttribute('src')).toBe('https://stockage.test/thumb?X-Amz-Signature=abc');

    // La miniature s'affiche, l'original s'ouvre : on ne telecharge jamais
    // 8 Mo pour remplir une bulle de 400 px.
    expect(image.closest('a')?.getAttribute('href')).toBe(
      'https://stockage.test/original?X-Amz-Signature=abc',
    );
  });

  /**
   * L'erreur attendue n'est pas une image cassee : c'est une URL signee
   * EXPIREE, dans un onglet reste ouvert plus de quinze minutes. Sans ce
   * rappel, l'image resterait definitivement vide alors qu'un simple
   * rechargement la ramene.
   */
  it('previent qu il faut resigner quand le chargement de la miniature echoue', () => {
    const onExpired = vi.fn();

    render(
      <MessageMedia
        media={media({
          status: 'ready',
          url: 'https://stockage.test/original?X-Amz-Signature=perimee',
          thumbnailUrl: 'https://stockage.test/thumb?X-Amz-Signature=perimee',
        })}
        onExpired={onExpired}
      />,
    );

    fireEvent.error(screen.getByRole('img'));

    expect(onExpired).toHaveBeenCalledOnce();
  });

  it('annonce un refus plutot que de laisser un emplacement vide', () => {
    render(<MessageMedia media={media({ status: 'rejected' })} onExpired={vi.fn()} />);

    expect(screen.getByText('Fichier refusé')).not.toBeNull();
    // Rien a montrer, donc pas d'image : surtout pas une URL signee vers des
    // octets qu'on vient precisement de refuser.
    expect(screen.queryByRole('img')).toBeNull();
  });

  it('montre son apercu local a l expediteur pendant le traitement', () => {
    render(
      <MessageMedia media={media({ previewUrl: 'blob:local/1' })} onExpired={vi.fn()} />,
    );

    expect(screen.getByRole('img').getAttribute('src')).toBe('blob:local/1');
  });

  /**
   * Le vrai sujet du placeholder : reserver la place. Sans hauteur reservee,
   * la liste saute au moment ou l'image arrive et le lecteur perd sa ligne.
   */
  it('reserve les proportions de l image quand le serveur les connait deja', () => {
    const { container } = render(
      <MessageMedia media={media({ width: 1600, height: 900 })} onExpired={vi.fn()} />,
    );

    const placeholder = container.querySelector<HTMLElement>('[style]');
    expect(placeholder?.style.aspectRatio).toBe('1600 / 900');
  });

  it('retombe sur une taille par defaut quand les dimensions sont inconnues', () => {
    const { container } = render(<MessageMedia media={media()} onExpired={vi.fn()} />);

    const placeholder = container.querySelector<HTMLElement>('[style]');
    expect(placeholder?.style.height).toBe('9rem');
  });

  it('affiche une piece jointe nommee pour un document pret, pas une image', () => {
    render(<MessageMedia media={documentReady({ filename: 'rapport.pdf' })} onExpired={() => {}} />);

    expect(screen.getByText('rapport.pdf')).not.toBeNull();
    expect(screen.queryByRole('img')).toBeNull();
  });

  it("garde la meme hauteur entre l'attente et l'affichage d'un document", () => {
    // Pas de saut de mise en page a traiter : contrairement a une image, la
    // hauteur d'une piece jointe ne depend pas de son contenu.
    const { container: waiting } = render(
      <MessageMedia media={documentProcessing()} onExpired={() => {}} />,
    );
    const { container: ready } = render(<MessageMedia media={documentReady()} onExpired={() => {}} />);

    expect(waiting.firstElementChild?.className).toContain('h-14');
    expect(ready.firstElementChild?.className).toContain('h-14');
  });

  it('affiche une image des que le worker a tranche le mimeType, meme si le nom trompe', () => {
    // `filename` porte une extension de document (`.csv`) mais `mimeType` dit
    // "image" : ce conflit force `isDocument` a passer par la branche
    // `mimeType !== null`, jamais par le repli sur l'extension — sans quoi ce
    // test rendrait quand meme une image "par accident" (un `.jpg` n'aurait
    // rien prouve, cf. revue finale). C'est l'etat regulier apres traitement
    // par le worker, pour CHAQUE image de l'application.
    render(
      <MessageMedia
        media={media({
          status: 'ready',
          mimeType: 'image/jpeg',
          filename: 'export.csv',
          url: 'https://stockage.test/original?X-Amz-Signature=abc',
          thumbnailUrl: 'https://stockage.test/thumb?X-Amz-Signature=abc',
          width: 1600,
          height: 900,
        })}
        onExpired={vi.fn()}
      />,
    );

    expect(screen.getByRole('img')).not.toBeNull();
    expect(screen.queryByText('export.csv')).toBeNull();
  });

  it("n'affiche aucun apercu local pour un document en cours", () => {
    // L'expediteur d'un PDF voit la meme chose que les autres : il n'y a rien
    // a previsualiser. C'est la difference avec le cas image.
    render(<MessageMedia media={documentProcessing()} onExpired={() => {}} />);

    expect(screen.queryByRole('img')).toBeNull();
    expect(screen.getByText(/en cours/i)).not.toBeNull();
  });
});
