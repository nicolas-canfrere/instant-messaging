/**
 * Accusés de réception : un curseur « distribué » et un curseur « lu » par
 * membre et par conversation.
 *
 * L'evenement `receipt.updated` porte TOUJOURS les deux curseurs, donc le
 * reducer REMPLACE l'etat du membre. Fusionner serait non seulement inutile,
 * mais faux : deux evenements arrives dans le desordre laisseraient un curseur
 * en avance sur la realite, et un curseur qui recule cote serveur ne pourrait
 * jamais se refleter ici.
 *
 * Les ULID se comparent lexicographiquement : `watermark >= messageId` signifie
 * « ce membre a atteint ce message ». C'est la propriete qui a justifie le choix
 * de l'ULID, et elle sert ici sans conversion.
 */
export type MemberReceipts = {
  lastDeliveredMessageId: string | null;
  lastReadMessageId: string | null;
};

export type ReceiptsState = {
  /** conversationId -> (userId -> curseurs) */
  byConversation: Record<string, Record<string, MemberReceipts>>;
};

export type ReceiptsAction = {
  type: 'receipt/updated';
  conversationId: string;
  userId: string;
  lastDeliveredMessageId: string | null;
  lastReadMessageId: string | null;
};

/** Statut affiche sur MES messages uniquement. */
export type ReceiptStatus = 'sent' | 'delivered' | 'read';

export function emptyReceiptsState(): ReceiptsState {
  return { byConversation: {} };
}

export function receiptsReducer(state: ReceiptsState, action: ReceiptsAction): ReceiptsState {
  switch (action.type) {
    case 'receipt/updated': {
      const current = state.byConversation[action.conversationId] ?? {};

      return {
        byConversation: {
          ...state.byConversation,
          [action.conversationId]: {
            ...current,
            [action.userId]: {
              lastDeliveredMessageId: action.lastDeliveredMessageId,
              lastReadMessageId: action.lastReadMessageId,
            },
          },
        },
      };
    }
  }
}

/** `null` n'a jamais atteint quoi que ce soit ; sinon comparaison lexicographique. */
function reached(watermark: string | null, messageId: string): boolean {
  return watermark !== null && watermark >= messageId;
}

/** Les curseurs de tous les membres SAUF moi : mon propre watermark ne prouve rien. */
function others(state: ReceiptsState, conversationId: string, meId: string): MemberReceipts[] {
  return Object.entries(state.byConversation[conversationId] ?? {})
    .filter(([userId]) => userId !== meId)
    .map(([, receipts]) => receipts);
}

export function selectStatusFor(
  state: ReceiptsState,
  conversationId: string,
  messageId: string,
  meId: string,
): ReceiptStatus {
  const peers = others(state, conversationId, meId);

  // « Lu » des qu'UN destinataire a lu, comme WhatsApp en direct. En groupe, le
  // decompte precis est rendu par selectReadCount.
  if (peers.some((r) => reached(r.lastReadMessageId, messageId))) return 'read';
  if (peers.some((r) => reached(r.lastDeliveredMessageId, messageId))) return 'delivered';

  return 'sent';
}

/** Combien de membres, moi excepte, ont lu jusqu'a ce message. */
export function selectReadCount(
  state: ReceiptsState,
  conversationId: string,
  messageId: string,
  meId: string,
): number {
  return others(state, conversationId, meId).filter((r) =>
    reached(r.lastReadMessageId, messageId),
  ).length;
}
