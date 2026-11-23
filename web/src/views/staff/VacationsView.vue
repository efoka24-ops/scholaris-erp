<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useStaffDirectory } from './useStaffDirectory';
import VacationHours from './VacationHours.vue';
import VacationContracts from './VacationContracts.vue';
import VacationStatements from './VacationStatements.vue';
import VacationRates from './VacationRates.vue';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';

// Vacations : heures, contrats, relevés mensuels et taux — design : Dashboard.html, Frais.html (onglets, tableaux, actions en ligne).
const { t } = useI18n();
const ctx = useContextStore();
const dir = useStaffDirectory();

type Tab = 'hours' | 'contracts' | 'statements' | 'rates';
const tab = ref<Tab>('hours');
const tabs = computed<Tab[]>(() => ['hours', 'contracts', 'statements', ...(ctx.can('vacations.manage', 'staff.read') ? (['rates'] as Tab[]) : [])]);

onMounted(dir.load);
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('staff.eyebrow') }}</p>
      <h1>{{ t('vac.title') }}</h1>
      <p class="lede">{{ t('vac.lede') }}</p>
    </header>

    <ErrorNotice v-if="dir.error.value" :error="dir.error.value" />

    <div class="row tabs" role="tablist" :aria-label="t('vac.title')">
      <button v-for="k in tabs" :key="k" type="button" role="tab" class="btn" :class="{ 'btn--ink': tab === k }" :aria-selected="tab === k" :data-tab="k" @click="tab = k">
        {{ t(`vac.tab.${k}` as MessageKey) }}
      </button>
    </div>

    <VacationHours v-if="tab === 'hours'" :dir="dir" />
    <VacationContracts v-else-if="tab === 'contracts'" :dir="dir" />
    <VacationStatements v-else-if="tab === 'statements'" :dir="dir" />
    <VacationRates v-else :dir="dir" />
  </section>
</template>

<style scoped>
.tabs {
  margin-bottom: 16px;
}
</style>
