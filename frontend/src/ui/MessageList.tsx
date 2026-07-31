import { Fragment, useEffect, useLayoutEffect, useRef, useState, type UIEvent } from 'react';
import type { UserSummary } from '../api/types';
import type { Thread } from '../store/messagesReducer';
import {
  selectReadCount,
  selectStatusFor,
  type ReceiptsState,
} from '../store/receiptsReducer';
import {
  dayKey,
  formatDaySeparator,
  formatRelative,
  viewerLocale,
  viewerTimeZone,
} from './dates';
import { canStillEdit, deletedMessageLabel, editedMessageLabel, formatTime, userName } from './labels';
import { MessageActions } from './MessageActions';
import { MessageEditor } from './MessageEditor';
import { MessageMedia } from './MessageMedia';
import { ReceiptTicks } from './ReceiptTicks';
import { useScrollAnchor } from './useScrollAnchor';

/** Distance au haut du conteneur en dessous de laquelle on charge la page precedente. */
const LOAD_OLDER_THRESHOLD_PX = 100;

/** Marge de tolerance pour considerer que l'utilisateur regarde bien le bas du fil. */
const AT_BOTTOM_TOLERANCE_PX = 40;

/**
 * Periode du reveil de l'horloge du composant.
 *
 * Trente secondes, et c'est deliberement grossier : cette horloge n'a que deux
 * consommateurs, la fenetre d'edition (quinze minutes) et les separateurs de
 * jour. Trente secondes d'imprecision n'y sont pas perceptibles, alors qu'un tic
 * d'une seconde re-rendrait toute la liste trente fois plus souvent pour rien.
 */
export const EDIT_CLOCK_TICK_MS = 30_000;

type Props = {
  thread: Thread;
  users: Record<string, UserSummary>;
  meId: string;
  conversationId: string;
  receiptsState: ReceiptsState;
  /** En groupe seulement, on affiche « lu par N » : en direct, la coche bleue suffit. */
  isGroup: boolean;
  onLoadOlder: () => void;
  onDeleteMessage: (messageId: string) => void;
  onEditMessage: (messageId: string, content: string) => void;
  /**
   * Appele quand une URL signee s'avere perimee : l'appelant recharge la page
   * de messages, ce qui en obtient de fraiches. Le composant, lui, ne sait pas
   * comment on resigne — il constate seulement que ca a echoue.
   */
  onMediaExpired: () => void;
};

/**
 * Ce composant est remonte a chaque changement de conversation : c'est
 * `ConversationView` qui porte `key={conversation.id}` sur `ConversationBody`,
 * le composant qui rend ce `MessageList` — pas un `key` pose ici. Changer de
 * conversation remonte donc tout `ConversationBody`, ce composant compris.
 * C'est volontaire, et c'est ce qui remet a zero la hauteur memorisee par
 * `useScrollAnchor` — sinon la hauteur d'un fil servirait d'ancre a un autre
 * et le scroll sauterait au changement.
 */
export function MessageList({
  thread,
  users,
  meId,
  conversationId,
  receiptsState,
  isGroup,
  onLoadOlder,
  onDeleteMessage,
  onEditMessage,
  onMediaExpired,
}: Props) {
  const container = useRef<HTMLDivElement | null>(null);
  /** L'utilisateur suivait-il le bas du fil juste avant ce rendu ? En ref : ne doit pas re-rendre. */
  const atBottom = useRef(true);
  /** Le message actuellement en cours d'edition, ou aucun. Purement local a l'affichage. */
  const [editingId, setEditingId] = useState<string | null>(null);

  // Resolus une fois par rendu de liste plutot qu'a chaque message : ces appels
  // Intl ne sont pas gratuits, et la valeur ne change pas en cours de session.
  const timeZone = viewerTimeZone();
  const locale = viewerLocale();

  /**
   * UNE SEULE horloge pour tout le composant, et elle avance.
   *
   * Deux choses en dependent : les separateurs de jour (« Aujourd'hui » devient
   * « Hier » a minuit) et la fenetre d'edition de quinze minutes. Aucune des
   * deux ne peut compter sur un nouveau rendu au bon moment — le menu d'action
   * est revele par `group-hover`, donc par du CSS et non par React : survoler ne
   * re-rend rien, et l'entree « Modifier » restait offerte bien apres la
   * fermeture de la fenetre, le clic partant alors en 403.
   *
   * D'ou ce tic, sur le meme motif que `TypingIndicator`. Il ne fait varier ni
   * la longueur de la liste ni l'identite de son premier element : ni l'effet de
   * suivi du bas du fil ni `useScrollAnchor` ne se redeclenchent, la position de
   * lecture ne bouge donc pas.
   */
  const [nowMs, setNowMs] = useState(() => Date.now());
  const now = new Date(nowMs);

  useEffect(() => {
    const timer = setInterval(() => setNowMs(Date.now()), EDIT_CLOCK_TICK_MS);

    return () => clearInterval(timer);
  }, []);

  // Corrige le saut de scroll provoque par l'insertion d'une page plus ancienne
  // EN TETE de la liste. Doit etre appele avant l'effet de suivi ci-dessous :
  // les effets de layout s'executent dans l'ordre de declaration, et le suivi
  // du bas doit pouvoir ecraser la correction quand les deux s'appliquent.
  //
  // On lui passe l'identifiant du premier message : c'est lui qui distingue un
  // prepend (page ancienne) d'un simple ajout en bas (message recu en SSE), que
  // le hook ne doit surtout PAS corriger. La longueur ne sert qu'a le faire
  // tourner a chaque changement de liste (voir le commentaire du hook).
  useScrollAnchor(container, thread.items[0]?.clientMessageId ?? null, thread.items.length);

  useLayoutEffect(() => {
    const element = container.current;
    if (!element) return;

    // Au premier rendu (`atBottom` vaut `true`) on se place en bas : un fil de
    // discussion se lit par la fin. Ensuite, on ne suit les nouveaux messages
    // que si l'utilisateur etait deja en bas — sinon on le tirerait hors de la
    // portion d'historique qu'il est en train de lire.
    if (atBottom.current) {
      element.scrollTop = element.scrollHeight;
    }
  }, [thread.items.length]);

  function handleScroll(event: UIEvent<HTMLDivElement>) {
    const element = event.currentTarget;

    atBottom.current =
      element.scrollHeight - element.scrollTop - element.clientHeight < AT_BOTTOM_TOLERANCE_PX;

    // `nextBefore === null` veut dire « debut de l'historique atteint » : on
    // arrete de demander. La garde anti-doublon vit dans `useAppState`, ce
    // handler peut donc se permettre d'etre appele a chaque pixel de scroll.
    if (element.scrollTop < LOAD_OLDER_THRESHOLD_PX && thread.nextBefore !== null) {
      onLoadOlder();
    }
  }

  return (
    <div ref={container} onScroll={handleScroll} className="flex-1 overflow-y-auto px-4 py-3">
      {thread.nextBefore === null && thread.loaded && thread.items.length > 0 && (
        <p className="pb-3 text-center text-xs text-slate-400">Debut de la conversation</p>
      )}

      {thread.loaded && thread.items.length === 0 && (
        <p className="text-center text-sm text-slate-400">Aucun message pour l'instant.</p>
      )}

      <ul className="flex flex-col gap-2">
        {thread.items.map((message, index) => {
          // Un separateur de jour s'insere quand le message precedent n'est
          // pas dans le meme jour QUE LE LECTEUR PERCOIT : deux messages
          // separes de quelques secondes peuvent tomber de part et d'autre de
          // minuit local, meme si le serveur les a recus dans le meme jour UTC.
          // Extrait UNE fois, en tete : c'est l'identifiant SERVEUR, `null` tant
          // que l'envoi optimiste n'est pas acquitte. Le nommer ici permet a
          // TypeScript de retenir le retrecissement `!== null` sur toutes les
          // conditions ci-dessous — sans quoi il faudrait des `as string`, et
          // c'est precisement ce silence qui avait masque le bug ou
          // `editingId === message.id` valait vrai pour `null === null`, donc
          // pour TOUS les messages optimistes.
          const messageId = message.id;

          const previous = thread.items[index - 1];
          const separator =
            previous === undefined ||
            dayKey(previous.createdAt, timeZone) !== dayKey(message.createdAt, timeZone)
              ? formatDaySeparator(message.createdAt, timeZone, locale, now)
              : null;

          return (
            // La cle reste l'identifiant client : c'est le seul qui existe des
            // le rendu optimiste, avant que le serveur n'attribue un ULID.
            // Utiliser `id` ferait remonter le composant a l'acquittement. Le
            // `Fragment` permet de rendre le separateur et le message comme
            // deux freres sans conteneur supplementaire dans la liste.
            <Fragment key={message.clientMessageId}>
              {separator !== null && separator !== '' && (
                <li className="sticky top-0 self-center rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">
                  {separator}
                </li>
              )}

              <li
                className={`relative group max-w-[75%] rounded px-3 py-2 ${
                  message.senderId === meId
                    ? 'self-end bg-slate-900 text-white'
                    : 'bg-white text-slate-900'
                } ${message.status === 'failed' ? 'opacity-70 ring-1 ring-red-400' : ''}`}
              >
                <p className="text-xs opacity-60">
                  {userName(users, message.senderId)} ·{' '}
                  {/*
                    L'heure exacte reste lisible ; le temps relatif (« il y a 5
                    minutes ») est offert au survol, ou il ne coute aucune place
                    a l'ecran. `dateTime` porte l'instant absolu ISO, donc non
                    ambigu pour tout ce qui lit la page autrement qu'avec des
                    yeux. `now` et `locale` sont ceux resolus une seule fois en
                    tete de liste : en resoudre d'autres ici referait un appel
                    Intl par message.
                  */}
                  <time
                    dateTime={message.createdAt}
                    title={formatRelative(message.createdAt, now, locale)}
                  >
                    {formatTime(message.createdAt, timeZone, locale)}
                  </time>
                  {/*
                    Uniquement sur un message VIVANT : `deleteForEveryone()`
                    conserve volontairement `edited_at`, un message edite puis
                    supprime porte donc les deux marques. Sans cette garde,
                    l'interface affiche « Alice · 14:32 · modifie » juste
                    au-dessus de « Ce message a ete supprime ».
                  */}
                  {message.deletedAt === null &&
                    message.editedAt !== null &&
                    ` · ${editedMessageLabel}`}
                </p>
                {message.deletedAt !== null ? (
                  <p className="italic opacity-60">{deletedMessageLabel}</p>
                ) : messageId !== null && editingId === messageId ? (
                  <MessageEditor
                    initialContent={message.content ?? ''}
                    onSubmit={(content) => {
                      setEditingId(null);
                      onEditMessage(messageId, content);
                    }}
                    onCancel={() => setEditingId(null)}
                  />
                ) : (
                  <p className="whitespace-pre-wrap break-words">{message.content}</p>
                )}

                {/*
                  Apres le texte, jamais avant : un message peut porter les deux,
                  et la legende se lit au-dessus de ce qu'elle legende.

                  Rien n'est rendu pour un tombstone — supprimer pour tous
                  detache les medias cote serveur, `media` est donc vide.
                */}
                {message.deletedAt === null &&
                  message.media.map((media) => (
                    <MessageMedia key={media.id} media={media} onExpired={onMediaExpired} />
                  ))}

                {/*
                  Seulement sur SES propres messages vivants et acquittes : un
                  message optimiste n'a pas encore d'`id` serveur a envoyer.
                */}
                {message.senderId === meId && messageId !== null && message.deletedAt === null && (
                  <MessageActions
                    onEdit={
                      // `nowMs`, et non `Date.now()` : c'est l'horloge du
                      // composant, celle qui avance et provoque le re-rendu.
                      canStillEdit(message.createdAt, nowMs)
                        ? () => setEditingId(messageId)
                        : null
                    }
                    onDelete={() => {
                      if (window.confirm('Supprimer ce message pour tout le monde ?')) {
                        onDeleteMessage(messageId);
                      }
                    }}
                  />
                )}

                {/*
                  Uniquement sur SES propres messages acquittes : un message encore
                  optimiste n'a pas d'`id` serveur, donc aucun watermark ne peut le
                  designer, et le statut d'un message recu n'a pas de sens ici.
                */}
                {message.senderId === meId && messageId !== null && message.deletedAt === null && (
                  <ReceiptTicks
                    status={selectStatusFor(receiptsState, conversationId, messageId, meId)}
                    readCount={
                      isGroup
                        ? selectReadCount(receiptsState, conversationId, messageId, meId)
                        : undefined
                    }
                  />
                )}

                {message.status === 'pending' && <p className="text-xs opacity-60">envoi…</p>}
                {message.status === 'failed' && (
                  <p className="text-xs text-red-500">échec — réessayer</p>
                )}
              </li>
            </Fragment>
          );
        })}
      </ul>
    </div>
  );
}
