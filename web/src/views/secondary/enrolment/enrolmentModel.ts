// Modèle du formulaire d'inscription du secondaire (sans Vue, testable) : formulaire plat -> corps d'API-01. La majorité est celle
// du socle (18 ans) ; un mineur exige un garant (le serveur le vérifie aussi).
import type { EnrolmentInput } from '@/api/secondaryEnrolment';

export interface EnrolForm {
  last_name: string;
  first_name: string;
  birth_date: string;
  gender: '' | 'male' | 'female';
  class_id: string;
  entry_date: string;
  guardian_last_name: string;
  guardian_first_name: string;
  guardian_phone: string;
  national_id: string;
  repeater: boolean;
  documents: string;
  age_decision_reason: string;
  exclusion_decision_reason: string;
}

export function emptyEnrolForm(today: string = new Date().toISOString().slice(0, 10)): EnrolForm {
  return {
    last_name: '', first_name: '', birth_date: '', gender: '', class_id: '', entry_date: today, guardian_last_name: '', guardian_first_name: '',
    guardian_phone: '', national_id: '', repeater: false, documents: '', age_decision_reason: '', exclusion_decision_reason: '',
  };
}

/** Âge révolu à une date (AAAA-MM-JJ) ; null si une date manque. */
export function ageAt(birthDate: string, on: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(birthDate) || !/^\d{4}-\d{2}-\d{2}$/.test(on)) return null;
  const [by, bm, bd] = birthDate.split('-').map(Number) as [number, number, number];
  const [y, m, d] = on.split('-').map(Number) as [number, number, number];
  return y - by - (m < bm || (m === bm && d < bd) ? 1 : 0);
}

export function isMinor(birthDate: string, on: string): boolean {
  const age = ageAt(birthDate, on);
  return age !== null && age < 18;
}

/** Corps d'API-01 : champs vides retirés, pièces séparées par des virgules. */
export function dossierFromForm(form: EnrolForm): EnrolmentInput {
  const trim = (v: string): string => v.trim();
  const input: EnrolmentInput = {
    identity: {
      last_name: trim(form.last_name),
      first_name: trim(form.first_name),
      ...(form.birth_date !== '' ? { birth_date: form.birth_date } : {}),
      ...(form.gender !== '' ? { gender: form.gender } : {}),
    },
    class_id: form.class_id,
    entry_date: form.entry_date,
    repeater: form.repeater,
  };
  if (trim(form.guardian_last_name) !== '') {
    input.guardians = [{
      kind: 'parent',
      identity: {
        last_name: trim(form.guardian_last_name),
        first_name: trim(form.guardian_first_name) === '' ? trim(form.guardian_last_name) : trim(form.guardian_first_name),
        ...(trim(form.guardian_phone) !== '' ? { phone: trim(form.guardian_phone) } : {}),
      },
    }];
  }
  if (trim(form.national_id) !== '') input.national_id = trim(form.national_id);
  const documents = form.documents.split(',').map(trim).filter((d) => d !== '');
  if (documents.length > 0) input.documents_checked = documents.slice(0, 20);
  if (trim(form.age_decision_reason) !== '') input.age_decision_reason = trim(form.age_decision_reason);
  if (trim(form.exclusion_decision_reason) !== '') input.exclusion_decision_reason = trim(form.exclusion_decision_reason);
  return input;
}
