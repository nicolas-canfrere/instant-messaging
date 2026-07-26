import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MessageEditor } from './MessageEditor';

describe('MessageEditor', () => {
  it('soumet le texte rogne quand il a change', () => {
    const onSubmit = vi.fn();
    const onCancel = vi.fn();

    render(<MessageEditor initialContent="bonjor" onSubmit={onSubmit} onCancel={onCancel} />);

    const textarea = screen.getByLabelText('Modifier le message');
    fireEvent.change(textarea, { target: { value: '  bonjour  ' } });
    fireEvent.keyDown(textarea, { key: 'Enter' });

    expect(onSubmit).toHaveBeenCalledWith('bonjour');
    expect(onCancel).not.toHaveBeenCalled();
  });

  /**
   * Valider sans avoir rien change ne doit pas partir en `PATCH`. Le serveur le
   * traiterait en no-op, ce n'est donc pas une economie de requete : c'est le
   * chemin qui produisait la fausse mention « modifie », la reponse d'un no-op
   * portant `edited_at: null` que le front recopiait de travers.
   */
  it('ne soumet pas quand le texte rogne est identique au contenu initial', () => {
    const onSubmit = vi.fn();
    const onCancel = vi.fn();

    render(<MessageEditor initialContent="bonjour" onSubmit={onSubmit} onCancel={onCancel} />);

    const textarea = screen.getByLabelText('Modifier le message');
    fireEvent.change(textarea, { target: { value: '  bonjour  ' } });
    fireEvent.keyDown(textarea, { key: 'Enter' });

    expect(onSubmit).not.toHaveBeenCalled();
    expect(onCancel).toHaveBeenCalled();
  });

  it('ne soumet pas un contenu vide', () => {
    const onSubmit = vi.fn();

    render(<MessageEditor initialContent="bonjour" onSubmit={onSubmit} onCancel={vi.fn()} />);

    const textarea = screen.getByLabelText('Modifier le message');
    fireEvent.change(textarea, { target: { value: '   ' } });
    fireEvent.keyDown(textarea, { key: 'Enter' });

    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('annule sur Echap sans soumettre', () => {
    const onSubmit = vi.fn();
    const onCancel = vi.fn();

    render(<MessageEditor initialContent="bonjour" onSubmit={onSubmit} onCancel={onCancel} />);

    fireEvent.keyDown(screen.getByLabelText('Modifier le message'), { key: 'Escape' });

    expect(onCancel).toHaveBeenCalled();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
