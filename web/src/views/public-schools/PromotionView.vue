<script setup lang="ts">
// Passage de classe et conseil des maîtres (PAS) : propositions selon la politique nationale (non modifiable par l'école, RG-PAS-1),
// décisions du conseil (tout redoublement est motivé ; un deuxième redoublement dans le même niveau attend l'avis de l'inspecteur,
// RG-PAS-2), recours des familles dans le délai paramétré, procès-verbal DOC-12. L'inspecteur ne voit que les avis qui l'attendent.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicPrimary, publicSchools, type ClassRow, type PromotionPolicyView, type PromotionRow } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const yearLabel = ref('');
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const rows = ref<PromotionRow[]>([]);
const policy = ref<PromotionPolicyView | null>(null);
const decisions = ref<Record<string, { decision: string; reason: string }>>({});
const heldOn = ref(todayIso());
const attendees = ref('');
const councilId = ref<string | null>(null);
const opinionComment = ref<Record<string, string>>({});
const done = ref('');

const canPropose = computed(() => ctx.can('public_primary.promotion.write', 'public_primary.promotion.validate'));
const canDecide = computed(() => ctx.can('public_primary.promotion.validate'));
const canOpine = computed(() => ctx.can('public_primary.promotion.opinion'));
const label = (code: string | null): string =>
  code === null
    ? '—'
    : t(code === 'promote' ? 'pp.pas.promote' : code === 'promote_with_remediation' ? 'pp.pas.promoteWithRemediation' : code === 'repeat' ? 'pp.pas.repeat' : 'pp.pas.councilReview');
const stateLabel = (s: PromotionRow['state']): string => t(s === 'proposed' ? 'pp.pas.stateProposed' : s === 'final' ? 'pp.pas.stateFinal' : 'pp.pas.statePending');

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (!year) return;
    yearLabel.value = year.label;
    classes.value = await publicSchools.classes(year.id);
    policy.value = await publicPrimary.policy();
  });
});

function decisionOf(id: string): { decision: string; reason: string } {
  decisions.value[id] ??= { decision: '', reason: '' };
  return decisions.value[id];
}

function reset(list: PromotionRow[]): void {
  rows.value = list;
  decisions.value = Object.fromEntries(list.map((r) => [r.id, { decision: r.decision ?? (r.proposal === 'council_review' ? '' : r.proposal), reason: r.reason ?? '' }]));
  councilId.value = list.find((r) => r.council_id)?.council_id ?? null;
}

async function load(): Promise<void> {
  if (!classId.value) return;
  await run(async () => reset(await publicPrimary.promotion(yearLabel.value, classId.value)));
}

async function propose(): Promise<void> {
  await run(async () => reset(await publicPrimary.propose(yearLabel.value, classId.value)));
}

async function council(): Promise<void> {
  const body = {
    structure_id: classId.value,
    held_on: heldOn.value,
    attendees: attendees.value.split(',').map((a) => a.trim()).filter((a) => a !== ''),
    decisions: rows.value
      .filter((r) => r.state === 'proposed' && decisions.value[r.id]?.decision)
      .map((r) => ({ person_id: r.person_id, decision: decisions.value[r.id]?.decision ?? '', ...(decisions.value[r.id]?.reason ? { reason: decisions.value[r.id]?.reason } : {}) })),
  };
  await run(async () => {
    const res = await publicPrimary.council(yearLabel.value, body);
    reset(res.decisions);
    councilId.value = res.council_id;
    done.value = t('pp.pas.councilDone');
  });
}

async function minutes(): Promise<void> {
  if (!councilId.value) return;
  await run(async () => {
    const res = await publicPrimary.minutes(councilId.value as string);
    await openDocument(res.document.id);
  });
}

async function opinion(row: PromotionRow, value: 'favourable' | 'unfavourable'): Promise<void> {
  await run(async () => {
    await publicPrimary.opinion(row.id, value, opinionComment.value[row.id] ?? '');
    done.value = t('pp.pas.opinionDone');
    await load();
  });
}

async function appeal(row: PromotionRow, action: 'lodge' | 'uphold' | 'revise'): Promise<void> {
  const reason = window.prompt(t('pp.pas.appealReason')) ?? '';
  if (reason.trim() === '') return;
  await run(async () => {
    await publicPrimary.appeal(row.id, { action, reason, ...(action === 'revise' ? { decision: 'promote_with_remediation' } : {}) });
    await load();
  });
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.pas.title') }}</h1>
      <p class="lede">{{ t('pp.pas.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <p v-if="policy" class="muted">{{ t('pp.pas.policy', { version: policy.version, remediation: policy.na_for_remediation, council: policy.na_for_council }) }}</p>

    <section class="card controls">
      <div class="field">
        <label for="pa-class">{{ t('pp.common.class') }}</label>
        <select id="pa-class" v-model="classId" @change="load">
          <option value="">—</option>
          <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <button v-if="canPropose" type="button" class="btn" :disabled="busy || !classId" @click="propose">{{ t('pp.pas.propose') }}</button>
    </section>

    <section v-if="rows.length > 0" class="card" aria-labelledby="pa-list">
      <h2 id="pa-list">{{ t('pp.pas.list') }}</h2>
      <ul class="rows">
        <li v-for="r in rows" :key="r.id" class="row">
          <p><strong>{{ r.name }}</strong> · {{ t('pp.pas.proposal') }} : {{ label(r.proposal) }} ({{ t('pp.pas.naCount', { n: r.key_na_count }) }}) · <span class="tag">{{ stateLabel(r.state) }}</span></p>
          <p v-if="r.decision">{{ t('pp.pas.decision') }} : {{ label(r.decision) }}<span v-if="r.reason" class="muted"> — {{ r.reason }}</span></p>
          <template v-if="canDecide && r.state === 'proposed'">
            <div class="field">
              <label :for="`dc-${r.id}`">{{ t('pp.pas.decision') }}</label>
              <select :id="`dc-${r.id}`" v-model="decisionOf(r.id).decision">
                <option value="">—</option>
                <option value="promote">{{ label('promote') }}</option>
                <option value="promote_with_remediation">{{ label('promote_with_remediation') }}</option>
                <option value="repeat">{{ label('repeat') }}</option>
              </select>
            </div>
            <div class="field">
              <label :for="`rs-${r.id}`">{{ t('pp.common.reason') }}</label>
              <input :id="`rs-${r.id}`" v-model="decisionOf(r.id).reason" type="text" maxlength="1000" :required="decisionOf(r.id).decision === 'repeat'" />
            </div>
          </template>
          <template v-if="canOpine && r.state === 'pending_opinion'">
            <div class="field">
              <label :for="`op-${r.id}`">{{ t('pp.pas.opinionComment') }}</label>
              <input :id="`op-${r.id}`" v-model="opinionComment[r.id]" type="text" maxlength="500" />
            </div>
            <button type="button" class="btn btn--primary" :disabled="busy" @click="opinion(r, 'favourable')">{{ t('pp.pas.favourable') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="opinion(r, 'unfavourable')">{{ t('pp.pas.unfavourable') }}</button>
          </template>
          <p v-if="r.appeal_until && r.state === 'final'" class="muted">{{ t('pp.pas.appealUntil', { date: formatDate(r.appeal_until) }) }} · {{ r.appeal_state ?? '' }}</p>
          <div v-if="canDecide && r.state === 'final'" class="actions">
            <button v-if="!r.appeal_state" type="button" class="btn btn--small" @click="appeal(r, 'lodge')">{{ t('pp.pas.appealLodge') }}</button>
            <button v-if="r.appeal_state === 'lodged'" type="button" class="btn btn--small" @click="appeal(r, 'uphold')">{{ t('pp.pas.appealUphold') }}</button>
            <button v-if="r.appeal_state === 'lodged'" type="button" class="btn btn--small" @click="appeal(r, 'revise')">{{ t('pp.pas.appealRevise') }}</button>
          </div>
        </li>
      </ul>
      <form v-if="canDecide && rows.some((r) => r.state === 'proposed')" class="controls" @submit.prevent="council">
        <div class="field">
          <label for="pa-date">{{ t('pp.pas.heldOn') }}</label>
          <input id="pa-date" v-model="heldOn" type="date" required />
        </div>
        <div class="field">
          <label for="pa-att">{{ t('pp.pas.attendees') }}</label>
          <input id="pa-att" v-model="attendees" type="text" required />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.pas.record') }}</button>
      </form>
      <button v-if="canDecide && councilId" type="button" class="btn" :disabled="busy" @click="minutes">{{ t('pp.pas.minutes') }}</button>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.row { border: 1px solid var(--input-border); padding: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
