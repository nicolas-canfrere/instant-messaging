export type Me = { id: string; username: string; display_name: string };

export type UserSummary = Me;

export type ConversationSummary = {
  id: string;
  type: 'direct' | 'group';
  title: string | null;
  last_message_at: string | null;
  last_message_preview: string | null;
  last_message_sender_id: string | null;
  unread_count: number;
};

export type ConversationMember = { user_id: string; role: string };

/**
 * Le detail est la SEULE reponse qui expose les membres : la liste des
 * conversations n'en donne pas. C'est par la qu'on retrouve l'interlocuteur
 * d'une conversation directe pour l'afficher par son nom.
 */
export type ConversationDetail = {
  id: string;
  type: 'direct' | 'group';
  title: string | null;
  members: ConversationMember[];
};

/**
 * Une piece jointe portee par un message, telle que le serveur la decrit —
 * image ou document.
 *
 * Tout est nullable sauf `id`, `status` et `filename`, et ce n'est pas de la
 * prudence : le serveur ne signe une URL que pour un media `ready`. Tant que
 * le worker n'a pas valide les octets, il n'y a NI dimensions NI URL — on ne
 * donne pas acces a des octets dont personne n'a encore verifie la nature.
 * `filename` echappe a cette regle : c'est le nom que l'utilisateur a choisi,
 * connu des la pre-signature, jamais mesure dans les octets.
 *
 * `ready` ne veut plus dire « miniature disponible » : cela veut dire
 * « valide ». Une image a une miniature ; un document n'en a jamais —
 * `thumbnail_url`, `width` et `height` y restent nuls, meme `ready`. C'est
 * `mime_type` (ou a defaut l'extension de `filename`) qui distingue les deux
 * cote affichage.
 *
 * Les quatre etats se lisent comme une progression :
 *  - `pending`    : pre-signe, le navigateur n'a encore rien televerse ;
 *  - `processing` : les octets sont arrives, le worker n'a pas tranche ;
 *  - `ready`      : valide ; miniature disponible pour une image seulement ;
 *  - `rejected`   : refuse (mauvais type, trop gros, illisible).
 */
export type ApiMedia = {
  id: string;
  status: 'pending' | 'processing' | 'ready' | 'rejected';
  mime_type: string | null;
  width: number | null;
  height: number | null;
  url: string | null;
  thumbnail_url: string | null;
  filename: string;
};

export type ApiMessage = {
  id: string;
  conversation_id: string;
  sender_id: string;
  /** `null` veut dire supprime pour tous : le serveur n'a plus la charge utile. */
  content: string | null;
  client_message_id: string;
  created_at: string;
  edited_at: string | null;
  deleted_at: string | null;
  /** Vide pour un message texte-seul. */
  media: ApiMedia[];
};

/** Ce que rend `POST /api/media` : de quoi televerser, rien de plus. */
export type UploadTicket = {
  media_id: string;
  upload_url: string;
  expires_at: string;
};

export type MessagePageResponse = { items: ApiMessage[]; next_before: string | null };

export type RealtimeToken = { hub_url: string; topics: string[] };

export type HeartbeatResponse = { online_user_ids: string[] };
