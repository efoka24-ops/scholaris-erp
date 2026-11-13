// Annuaire des élèves du secondaire (FIX-SEC) : un élève se choisit par son nom et sa classe, jamais par son identifiant interne.
// Une lecture par écran ; un refus (droit absent) laisse la liste vide et l'écran reste utilisable.
import { ref } from 'vue';
import { secondaryEnrolment, type DirectoryStudent } from '@/api/secondaryEnrolment';

/** Texte affiché d'un élève : « NOM Prénom · Classe ». */
export function studentText(s: DirectoryStudent): string {
  return s.class_label ? `${s.full_name} · ${s.class_label}` : s.full_name;
}

const fold = (value: string): string => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Élèves dont le nom ou la classe contient chacun des mots cherchés (sans tenir compte des accents ni de la casse). */
export function filterStudents(list: readonly DirectoryStudent[], query: string, classId = ''): DirectoryStudent[] {
  const words = fold(query).split(/\s+/).filter(Boolean);
  return list.filter((s) => {
    if (classId !== '' && s.class_structure_id !== classId) return false;
    const text = fold(`${s.full_name} ${s.class_label ?? ''}`);
    return words.every((w) => text.includes(w));
  });
}

/** Classes présentes dans l'annuaire (structure de classe et libellé), triées par libellé. */
export function classesOf(list: readonly DirectoryStudent[]): { structure_id: string; label: string }[] {
  const seen = new Map<string, string>();
  for (const s of list) if (!seen.has(s.class_structure_id)) seen.set(s.class_structure_id, s.class_label ?? s.class_structure_id);
  return [...seen].map(([structure_id, label]) => ({ structure_id, label })).sort((a, b) => a.label.localeCompare(b.label, 'fr'));
}

export function useStudentDirectory() {
  const students = ref<DirectoryStudent[]>([]);
  const loaded = ref(false);
  async function load(): Promise<void> {
    students.value = await secondaryEnrolment.directory().catch(() => []);
    loaded.value = true;
  }
  /** Nom et classe d'un élève de l'annuaire ; à défaut le repli donné (jamais l'identifiant interne). */
  const nameOf = (personId: string, fallback = '—'): string => {
    const found = students.value.find((s) => s.person_id === personId);
    return found ? studentText(found) : fallback;
  };
  return { students, loaded, load, nameOf };
}
