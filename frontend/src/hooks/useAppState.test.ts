import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { api } from '../api/client';
import type { ApiMessage, ConversationSummary, Me } from '../api/types';
import { selectThread } from '../store/messagesReducer';
import { useAppState } from './useAppState';

/**
 * `useAppState` parle au serveur et ouvre un flux SSE des son montage. On
 * remplace donc le client HTTP en entier — c'est la seule frontiere du hook — et
 * on fournit un `EventSource` inerte, jsdom n'en ayant pas. Le flux ne pousse
 * rien : ce fichier verifie ce que le hook fait de ses PROPRES appels, pas ce
 * qu'il fait des echos du hub, que `messagesReducer.test.ts` couvre deja.
 */
vi.mock('../api/client', () => ({
  api: {
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    users: vi.fn(),
    conversations: vi.fn(),
    conversation: vi.fn(),
    messages: vi.fn(),
    sendMessage: vi.fn(),
    createDirect: vi.fn(),
    createGroup: vi.fn(),
    addMembers: vi.fn(),
    realtimeToken: vi.fn(),
    heartbeat: vi.fn(),
    typing: vi.fn(),
    receipts: vi.fn(),
    deleteMessage: vi.fn(),
    editMessage: vi.fn(),
    leaveConversation: vi.fn(),
  },
}));

const ME: Me = { id: 'user-alice', username: 'alice', display_name: 'Alice' };
const CONVERSATION_ID = 'conv-alpha';
const MESSAGE_ID = '01J0000000000000000000000A';

function apiMessage(overrides: Partial<ApiMessage> = {}): ApiMessage {
  return {
    id: MESSAGE_ID,
    conversation_id: CONVERSATION_ID,
    sender_id: ME.id,
    content: 'bonjour',
    client_message_id: 'client-a',
    created_at: '2026-07-26T09:00:00+00:00',
    edited_at: null,
    deleted_at: null,
    ...overrides,
  };
}

function conversationSummary(id: string): ConversationSummary {
  return {
    id,
    type: 'group',
    title: 'Equipe projet',
    last_message_at: null,
    last_message_preview: null,
    last_message_sender_id: null,
    unread_count: 0,
  };
}

/** Flux inerte : il ne pousse jamais rien, il se contente d'exister et de se fermer. */
class InertEventSource {
  onmessage: unknown = null;
  onerror: unknown = null;

  addEventListener(): void {}
  close(): void {}
}

beforeEach(() => {
  vi.stubGlobal('EventSource', InertEventSource);

  vi.mocked(api.conversations).mockResolvedValue([]);
  vi.mocked(api.users).mockResolvedValue([]);
  vi.mocked(api.heartbeat).mockResolvedValue({ online_user_ids: [] });
  vi.mocked(api.realtimeToken).mockResolvedValue({ hub_url: 'http://hub', topics: [] });
  vi.mocked(api.receipts).mockResolvedValue(undefined);
  vi.mocked(api.messages).mockResolvedValue({ items: [apiMessage()], next_before: null });
  vi.mocked(api.deleteMessage).mockResolvedValue(undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

/** Monte le hook et charge le fil de `CONVERSATION_ID`, pret a etre mute. */
async function renderWithLoadedThread() {
  const rendered = renderHook(() => useAppState(ME));

  await act(async () => {
    rendered.result.current.selectConversation(CONVERSATION_ID);
  });

  await waitFor(() => {
    expect(selectThread(rendered.result.current.messagesState, CONVERSATION_ID).loaded).toBe(true);
  });

  return rendered;
}

describe('useAppState', () => {
  /**
   * Non-regression. `DELETE` repondait 204 sans que rien ne soit dispatche
   * localement : quand le hub est injoignable, l'echo SSE n'arrive jamais et le
   * message supprime restait affiche, sans la moindre erreur visible.
   */
  it('marque le message comme supprime des le 204, sans attendre l echo SSE', async () => {
    const { result } = await renderWithLoadedThread();

    await act(async () => {
      await result.current.deleteMessage(CONVERSATION_ID, MESSAGE_ID);
    });

    const item = selectThread(result.current.messagesState, CONVERSATION_ID).items[0];

    expect(item?.content).toBeNull();
    // Un instant client provisoire, que l'echo SSE ecrasera : il ne sert qu'a
    // lever le drapeau du tombstone, jamais a ordonner.
    expect(item?.deletedAt).not.toBeNull();
    expect(Number.isNaN(new Date(item?.deletedAt ?? '').getTime())).toBe(false);
  });

  /**
   * Meme motif que la suppression d'un message : on applique des le 204. Si le
   * hub est injoignable, l'echo `membership.changed` n'arrivera jamais et la
   * conversation quittee resterait affichee, selectionnee, sans erreur visible.
   */
  it('deselectionne la conversation quittee des le 204, sans attendre l echo SSE', async () => {
    // Une conversation au montage, plus aucune au rafraichissement d'apres le
    // depart : c'est exactement ce que le serveur renverra.
    vi.mocked(api.conversations)
      .mockResolvedValueOnce([conversationSummary(CONVERSATION_ID)])
      .mockResolvedValue([]);
    vi.mocked(api.leaveConversation).mockResolvedValue(undefined);

    const { result } = await renderWithLoadedThread();

    expect(result.current.selectedId).toBe(CONVERSATION_ID);

    await act(async () => {
      await result.current.leaveConversation(CONVERSATION_ID);
    });

    expect(api.leaveConversation).toHaveBeenCalledWith(CONVERSATION_ID);
    expect(result.current.selectedId).toBeNull();
    expect(result.current.conversations).toEqual([]);
  });

  /**
   * Le depart a REUSSI des que le 204 est la ; ce qui echoue ensuite n'est
   * qu'un geste de suivi. Laisser ce rejet remonter faisait passer un succes
   * pour un echec — et pire, en silence : `MembersPanel` recevait bien
   * l'erreur, mais la deselection venait de le demonter, donc son `setError`
   * ne peignait plus rien. Meme motif que `afterCreated`.
   */
  it('ne transforme pas un depart reussi en echec quand le rafraichissement casse', async () => {
    vi.mocked(api.conversations)
      .mockResolvedValueOnce([conversationSummary(CONVERSATION_ID)])
      .mockRejectedValue(new Error('reseau'));
    vi.mocked(api.leaveConversation).mockResolvedValue(undefined);

    // La panne part dans la console, comme toute anomalie temps reel.
    const console_ = vi.spyOn(console, 'error').mockImplementation(() => {});

    const { result } = await renderWithLoadedThread();

    await act(async () => {
      await expect(result.current.leaveConversation(CONVERSATION_ID)).resolves.toBeUndefined();
    });

    expect(result.current.selectedId).toBeNull();
    expect(console_).toHaveBeenCalled();
  });
});
