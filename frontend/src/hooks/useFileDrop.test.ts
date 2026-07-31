import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DragEvent } from 'react';
import { useFileDrop } from './useFileDrop';

/** Evenement de depot minimal : seuls `preventDefault` et `files` comptent. */
function dropEvent(files: File[]) {
  return {
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
    dataTransfer: { files, items: files.map(() => ({ kind: 'file' })) },
  } as unknown as DragEvent;
}

describe('useFileDrop', () => {
  it("empeche le navigateur de quitter l'application pour ouvrir le fichier", () => {
    // Sans preventDefault sur `drop`, le navigateur navigue vers le fichier :
    // l'onglet est perdu, le brouillon avec. C'est la regression la plus
    // couteuse et la plus facile a introduire.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));
    const event = dropEvent([new File(['x'], 'a.pdf', { type: 'application/pdf' })]);

    act(() => result.current.handlers.onDrop(event));

    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('empeche aussi le navigateur de refuser le depot', () => {
    // Sans preventDefault sur `dragover`, `drop` ne se declenche jamais.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));
    const event = dropEvent([]);

    act(() => result.current.handlers.onDragOver(event));

    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('ne clignote pas quand le curseur passe sur un enfant', () => {
    // dragenter/dragleave se declenchent aussi sur les enfants. Un booleen
    // naif ferait disparaitre le voile au premier enfant survole. On compte
    // la profondeur.
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));

    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDragLeave(dropEvent([])));

    expect(result.current.isDragging).toBe(true);

    act(() => result.current.handlers.onDragLeave(dropEvent([])));

    expect(result.current.isDragging).toBe(false);
  });

  it('remet le compteur a zero au depot', () => {
    const { result } = renderHook(() => useFileDrop({ onFiles: vi.fn() }));

    act(() => result.current.handlers.onDragEnter(dropEvent([])));
    act(() => result.current.handlers.onDrop(dropEvent([])));

    expect(result.current.isDragging).toBe(false);
  });

  it('transmet les fichiers deposes', () => {
    const onFiles = vi.fn();
    const { result } = renderHook(() => useFileDrop({ onFiles }));
    const file = new File(['x'], 'notes.md', { type: '' });

    act(() => result.current.handlers.onDrop(dropEvent([file])));

    expect(onFiles).toHaveBeenCalledWith([file]);
  });
});
