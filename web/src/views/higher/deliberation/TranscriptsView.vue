<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, isUnsignedMinutes, SEMESTERS, type IssuedJuryDoc } from '@/api/higher-deliberation';
import type { IssuedDoc } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { locale } from '@/i18n';
import PersonPicker from '../PersonPicker.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import UnsignedNotice from './UnsignedNotice.vue';
import { DP } from './access';
import { dt } from './i18n';

// Relevés semestriels (DOC-09) : émission après signature du PV (RG-DOC-3) et liste des relevés émis (registre des documents).
const ctx = useContextStore();
const higher = useHigherStore();
const act = useHigherAction();
const list = useHigherAction();
const form = reactive({ person: null as string | null, period_id: 'S1', locale: 'fr' as 'fr' | 'en' });
const tried = ref(false);
const issued = ref<IssuedJuryDoc | null>(null);
const unsigned = ref(false);
const items = ref<IssuedDoc[]>([]);
const canIssue = computed(() => ctx.can(...DP.prepare) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => deliberation.issuedTranscripts({ ...(higher.scope() as Record<string, string>) }));
  if (res) items.value = res;
}

async function issue(): Promise<void> {
  tried.value = true;
  if (!form.person) return;
  const person = form.person;
  issued.value = null;
  unsigned.value = false;
  const res = await act.run(() => deliberation.issueTranscript({ person_id: person, period_id: form.period_id, locale: form.locale }));
  if (res) {
    issued.value = res;
    await load();
  } else if (isUnsignedMinutes(act.error.value)) {
    unsigned.value = true;
    act.error.value = null;
  }
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <DelFrame :title="dt('hdl.tr.title')" :lede="dt('hdl.tr.lede')" @school-change="load">
    <form v-if="canIssue" class="card hi-form" novalidate @submit.prevent="issue">
      <div class="hi-wide"><PersonPicker id="tr-person" v-model="form.person" :label="dt('hdl.student')" /><span v-if="tried && !form.person" class="error">{{ dt('hdl.required') }}</span></div>
      <div class="field"><label for="tr-period">{{ dt('hdl.period') }}</label><select id="tr-period" v-model="form.period_id"><option v-for="s in SEMESTERS" :key="s" :value="s">{{ s }}</option></select></div>
      <div class="field"><label for="tr-locale">{{ dt('hdl.tr.locale') }}</label><select id="tr-locale" v-model="form.locale"><option value="fr">Français</option><option value="en">English</option></select></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ dt('hdl.tr.issue') }}</button></div>
    </form>
    <UnsignedNotice v-if="unsigned" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="issued" class="notice notice--success" role="status">{{ dt('hdl.tr.issued', { number: String(issued.number ?? '') }) }}</p>

    <section class="hi-section">
      <h2>{{ dt('hdl.tr.list') }}</h2>
      <ErrorNotice :error="list.error.value" />
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ dt('hdl.tr.number') }}</th><th scope="col">{{ dt('hdl.student') }}</th><th scope="col">{{ dt('hdl.tr.issuedAt') }}</th><th scope="col">{{ dt('hdl.status') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in items" :key="d.id"><th scope="row">{{ d.number }}</th><td>{{ d.person_id }}</td><td>{{ formatDate(d.issued_at, locale) }}</td><td><span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ d.status }}</span></td></tr>
            <tr v-if="items.length === 0"><td colspan="4" class="muted">{{ dt('hdl.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </DelFrame>
</template>
