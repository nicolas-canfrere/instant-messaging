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

const USERS: Record<string, UserSummary> = { [ALICE.id]: ALICE, [BOB.id]: BOB };

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
