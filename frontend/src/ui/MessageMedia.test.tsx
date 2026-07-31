import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import type { StoredMedia } from '../store/messagesReducer';
import { MessageMedia } from './MessageMedia';

const MEDIA_ID = '01JQZ0000000000000000060AA';

function media(overrides: Partial<StoredMedia> = {}): StoredMedia {
  return {
    id: MEDIA_ID,
    status: 'processing',
    url: null,
    thumbnailUrl: null,
    width: null,
    height: null,
    previewUrl: null,
    ...overrides,
  };
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
});
