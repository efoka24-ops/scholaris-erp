<script setup lang="ts">
// Finalité d'une lecture nominative, pour la seule supervision territoriale (inspection, délégation) : exigée par le serveur et
// inscrite au journal des consultations. Invisible dans l'école.
import { PURPOSES, isSupervision, supervisionPurpose, type Purpose } from '@/api/publicSchools';
import { useI18n } from '@/i18n';

const props = withDefaults(defineProps<{ id?: string }>(), { id: 'ps-purpose' });
const { t } = useI18n();
const label = (p: Purpose): string => t(p === 'transfer' ? 'ps.purpose.transfer' : p === 'exam' ? 'ps.purpose.exam' : 'ps.purpose.control');
</script>

<template>
  <div v-if="isSupervision()" class="field purpose">
    <label :for="props.id">{{ t('ps.purpose.label') }}</label>
    <select :id="props.id" v-model="supervisionPurpose">
      <option v-for="p in PURPOSES" :key="p" :value="p">{{ label(p) }}</option>
    </select>
    <span class="hint">{{ t('ps.purpose.hint') }}</span>
  </div>
</template>

<style scoped>
.purpose { max-width: 24rem; }
</style>
