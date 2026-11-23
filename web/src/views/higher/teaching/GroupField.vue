<script setup lang="ts">
import { onMounted } from 'vue';
import { useHigherStore } from '../store';
import { useHt } from './i18n';

// Choix d'un groupe parmi le référentiel IPES (libellé niveau · groupe).
defineProps<{ id: string; modelValue: string; invalid?: boolean; label?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();
const { t } = useHt();
const higher = useHigherStore();
onMounted(() => void higher.loadRefs('groups', 'levels'));
</script>

<template>
  <div class="field">
    <label :for="id">{{ label ?? t('hi.group') }}</label>
    <select :id="id" :value="modelValue" :aria-invalid="invalid && !modelValue" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
      <option value="" disabled>{{ t('ht.groupPick') }}</option>
      <option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ higher.levelName(g.level_id) }} · {{ g.name }}</option>
    </select>
    <span v-if="invalid && !modelValue" class="error">{{ t('hi.required') }}</span>
  </div>
</template>
