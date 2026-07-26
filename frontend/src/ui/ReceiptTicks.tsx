import type { ReceiptStatus } from '../store/receiptsReducer';

const LABELS: Record<ReceiptStatus, string> = {
  sent: 'Envoyé',
  delivered: 'Distribué',
  read: 'Lu',
};

/**
 * ✓ envoye · ✓✓ distribue · ✓✓ bleu lu. Ne s'affiche que sur SES PROPRES
 * messages : le statut d'un message recu n'a aucun sens pour son destinataire.
 */
export function ReceiptTicks({ status, readCount }: { status: ReceiptStatus; readCount?: number }) {
  const marks = status === 'sent' ? '✓' : '✓✓';
  const color = status === 'read' ? 'text-sky-500' : 'text-slate-400';

  return (
    <span className={`ml-1 text-xs ${color}`} title={LABELS[status]} aria-label={LABELS[status]}>
      {marks}
      {readCount !== undefined && readCount > 0 ? ` ${readCount}` : ''}
    </span>
  );
}
