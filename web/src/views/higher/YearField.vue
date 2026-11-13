<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from '@/i18n';
import { useHigherStore } from './store';

// Champ « Année académique » : liste des années du socle (/academic-years) ; saisie de l'identifiant si la liste est illisible.
const props = withDefaults(defineProps<{ id: string; modelValue: string; label?: string; invalid?: boolean; disabled?: boolean }>(), { label: '', invalid: false, disabled: false });
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
const { t } = useI18n();
const higher = useHigherStore();
onMounted(() => void higher.loadRefs('years'));
const update = (e: Event): void => emit('update:modelValue', (e.target as HTMLInputElement | HTMLSelectElement).value);
</script>

<template>
  <div class="field">
    <label :for="props.id">{{ label || t('hi.year') }}</label>
    <select v-if="higher.years.length" :id="props.id" :value="modelValue" :aria-invalid="invalid" :disabled="disabled" @change="update">
      <option value="" disabled>{{ t('hi.yearPick') }}</option>
      <option v-for="y in higher.years" :key="y.id" :value="y.id">{{ y.label }}</option>
    </select>
    <input v-else :id="props.id" :value="modelValue" type="text" :aria-invalid="invalid" :disabled="disabled" :placeholder="t('hi.idHint')" @input="update" />
    <span v-if="invalid" class="error">{{ t('hi.required') }}</span>
  </div>
</template>
