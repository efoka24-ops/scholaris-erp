<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { CONDITIONS, PARTIES, internships, type MyInternship } from '@/api/higher-internships';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { ik, useInternshipsI18n } from './i18n';

// « Mon stage » (étudiant) : sa convention, ses signatures, son suivi, les conditions de soutenance, sa soutenance et son jury ;
// dépôt du mémoire une fois la convention signée. La note n'apparaît qu'avec le PV (le serveur la masque avant).
const { ts, locale } = useInternshipsI18n();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const data = ref<MyInternship | null>(null);
const none = ref(false);
const thesis = reactive({ title: '', file_ref: '' });
const sent = ref(false);
const canDeposit = computed(() => !higher.readOnly && !!data.value && ['signed', 'completed'].includes(data.value.internship.status) && !data.value.internship.thesis_deposited_at);

async function refresh(): Promise<void> {
  try {
    data.value = await internships.mine();
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) {
      none.value = true;
      return;
    }
    load.error.value = e;
  }
}

async function deposit(): Promise<void> {
  if (!thesis.title.trim() || !thesis.file_ref.trim()) return;
  if ((await act.run(() => internships.depositMyThesis(thesis.title.trim(), thesis.file_ref.trim()))) === undefined) return;
  sent.value = true;
  await refresh();
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <HigherFrame :title="ts('hs.m.title')" :lede="ts('hs.m.lede')" section="portal" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="none" class="card muted" data-test="no-internship">{{ ts('hs.m.none') }}</p>
    <template v-if="data">
      <section class="card" data-test="my-internship">
        <h2>{{ data.internship.subject }}</h2>
        <p><span class="tag" :class="`tag--${toneOf(data.internship.status) ?? 'neutral'}`">{{ ts(ik('hs.state.', data.internship.status)) }}</span></p>
        <p class="hi-small">{{ ts('hs.c.period', { from: formatDate(data.internship.starts_on, locale), to: formatDate(data.internship.ends_on, locale) }) }} · {{ ts('hs.c.supervisor') }} : {{ data.internship.supervisor_name }}</p>
        <ul class="hi-list">
          <li v-for="p in PARTIES" :key="p"><span class="hi-grow">{{ ts(ik('hs.party.', p)) }}</span><span v-if="data.internship.signatures[p]">{{ ts('hs.d.signedOn', { date: formatDate(data.internship.signatures[p], locale) }) }}</span><span v-else>—</span></li>
        </ul>
      </section>
      <section class="card">
        <h2>{{ ts('hs.d.readiness') }}</h2>
        <ul class="hi-list">
          <li v-for="c in CONDITIONS" :key="c" :data-test="`cond-${c}`"><span class="hi-grow">{{ ts(ik('hs.cond.', c)) }}</span><span class="tag" :class="data.readiness.conditions[c] ? 'tag--success' : 'tag--warning'">{{ data.readiness.conditions[c] ? '✓' : '—' }}</span></li>
        </ul>
      </section>
      <section v-if="canDeposit" class="card hi-form" data-test="thesis-form">
        <div class="field"><label for="mt-t">{{ ts('hs.d.thesisTitle') }}</label><input id="mt-t" v-model="thesis.title" maxlength="255"></div>
        <div class="field"><label for="mt-r">{{ ts('hs.d.thesisRef') }}</label><input id="mt-r" v-model="thesis.file_ref" maxlength="255"></div>
        <button type="button" class="btn btn--primary" data-test="deposit" @click="deposit">{{ ts('hs.d.deposit') }}</button>
      </section>
      <p v-if="sent" class="tag tag--success" data-test="deposit-ok">{{ ts('hs.m.sent') }}</p>
      <section class="card" data-test="my-defense">
        <h2>{{ ts('hs.m.defense') }}</h2>
        <template v-if="data.defense">
          <p><strong>{{ ts('hs.s.slot', { date: formatDate(data.defense.held_on, locale), start: data.defense.start_time, end: data.defense.end_time, room: data.defense.room }) }}</strong> · {{ ts(ik('hs.dstate.', data.defense.status)) }}</p>
          <p class="hi-small">{{ data.defense.jury.map((m) => `${m.display_name} (${ts(ik('hs.role.', m.jury_role))})`).join(', ') }}</p>
          <p v-if="data.defense.grade !== null" data-test="my-grade">{{ ts('hs.m.grade', { grade: data.defense.grade }) }}</p>
        </template>
        <p v-else class="muted">{{ ts('hs.m.noDefense') }}</p>
      </section>
    </template>
  </HigherFrame>
</template>
