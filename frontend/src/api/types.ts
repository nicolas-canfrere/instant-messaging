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
};

export type MessagePageResponse = { items: ApiMessage[]; next_before: string | null };

export type RealtimeToken = { hub_url: string; topics: string[] };

export type HeartbeatResponse = { online_user_ids: string[] };
