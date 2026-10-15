<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, type Transcript } from '@/api/higher-deliberation';
import { locale } from '@/i18n';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import { dt, num } from './i18n';

// « Mes résultats » de l'étudiant (R12, self.read) : résultats publiés par semestre, UE par UE.
const higher = useHigherStore();
const list = useHigherAction();
const items = ref<Transcript[]>([]);

async function load(): Promise<void> {
  const res = await list.run(() => deliberation.myResults(locale.value));
  if (res) items.value = res;
}

watch(locale, load);
onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <DelFrame :title="dt('hdl.mine.title')" :lede="dt('hdl.mine.lede')" :nav="false" :selector="false">
    <ErrorNotice :error="list.error.value" />
    <p v-if="items.length === 0 && !list.busy.value" class="muted">{{ dt('hdl.mine.none') }}</p>
    <section v-for="r in items" :key="`${r.period_id}-${r.jury_id}`" class="card hi-section" :data-test="`mine-${r.period_id}`">
      <h2>{{ r.period_label || r.period_id }} · {{ r.session_label }}</h2>
      <div class="hdl-counts">
        <div class="hdl-count"><span class="muted">{{ dt('hdl.prep.average') }}</span><strong>{{ num(r.synthesis.average) }}</strong></div>
        <div class="hdl-count"><span class="muted">{{ dt('hdl.prep.gpa') }}</span><strong>{{ num(r.synthesis.gpa) }}</strong></div>
        <div class="hdl-count"><span class="muted">{{ dt('hdl.prep.credits') }}</span><strong>{{ r.synthesis.credits_earned }} / {{ r.synthesis.credits_total }}</strong></div>
        <div class="hdl-count"><span class="muted">{{ dt('hdl.mine.decision') }}</span><strong>{{ r.synthesis.decision ?? '—' }}</strong><span v-if="r.synthesis.mention" class="hdl-sub">{{ r.synthesis.mention }}</span></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ dt('hdl.mine.unit') }}</th><th scope="col" class="num">{{ dt('hdl.prep.credits') }}</th><th scope="col" class="num">{{ dt('hdl.prep.average') }}</th><th scope="col">{{ dt('hdl.mine.result') }}</th></tr></thead>
          <tbody>
            <tr v-for="u in r.units" :key="u.code"><th scope="row">{{ u.code }}<span class="hdl-sub">{{ u.name }}</span></th><td class="num">{{ u.credits }}</td><td class="num">{{ num(u.average) }}<span v-if="u.letter" class="hdl-sub">{{ u.letter }}</span></td><td>{{ u.result_label }}<span class="hdl-sub">{{ u.session_label }}</span></td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </DelFrame>
</template>
