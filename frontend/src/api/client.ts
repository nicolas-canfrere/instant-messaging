import { toProblemError } from './problem';
import type {
  ApiMessage,
  ConversationDetail,
  ConversationSummary,
  HeartbeatResponse,
  Me,
  MessagePageResponse,
  RealtimeToken,
  UploadTicket,
  UserSummary,
} from './types';

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...init,
    // Indispensable : la session ET le cookie Mercure voyagent en cookies.
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...init.headers },
  });

  if (!response.ok) {
    throw await toProblemError(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export const api = {
  login: (username: string, password: string) =>
    request<{ status: string }>('/api/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }),

  logout: () => request<void>('/api/logout', { method: 'POST' }),

  me: () => request<Me>('/api/me'),

  users: () => request<UserSummary[]>('/api/users'),

  conversations: () => request<ConversationSummary[]>('/api/conversations'),

  conversation: (conversationId: string) =>
    request<ConversationDetail>(`/api/conversations/${conversationId}`),

  messages: (conversationId: string, before?: string) => {
    const query = new URLSearchParams({ limit: '50' });
    if (before) query.set('before', before);

    return request<MessagePageResponse>(`/api/conversations/${conversationId}/messages?${query}`);
  },

  /**
   * `content` peut etre `null` : un message n'est plus oblige d'avoir du texte,
   * il peut n'etre QUE des images. Envoyer une chaine vide a la place ferait un
   * 422 — le serveur rogne le contenu et refuse ce qui devient vide.
   */
  sendMessage: (
    conversationId: string,
    clientMessageId: string,
    content: string | null,
    mediaIds: string[] = [],
  ) =>
    request<{ id: string }>(`/api/conversations/${conversationId}/messages`, {
      method: 'POST',
      body: JSON.stringify({
        client_message_id: clientMessageId,
        content,
        media_ids: mediaIds,
      }),
    }),

  /**
   * Reserve un emplacement et rend l'URL signee vers laquelle televerser. Le
   * serveur ne voit jamais les octets : il ne fait que signer.
   *
   * `size` est ANNONCE, pas mesure : il permet de refuser un fichier hors
   * plafond avant tout transfert. Le vrai controle de taille a lieu apres, cote
   * worker — une URL pre-signee PUT ne sait pas plafonner ce qu'elle recoit.
   */
  presignUpload: (filename: string, contentType: string, size: number) =>
    request<UploadTicket>('/api/media', {
      method: 'POST',
      body: JSON.stringify({ filename, content_type: contentType, size }),
    }),

  /**
   * Dit au serveur que les octets sont arrives, ce qu'il ne peut pas savoir
   * autrement : le PUT est alle directement au stockage, sans passer par lui.
   * C'est cet appel qui met le worker en marche.
   */
  confirmUpload: (mediaId: string) =>
    request<void>(`/api/media/${mediaId}/uploaded`, { method: 'POST' }),

  createDirect: (peerId: string) =>
    request<{ id: string }>('/api/conversations', {
      method: 'POST',
      body: JSON.stringify({ type: 'direct', member_ids: [peerId] }),
    }),

  createGroup: (title: string, memberIds: string[]) =>
    request<{ id: string }>('/api/conversations', {
      method: 'POST',
      body: JSON.stringify({ type: 'group', title, member_ids: memberIds }),
    }),

  addMembers: (conversationId: string, userIds: string[]) =>
    request<void>(`/api/conversations/${conversationId}/members`, {
      method: 'POST',
      body: JSON.stringify({ user_ids: userIds }),
    }),

  // `me` et non son propre ULID : partir est un geste sur soi, le serveur sait
  // deja qui appelle. Le front n'a donc pas a construire l'URL avec son id.
  leaveConversation: (conversationId: string) =>
    request<void>(`/api/conversations/${conversationId}/members/me`, {
      method: 'DELETE',
    }),

  realtimeToken: () => request<RealtimeToken>('/api/realtime/token'),

  heartbeat: () => request<HeartbeatResponse>('/api/presence/heartbeat', { method: 'POST' }),

  typing: (conversationId: string) =>
    request<void>(`/api/conversations/${conversationId}/typing`, { method: 'POST' }),

  receipts: (conversationId: string, watermarks: { deliveredUpTo?: string; readUpTo?: string }) =>
    request<void>(`/api/conversations/${conversationId}/receipts`, {
      method: 'POST',
      body: JSON.stringify({
        delivered_up_to: watermarks.deliveredUpTo,
        read_up_to: watermarks.readUpTo,
      }),
    }),

  deleteMessage: (conversationId: string, messageId: string) =>
    request<void>(`/api/conversations/${conversationId}/messages/${messageId}`, {
      method: 'DELETE',
    }),

  editMessage: (conversationId: string, messageId: string, content: string) =>
    request<ApiMessage>(`/api/conversations/${conversationId}/messages/${messageId}`, {
      method: 'PATCH',
      body: JSON.stringify({ content }),
    }),
};

export type { ApiMessage };
