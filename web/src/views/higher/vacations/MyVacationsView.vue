<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { vacations, type Statement } from '@/api/higher-vacations';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import StatementSummary from './StatementSummary.vue';
import { useHv } from './i18n';

// « Mes vacations » (R09, R10) : l'enseignant ne voit que ses propres états (le serveur filtre sur la personne liée au compte).
const { t } = useHv();
const higher = useHigherStore();
const list = useHigherAction();
const rows = ref<Statement[]>([]);
const linkable = useRouter().hasRoute('higher-vacation-statement');

onMounted(async () => {
  await higher.ensure();
  const res = await list.run(() => vacations.mine());
  if (res) rows.value = res;
});
</script>

<template>
  <HigherFrame :title="t('hv.mine.title')" :lede="t('hv.mine.lede')" section="curriculum">
    <ErrorNotice :error="list.error.value" />
    <section v-for="s in rows" :key="s.id" class="hi-section" data-test="my-statement">
      <h2><router-link v-if="linkable" :to="{ name: 'higher-vacation-statement', params: { id: s.id } }">{{ t('hv.detail.title', { month: s.period_month }) }}</router-link><template v-else>{{ t('hv.detail.title', { month: s.period_month }) }}</template></h2>
      <StatementSummary :statement="s" />
    </section>
    <p v-if="!list.busy.value && rows.length === 0" class="muted">{{ t('hv.mine.none') }}</p>
  </HigherFrame>
</template>
