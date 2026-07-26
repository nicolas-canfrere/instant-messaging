import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import type { Me } from '../api/types';
import { CurrentUserBar } from './CurrentUserBar';

const ALICE: Me = { id: 'user-alice', username: 'alice', display_name: 'Alice Martin' };

describe('CurrentUserBar', () => {
  it('affiche le nom et l’identifiant de connexion du porteur de la session', () => {
    render(<CurrentUserBar me={ALICE} onLogout={vi.fn()} />);

    expect(screen.getByText('Alice Martin')).toBeDefined();
    // Le `@` distingue l'identifiant du nom affichable : deux comptes peuvent
    // porter le meme `display_name`, jamais le meme `username`.
    expect(screen.getByText('@alice')).toBeDefined();
  });

  it('remonte l’intention de deconnexion sans rien decider', () => {
    const onLogout = vi.fn();

    render(<CurrentUserBar me={ALICE} onLogout={onLogout} />);
    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }));

    expect(onLogout).toHaveBeenCalledTimes(1);
  });
});
