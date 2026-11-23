<script setup lang="ts">
// Choix d'une section parmi celles que la personne connectée peut voir (le serveur borne déjà la liste à son périmètre).
import { onMounted, ref } from 'vue';
import { nursery, type Section } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { useAction } from './useNur';

const props = defineProps<{ modelValue: string; id?: string; label?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string]; loaded: [sections: Section[]] }>();
const { t } = useI18n();
const { error, run } = useAction();
const sections = ref<Section[]>([]);

onMounted(async () => {
  sections.value = (await run(() => nursery.sections())) ?? [];
  emit('loaded', sections.value);
  const first = sections.value[0];
  if (!props.modelValue && first && sections.value.length === 1) emit('update:modelValue', first.id);
});
</script>

<template>
  <div class="field">
    <label :for="id ?? 'nur-section'">{{ label ?? t('nur.section.pick') }}</label>
    <select :id="id ?? 'nur-section'" :value="modelValue" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
      <option value="">{{ t('nur.section.choose') }}</option>
      <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
    </select>
    <ErrorNotice :error="error" />
  </div>
</template>
