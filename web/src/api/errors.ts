import { ApiError } from './http';
import { translate, type Locale, locale } from '@/i18n';

export interface DescribedError {
  /** Phrase affichable : dit ce qui s'est passé. */
  message: string;
  /** Ce que la personne doit faire ensuite. */
  hint: string | null;
  /** Identifiant de trace à communiquer au support (RFC 9457 : trace_id). */
  traceId: string | null;
  fields: Record<string, string[]>;
}

/** Transforme une erreur d'appel en message soutenu qui dit quoi faire. Le texte du serveur prime lorsqu'il existe. */
export function describeError(error: unknown, lang: Locale = locale.value): DescribedError {
  const t = (k: Parameters<typeof translate>[0], p?: Record<string, string | number>): string => translate(k, p, lang);
  if (!(error instanceof ApiError)) {
    return { message: t('error.unknown'), hint: t('error.hint.retry'), traceId: null, fields: {} };
  }
  const detail = error.problem?.detail?.trim() || null;
  const fields = error.fieldErrors;
  const traceId = error.traceId;

  if (error.network) {
    return { message: t('error.network'), hint: t('error.hint.network'), traceId: null, fields };
  }
  switch (error.status) {
    case 401:
      return { message: detail ?? t('error.unauthorized'), hint: error.problem?.attempts_left === undefined ? t('error.hint.signin') : null, traceId, fields };
    case 403:
      if (error.isTwoFactorRequired) {
        return { message: detail ?? t('error.twoFactor'), hint: t('error.hint.twoFactor'), traceId, fields };
      }
      return { message: detail ?? t('error.forbidden'), hint: t('error.hint.forbidden'), traceId, fields };
    case 404:
      return { message: detail ?? t('error.notFound'), hint: t('error.hint.notFound'), traceId, fields };
    case 409:
      return { message: detail ?? t('error.conflict'), hint: t('error.hint.conflict'), traceId, fields };
    case 422: {
      const first = Object.values(fields).flat()[0];
      return { message: detail ?? first ?? t('error.validation'), hint: t('error.hint.validation'), traceId, fields };
    }
    case 423:
      return { message: detail ?? t('error.locked'), hint: t('error.hint.locked'), traceId, fields };
    case 429:
      return {
        message: t('error.rateLimited', { seconds: error.retryAfter ?? 60 }),
        hint: t('error.hint.rateLimited'),
        traceId,
        fields,
      };
    default:
      if (error.status >= 500) {
        return { message: detail ?? t('error.server'), hint: t('error.hint.server'), traceId, fields };
      }
      return { message: detail ?? t('error.unknown'), hint: t('error.hint.retry'), traceId, fields };
  }
}
