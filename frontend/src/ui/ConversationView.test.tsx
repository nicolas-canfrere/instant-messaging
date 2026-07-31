import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ConversationSummary, UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import { emptyReceiptsState } from '../store/receiptsReducer';
import { emptyTypingState } from '../store/typingReducer';
import { ConversationView } from './ConversationView';

const ALICE: UserSummary = { id: 'user-alice', username: 'alice', display_name: 'Alice' };

function conversation(id: string, title: string): ConversationSummary {
  return {
    id,
    type: 'group',
    title,
    last_message_at: null,
    last_message_preview: null,
    last_message_sender_id: null,
    unread_count: 0,
  };
}

/** Un fil charge, sans page precedente : le composant n'ira rien chercher. */
function threadWith(conversationId: string, content: string): Thread {
  return {
    items: [
      {
        id: `01J000000000000000000${conversationId}`,
        clientMessageId: `client-${conversationId}`,
        conversationId,
        senderId: ALICE.id,
        content,
        createdAt: '2026-07-25T18:00:00+00:00',
        editedAt: null,
        deletedAt: null,
        status: 'sent',
        media: [],
      },
    ],
    nextBefore: null,
    loaded: true,
  };
}

/** Un fil charge et vide : rien a afficher, rien a aller chercher. */
function emptyThread(): Thread {
  return { items: [], nextBefore: null, loaded: true };
}

/**
 * Harnais par defaut pour les tests qui n'ont pas besoin de faire varier la
 * conversation elle-meme (voir `threadWith`/`conversation` ci-dessus pour le
 * test de non-regression sur le changement de fil, qui lui a besoin de
 * controler chaque appel a `render`).
 */
function renderConversation() {
  return render(
    <ConversationView
      conversation={conversation('conv-alpha', 'Alpha')}
      thread={emptyThread()}
      users={{ [ALICE.id]: ALICE }}
      peers={{}}
      meId="user-bob"
      typingState={emptyTypingState()}
      receiptsState={emptyReceiptsState()}
      onLoadOlder={vi.fn()}
      onSend={vi.fn(async () => {})}
      onDeleteMessage={vi.fn()}
      onEditMessage={vi.fn()}
      onMediaExpired={vi.fn()}
      onTyping={vi.fn()}
      onLeave={vi.fn(async () => {})}
    />,
  );
}

describe('ConversationView', () => {
  /**
   * Non-regression. `MessageList` et `Composer` sont deux enfants du meme
   * `<section>`, que React reconcilie comme un TABLEAU : leurs `key` doivent
   * donc etre uniques ENTRE FRERES. Les deux ont porte `key={conversation.id}`,
   * la meme des deux cotes, ce qui rendait l'appariement ancienne/nouvelle liste
   * indetermine : React ne marquait jamais l'ancienne `MessageList` pour
   * suppression et en montait une seconde a cote. Chaque aller-retour entre deux
   * conversations empilait un fil de plus dans le DOM, et l'utilisateur voyait
   * les messages des deux conversations melanges a l'ecran.
   *
   * On teste le symptome vu par l'utilisateur — le message de la conversation
   * quittee doit disparaitre — et non la forme des cles, qui n'est qu'un moyen.
   */
  it('ne laisse pas le fil precedent dans le DOM quand on change de conversation', () => {
    const alpha = conversation('conv-alpha', 'Alpha');
    const beta = conversation('conv-beta', 'Beta');
    const props = {
      users: { [ALICE.id]: ALICE },
      peers: {},
      meId: 'user-bob',
      // Personne n'ecrit : l'indicateur ne rend rien et ne perturbe pas le
      // comptage des `<ul>` plus bas.
      typingState: emptyTypingState(),
      receiptsState: emptyReceiptsState(),
      onLoadOlder: vi.fn(),
      onSend: vi.fn(async () => {}),
      onDeleteMessage: vi.fn(),
      onEditMessage: vi.fn(),
      onMediaExpired: vi.fn(),
      onTyping: vi.fn(),
      onLeave: vi.fn(async () => {}),
    };

    const { container, rerender } = render(
      <ConversationView
        conversation={alpha}
        thread={threadWith('conv-alpha', 'message dans Alpha')}
        {...props}
      />,
    );

    expect(screen.getByText('message dans Alpha')).toBeDefined();

    rerender(
      <ConversationView
        conversation={beta}
        thread={threadWith('conv-beta', 'message dans Beta')}
        {...props}
      />,
    );

    expect(screen.getByText('message dans Beta')).toBeDefined();
    expect(screen.queryByText('message dans Alpha')).toBeNull();

    // La meme verite, vue par la structure : un seul fil monte. C'est la
    // duplication elle-meme qu'on surveille ici, pas seulement son effet
    // visible — le `<ul>` des messages est le seul de ce composant.
    expect(container.querySelectorAll('ul')).toHaveLength(1);
  });

  it('joint un fichier depose sur la conversation', async () => {
    // La cible est toute la conversation, pas le composer : l'utilisateur ne
    // vise pas, il lache.
    renderConversation();

    fireEvent.drop(screen.getByTestId('conversation'), {
      dataTransfer: { files: [new File(['x'], 'notes.md', { type: '' })] },
    });

    // Comme les autres tests de ce fichier, ce depot n'utilise pas
    // `@testing-library/jest-dom` (absent du projet) : `toBeDefined()` suffit,
    // `findByText`/`getByText` levent deja s'ils ne trouvent rien.
    expect(await screen.findByText('notes.md')).toBeDefined();
  });

  it('refuse un fichier non supporte sans aucun appel reseau', () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    renderConversation();

    fireEvent.drop(screen.getByTestId('conversation'), {
      dataTransfer: { files: [new File(['x'], 'archive.zip', { type: 'application/zip' })] },
    });

    expect(screen.getByText(/type non accepté/i)).toBeDefined();
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
