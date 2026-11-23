<script setup lang="ts">
// Choix d'un élève par son nom (FIX-SEC) : recherche par nom ou classe, puis liste « NOM Prénom · Classe ». La valeur reste
// l'identifiant interne, jamais affiché. La liste vient de l'annuaire des élèves (lu par l'écran parent ou par le composant).
import { computed, ref } from 'vue';
import type { DirectoryStudent } from '@/api/secondaryEnrolment';
import { useI18n } from '@/i18n';
import { filterStudents, studentText } from './studentDirectory';

const props = withDefaults(defineProps<{ id: string; label: string; students: DirectoryStudent[]; modelValue: string; classId?: string; required?: boolean; loaded?: boolean }>(), {
  classId: '',
  required: false,
  loaded: true,
});
const emit = defineEmits<{ 'update:modelValue': [value: string]; pick: [student: DirectoryStudent | null] }>();
const { t } = useI18n();
const query = ref('');
const LIMIT = 200;

const shown = computed(() => {
  const found = filterStudents(props.students, query.value, props.classId);
  const head = found.slice(0, LIMIT);
  const current = props.students.find((s) => s.person_id === props.modelValue);
  return current && !head.includes(current) ? [current, ...head] : head;
});

function choose(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  emit('update:modelValue', value);
  emit('pick', props.students.find((s) => s.person_id === value) ?? null);
}
</script>

<template>
  <div class="field student-select">
    <label :for="id">{{ label }}</label>
    <input :id="`${id}-search`" v-model="query" type="search" :aria-label="t('sec.student.search')" :placeholder="t('sec.student.search')" autocomplete="off" />
    <select :id="id" :value="modelValue" :required="required" @change="choose">
      <option value="">{{ t('sec.student.choose') }}</option>
      <option v-for="s in shown" :key="s.person_id" :value="s.person_id">{{ studentText(s) }}</option>
    </select>
    <p v-if="loaded && students.length === 0" class="muted small">{{ t('sec.student.none') }}</p>
  </div>
</template>

<style scoped>
.student-select { display: grid; gap: 6px; }
</style>
