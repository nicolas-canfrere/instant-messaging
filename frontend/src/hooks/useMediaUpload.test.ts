import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { api } from '../api/client';
import { putBytes } from '../api/upload';
import { useMediaUpload, type PendingUpload } from './useMediaUpload';

// Les deux frontieres du hook : notre API typee, et le PUT brut vers le
// stockage. Rien d'autre n'est double — le reste est la logique qu'on teste.
vi.mock('../api/client', () => ({
  api: { presignUpload: vi.fn(), confirmUpload: vi.fn() },
}));

vi.mock('../api/upload', () => ({ putBytes: vi.fn() }));

const MEDIA_ID = '01JQZ0000000000000000040AA';

/**
 * jsdom n'implemente ni `createObjectURL` ni `revokeObjectURL` : ce sont des
 * API du vrai navigateur. On les remplace par des espions, ce qui tombe bien —
 * la revocation est justement ce que ce fichier doit verifier.
 */
function stubObjectUrls(): { create: ReturnType<typeof vi.fn>; revoke: ReturnType<typeof vi.fn> } {
  const create = vi.fn((_file: Blob) => `blob:fake/${create.mock.calls.length}`);
  const revoke = vi.fn();

  Object.defineProperty(URL, 'createObjectURL', { value: create, configurable: true });
  Object.defineProperty(URL, 'revokeObjectURL', { value: revoke, configurable: true });

  return { create, revoke };
}

/**
 * `noUncheckedIndexedAccess` est actif : `pending[0]` est type `… | undefined`.
 * Plutot que semer des `!` dans les assertions, on echoue ici avec un message
 * qui dit ce qui manque reellement.
 */
function first(list: PendingUpload[]): PendingUpload {
  const [head] = list;

  if (head === undefined) {
    throw new Error('Aucune vignette en attente.');
  }

  return head;
}

function anImage(name = 'chat.jpg'): File {
  return new File(['des-octets'], name, { type: 'image/jpeg' });
}

describe('useMediaUpload', () => {
  let urls: ReturnType<typeof stubObjectUrls>;

  beforeEach(() => {
    urls = stubObjectUrls();

    vi.mocked(api.presignUpload).mockResolvedValue({
      media_id: MEDIA_ID,
      upload_url: 'https://stockage.test/signe',
      expires_at: '2026-07-26T09:05:00+00:00',
    });
    vi.mocked(api.confirmUpload).mockResolvedValue(undefined);
    vi.mocked(putBytes).mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.resetAllMocks();
  });

  it('affiche immediatement un apercu local, sans attendre le reseau', async () => {
    const { result } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage());
    });

    expect(result.current.pending).toHaveLength(1);
    expect(first(result.current.pending).previewUrl).toBe('blob:fake/1');
    expect(urls.create).toHaveBeenCalledOnce();
  });

  it('passe a `uploaded` et rend l\'identifiant serveur une fois le cycle complet', async () => {
    const { result } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage());
    });

    await waitFor(() => expect(first(result.current.pending).status).toBe('uploaded'));

    // Les trois etapes, dans l'ordre : reserver, televerser, confirmer.
    expect(api.presignUpload).toHaveBeenCalledWith('chat.jpg', 'image/jpeg', 10);
    expect(putBytes).toHaveBeenCalledWith('https://stockage.test/signe', expect.any(File));
    expect(api.confirmUpload).toHaveBeenCalledWith(MEDIA_ID);

    const taken = result.current.takeUploaded();
    expect(taken.map((media) => media.mediaId)).toEqual([MEDIA_ID]);
  });

  it('marque `failed` et n\'attache rien quand le transfert echoue', async () => {
    vi.mocked(putBytes).mockRejectedValue(new Error('Le transfert a echoue (403).'));

    const { result } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage());
    });

    await waitFor(() => expect(first(result.current.pending).status).toBe('failed'));

    // Le point de ce cas : un media dont les octets ne sont jamais arrives ne
    // doit PAS partir avec le message. Le serveur refuserait de toute facon —
    // mais l'utilisateur verrait un message casse plutot qu'une vignette en
    // erreur qu'il peut retirer.
    expect(result.current.takeUploaded()).toEqual([]);
    expect(api.confirmUpload).not.toHaveBeenCalled();
  });

  it('revoque l\'apercu exactement une fois quand on retire une vignette', async () => {
    const { result } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage());
    });

    const { localId, previewUrl } = first(result.current.pending);

    act(() => {
      result.current.remove(localId);
    });

    expect(result.current.pending).toHaveLength(0);
    expect(urls.revoke).toHaveBeenCalledExactlyOnceWith(previewUrl);
  });

  it('revoque tout ce qui reste en attente au demontage', async () => {
    const { result, unmount } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage('une.jpg'));
      await result.current.add(anImage('deux.jpg'));
    });

    const previews = result.current.pending.map((media) => media.previewUrl);
    expect(previews).toHaveLength(2);

    unmount();

    // La fuite classique de ce motif : sans ce nettoyage, le navigateur retient
    // chaque fichier ENTIER en memoire tant que l'onglet vit.
    expect(urls.revoke).toHaveBeenCalledTimes(2);
    previews.forEach((preview) => expect(urls.revoke).toHaveBeenCalledWith(preview));
  });

  /**
   * Le pendant du cas precedent, et la subtilite du hook : ce qui est PARTI
   * avec un message ne doit plus etre revoque ici. Son apercu est desormais
   * affiche par la bulle du message, jusqu'a ce que le serveur ait une vraie
   * miniature. Le revoquer casserait l'image sous les yeux de l'expediteur.
   */
  it('ne revoque pas au demontage les apercus deja partis avec un message', async () => {
    const { result, unmount } = renderHook(() => useMediaUpload());

    await act(async () => {
      await result.current.add(anImage());
    });
    await waitFor(() => expect(first(result.current.pending).status).toBe('uploaded'));

    act(() => {
      result.current.takeUploaded();
    });

    expect(result.current.pending).toHaveLength(0);

    unmount();

    expect(urls.revoke).not.toHaveBeenCalled();
  });
});
