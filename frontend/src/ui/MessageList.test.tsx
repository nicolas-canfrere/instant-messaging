import { describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import type { UserSummary } from '../api/types';
import type { StoredMessage, Thread } from '../store/messagesReducer';
import { emptyReceiptsState } from '../store/receiptsReducer';
import { formatRelative } from './dates';
import { deletedMessageLabel, editedMessageLabel, EDIT_WINDOW_MS } from './labels';
import { EDIT_CLOCK_TICK_MS, MessageList } from './MessageList';

/**
 * Ce fichier ne teste PAS le balisage : il teste les CONDITIONS d'affichage.
 *
 * Chacune combine plusieurs drapeaux du message (`id`, `deletedAt`, `senderId`,
 * la fenetre d'edition) et s'inverse donc silencieusement — c'est exactement ce
 * qui a laisse passer le bug ou tout message optimiste s'affichait comme un
 * editeur ouvert : `editingId` vaut `null` a l'etat initial, `id` vaut `null`
 * sur un envoi optimiste, et `null === null` est vrai.
 */

const ALICE: UserSummary = { id: 'user-alice', username: 'alice', display_name: 'Alice' };
const CONVERSATION_ID = 'conv-alpha';

function message(overrides: Partial<StoredMessage>): StoredMessage {
  return {
    id: '01J0000000000000000000000A',
    clientMessageId: 'client-a',
    conversationId: CONVERSATION_ID,
    senderId: ALICE.id,
    content: 'bonjour',
    // Recent, sinon la fenetre d'edition de 15 min serait deja close et
    // l'action « Modifier » masquee pour une raison sans rapport avec le test.
    createdAt: new Date().toISOString(),
    editedAt: null,
    deletedAt: null,
    status: 'sent',
    media: [],
    ...overrides,
  };
}

function threadOf(...items: StoredMessage[]): Thread {
  return { items, nextBefore: null, loaded: true };
}

function renderList(thread: Thread) {
  return render(
    <MessageList
      thread={thread}
      users={{ [ALICE.id]: ALICE }}
      meId={ALICE.id}
      conversationId={CONVERSATION_ID}
      receiptsState={emptyReceiptsState()}
      isGroup={false}
      onLoadOlder={vi.fn()}
      onDeleteMessage={vi.fn()}
      onEditMessage={vi.fn()}
    />,
  );
}

describe('MessageList', () => {
  /**
   * Non-regression du bug critique. Un envoi optimiste n'a pas encore d'`id`
   * serveur ; aucun editeur ne doit s'ouvrir sur lui, ni pendant l'aller-retour
   * reseau, ni definitivement quand l'envoi echoue (`failed` garde `id: null`).
   */
  it("n'ouvre pas d'editeur sur un message optimiste", () => {
    const { container } = renderList(
      threadOf(message({ id: null, content: 'en cours d envoi', status: 'pending' })),
    );

    expect(container.querySelector('textarea')).toBeNull();
    expect(screen.getByText('en cours d envoi')).toBeDefined();
  });

  it("n'ouvre pas d'editeur sur un envoi echoue", () => {
    const { container } = renderList(
      threadOf(message({ id: null, content: 'echoue', status: 'failed' })),
    );

    expect(container.querySelector('textarea')).toBeNull();
    expect(screen.getByText('echoue')).toBeDefined();
  });

  /**
   * Le tombstone est rendu, pas masque : il garde sa place dans l'ordre. Mais il
   * n'offre plus rien — ni menu d'action (il n'y a plus rien a editer ni a
   * supprimer), ni coches de reception (elles decriraient un contenu efface).
   */
  it('affiche le libelle du tombstone, sans action ni coche', () => {
    renderList(
      threadOf(message({ content: null, deletedAt: '2026-07-26T12:00:00+00:00' })),
    );

    expect(screen.getByText(deletedMessageLabel)).toBeDefined();
    expect(screen.queryByLabelText('Supprimer le message')).toBeNull();
    expect(screen.queryByLabelText('Modifier le message')).toBeNull();
    expect(screen.queryByLabelText('Envoyé')).toBeNull();
  });

  it('offre le menu sur son propre message vivant et acquitte', () => {
    renderList(threadOf(message({})));

    expect(screen.getByLabelText('Supprimer le message')).toBeDefined();
    expect(screen.getByLabelText('Modifier le message')).toBeDefined();
  });

  /**
   * Le temps relatif est offert au survol de l'heure. Le test verrouille le
   * cablage, pas le libelle (`dates.test.ts` s'en charge) : une fonction
   * correcte que personne n'appelle a l'air livree sans l'etre.
   *
   * L'assertion compare a ce que `formatRelative` produit pour cet instant, et
   * non a « different de la chaine vide » : `getAttribute` rend `null` quand
   * l'attribut est ABSENT, et `null !== ''` — retirer le `title` du composant
   * laissait donc le test vert.
   */
  it('offre le temps relatif au survol de l heure', () => {
    const createdAt = '2026-07-26T12:00:00+00:00';
    const { container } = renderList(threadOf(message({ createdAt })));

    const time = container.querySelector('time');

    expect(time?.getAttribute('dateTime')).toBe(createdAt);

    const title = time?.getAttribute('title');
    expect(typeof title).toBe('string');
    expect(title).not.toBe('');
    expect(title).toBe(formatRelative(createdAt, new Date(), navigator.language));
  });

  /**
   * `deleteForEveryone()` conserve volontairement `edited_at` : un message edite
   * puis supprime a donc les deux marques. La mention ne doit apparaitre que sur
   * un message VIVANT — sinon l'interface affiche « Alice · 14:32 · modifie »
   * au-dessus de « Ce message a ete supprime ».
   */
  it("n'affiche pas la mention « modifie » au-dessus d'un tombstone", () => {
    renderList(
      threadOf(
        message({
          content: null,
          editedAt: '2026-07-26T12:05:00+00:00',
          deletedAt: '2026-07-26T12:10:00+00:00',
        }),
      ),
    );

    expect(screen.getByText(deletedMessageLabel)).toBeDefined();
    expect(screen.queryByText(new RegExp(editedMessageLabel))).toBeNull();
  });

  /** Le pendant : sur un message vivant, la mention est bien la. */
  it('affiche la mention « modifie » sur un message vivant', () => {
    renderList(threadOf(message({ editedAt: '2026-07-26T12:05:00+00:00' })));

    expect(screen.getByText(new RegExp(editedMessageLabel))).toBeDefined();
  });

  /**
   * `canStillEdit` n'etait evalue qu'au rendu, et le menu est revele par
   * `group-hover` — du CSS, pas du React. Survoler ne re-rend pas : l'entree
   * « Modifier » restait donc offerte bien au-dela de la 15e minute, et le clic
   * partait en 403. Une horloge de composant fait avancer l'echeance.
   */
  it("retire « Modifier » quand la fenetre se ferme, sans nouvelle donnee", () => {
    vi.useFakeTimers();

    try {
      // Une seconde avant la fermeture : l'action est encore offerte au rendu.
      const createdAt = new Date(Date.now() - (EDIT_WINDOW_MS - 1_000)).toISOString();
      renderList(threadOf(message({ createdAt })));

      expect(screen.getByLabelText('Modifier le message')).toBeDefined();

      // Rien n'arrive du serveur : seul le temps passe.
      act(() => {
        vi.advanceTimersByTime(2 * EDIT_CLOCK_TICK_MS);
      });

      expect(screen.queryByLabelText('Modifier le message')).toBeNull();
      // La suppression, elle, n'a pas de fenetre : elle reste offerte.
      expect(screen.getByLabelText('Supprimer le message')).toBeDefined();
    } finally {
      vi.useRealTimers();
    }
  });

  /** Le menu ne s'affiche que sur SES messages : rien a editer chez les autres. */
  it("n'offre pas le menu sur le message d'un autre", () => {
    renderList(threadOf(message({ senderId: 'user-bob' })));

    expect(screen.queryByLabelText('Supprimer le message')).toBeNull();
    expect(screen.queryByLabelText('Modifier le message')).toBeNull();
  });
});
