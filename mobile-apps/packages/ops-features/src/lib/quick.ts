import type { QuickReply } from '../api/types';

/** A quick reply chip shows only when the server sent its text. Nothing is made up: no data, no chip. */
export const usableQuickReplies = (replies: QuickReply[] | null | undefined): QuickReply[] => (replies ?? []).filter((reply) => reply.label.trim() !== '' && (reply.text ?? '').trim() !== '');
