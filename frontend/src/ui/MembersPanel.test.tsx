import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { api } from '../api/client';
import type { ConversationDetail, UserSummary } from '../api/types';
import { MembersPanel } from './MembersPanel';

vi.mock('../api/client', () => ({
  api: { conversation: vi.fn(), addMembers: vi.fn() },
}));

const ALICE: UserSummary = { id: 'user-alice', username: 'alice', display_name: 'Alice' };
const BOB: UserSummary = { id: 'user-bob', username: 'bob', display_name: 'Bob' };
// Carol n'est PAS dans le groupe : sans elle, la liste des candidats a l'ajout
// serait vide et les tests sur le bloc « Ajouter » passeraient sans rien prouver.
const CAROL: UserSummary = { id: 'user-carol', username: 'carol', display_name: 'Carol' };

const USERS: Record<string, UserSummary> = {
  [ALICE.id]: ALICE,
  [BOB.id]: BOB,
  [CAROL.id]: CAROL,
};

/**
 * Alice administre, Bob est simple membre. Le type de retour est annote : sans
 * lui, `type: 'group'` s'elargirait en `string` et le mock ne satisferait plus
 * `api.conversation`.
 */
function detail(): ConversationDetail {
  return {
    id: 'conv-1',
    type: 'group',
    title: 'Equipe projet',
    members: [
      { user_id: ALICE.id, role: 'admin' },
      { user_id: BOB.id, role: 'member' },
    ],
  };
}

function renderPanel(meId: string, onLeave = vi.fn()) {
  vi.mocked(api.conversation).mockResolvedValue(detail());

  render(
    <MembersPanel
      conversationId="conv-1"
      users={USERS}
      meId={meId}
      onLeave={onLeave}
      onClose={vi.fn()}
    />,
  );

  return onLeave;
}

const LEAVE = { name: 'Quitter le groupe' };
const ADD = { name: 'Ajouter au groupe' };

afterEach(() => {
  vi.restoreAllMocks();
});

describe('MembersPanel', () => {
  it('propose de quitter le groupe a un simple membre', async () => {
    renderPanel(BOB.id);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
  });

  /**
   * L'admin ne PEUT pas partir tant qu'il n'a pas transfere ses droits : le 409
   * du serveur reste la vraie garantie, masquer le bouton evite seulement de
   * proposer une action interdite.
   */
  it('ne propose pas de quitter le groupe a un admin', async () => {
    renderPanel(ALICE.id);

    await waitFor(() => expect(screen.getByText('Bob')).toBeDefined());
    expect(screen.queryByRole('button', LEAVE)).toBeNull();
  });

  it('propose d’ajouter des membres a un admin', async () => {
    renderPanel(ALICE.id);

    await waitFor(() => expect(screen.getByRole('button', ADD)).toBeDefined());
  });

  /**
   * Le serveur repond 403 a un simple membre qui tente un ajout, et le voter le
   * journalise en `warning` — precisement pour signaler une interface qui
   * propose une action interdite. C'est le miroir du bouton de depart, masque
   * a l'admin : on ne propose jamais ce qu'on sait refuse.
   */
  it('ne propose pas d’ajouter des membres a un simple membre', async () => {
    renderPanel(BOB.id);

    await waitFor(() => expect(screen.getByText('Alice')).toBeDefined());
    expect(screen.queryByRole('button', ADD)).toBeNull();
    // Ni le bouton, ni les cases a cocher qui l'alimentent.
    expect(screen.queryByRole('checkbox')).toBeNull();
  });

  it('ne quitte rien si la confirmation est refusee', async () => {
    const onLeave = renderPanel(BOB.id);
    vi.spyOn(window, 'confirm').mockReturnValue(false);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
    fireEvent.click(screen.getByRole('button', LEAVE));

    expect(onLeave).not.toHaveBeenCalled();
  });

  it('quitte le groupe une fois la confirmation donnee', async () => {
    const onLeave = renderPanel(BOB.id);
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    await waitFor(() => expect(screen.getByRole('button', LEAVE)).toBeDefined());
    fireEvent.click(screen.getByRole('button', LEAVE));

    await waitFor(() => expect(onLeave).toHaveBeenCalledTimes(1));
  });
});
