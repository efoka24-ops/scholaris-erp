<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, isUnsignedMinutes, JURY_DOCS, type DocLine, type IssuedJuryDoc, type Jury, type JuryDocCode } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { locale } from '@/i18n';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import UnsignedNotice from './UnsignedNotice.vue';
import { DP } from './access';
import { dk, dt } from './i18n';

// Documents du jury : grille (DOC-14), PV (DOC-15), liste des résultats (DOC-16). Lignes : décisions de la séance du socle
// si elle existe, sinon la simulation. Avant signature du PV par le président, DOC-16 est refusé (409 RG-DEL-3) : on l'explique.
const props = defineProps<{ id: string }>();
const ctx = useContextStore();
const higher = useHigherStore();
const prep = useHigherAction();
const act = useHigherAction();
const jury = ref<Jury | null>(null);
const lines = ref<DocLine[]>([]);
const issued = ref<IssuedJuryDoc | null>(null);
const unsigned = ref(false);
const canIssue = computed(() => ctx.can(...DP.prepareOrPreside) && !higher.readOnly);

async function loadLines(): Promise<void> {
  const j = jury.value;
  if (!j) return;
  if (j.deliberation_ref) {
    const s = await prep.run(() => deliberation.session(j.deliberation_ref as string));
    if (s?.lines?.length) {
      lines.value = s.lines.map((l) => ({ person_id: l.person_id, average: l.average, credits_earned: l.credits_earned, credits_total: l.credits_total, proposal: l.proposal ?? null, decision: l.decision, reason: l.reason }));
      return;
    }
  }
  if (!ctx.can(...DP.prepare)) return;
  const sim = await prep.run(() => deliberation.simulate(props.id));
  if (sim) lines.value = sim.students.map((s) => ({ person_id: s.person_id, average: s.average, credits_earned: s.credits_earned, credits_total: s.credits_total, proposal: s.proposal, decision: s.proposal, reason: null }));
}

async function issue(code: JuryDocCode): Promise<void> {
  issued.value = null;
  unsigned.value = false;
  const res = await act.run(() => deliberation.issueDocument(props.id, { code, lines: lines.value, locale: locale.value }));
  if (res) {
    issued.value = res;
  } else if (isUnsignedMinutes(act.error.value)) {
    unsigned.value = true;
    act.error.value = null;
  }
}

onMounted(async () => {
  await higher.ensure();
  jury.value = (await prep.run(() => deliberation.jury(props.id))) ?? null;
  await loadLines();
});
</script>

<template>
  <DelFrame :title="dt('hdl.doc.title')" :lede="dt('hdl.doc.lede')" :selector="false">
    <p><router-link :to="{ name: 'higher-del-jury', params: { id } }">← {{ dt('hdl.back') }}</router-link></p>
    <p v-if="jury"><span class="tag tag--neutral">{{ dk('hdl.state.', jury.status) }}</span> {{ dt('hdl.doc.lines', { count: lines.length }) }}</p>
    <ErrorNotice :error="prep.error.value" />
    <p v-if="!prep.busy.value && lines.length === 0" class="notice notice--warning">{{ dt('hdl.doc.needSimulation') }}</p>
    <UnsignedNotice v-if="unsigned" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="issued" class="notice notice--success" role="status">
      {{ dt('hdl.doc.issued', { number: String(issued.number ?? '') }) }}
      <template v-if="issued.verification_token"> · {{ dt('hdl.doc.verify', { token: String(issued.verification_token) }) }}</template>
    </p>
    <ul class="hi-section hdl-links" role="list">
      <li v-for="code in JURY_DOCS" :key="code" class="card">
        <p><strong>{{ dk('hdl.doc.', code) }}</strong></p>
        <button v-if="canIssue" type="button" class="btn btn--primary" :disabled="act.busy.value || lines.length === 0" :data-test="`issue-${code}`" @click="issue(code)">{{ dt('hdl.doc.issue') }}</button>
      </li>
    </ul>
  </DelFrame>
</template>
