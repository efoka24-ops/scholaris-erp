// S-NOT05 : résumé de la publication des bulletins du secondaire aux familles (SMS « bulletin disponible », message essentiel par
// défaut). Fonction pure, testable : familles prévenues, déjà prévenues auparavant, et motifs traduits des envois non faits.
import type { ReportCardPublication } from '@/api/secondaryCouncils';
import type { MessageKey } from '@/i18n';

type Translate = (key: MessageKey, params?: Record<string, string | number>) => string;

export const KNOWN_REASONS = ['consent_missing', 'consent_revoked', 'no_contact', 'opted_out', 'sms_cap_reached', 'send_failed'];

export function publicationSummary(t: Translate, done: ReportCardPublication): string {
  const parts = [t('secc.publishedResult', { published: done.published, notified: done.notified, families: done.families })];
  if (done.already_notified > 0) parts.push(t('secc.alreadyNotified', { n: done.already_notified }));
  if (done.families === 0) parts.push(t('secc.noGuarantor'));
  const reasons = Object.entries(done.not_notified ?? {}).filter(([, n]) => n > 0);
  if (reasons.length > 0) {
    const details = reasons
      .map(([reason, n]) => (KNOWN_REASONS.includes(reason) ? t(`secc.reason.${reason}` as MessageKey, { n }) : t('secc.reason.other', { n, reason })))
      .join(' ; ');
    parts.push(t('secc.notNotified', { details }));
  }
  return parts.join(' ');
}
