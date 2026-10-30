<script setup lang="ts">
// Passage de fin d'année : propositions (section suivante ou primaire), avis de l'éducatrice, décision motivée de la direction ; fiche de
// liaison vers l'école primaire (préparée, puis signée et émise avec numéro et QR) ; état de clôture de l'année. Aucune règle officielle
// n'est codée ici : le serveur applique les paramètres de l'école (maintien encadré).
import { computed, ref, watch } from 'vue';
import { nursery, type IssuedDocument, type LiaisonSheet, type Section, type TransitionDecision } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const section = ref('');
const sections = ref<Section[]>([]);
const decisions = ref<TransitionDecision[]>([]);
const sheets = ref<LiaisonSheet[]>([]);
const opinions = ref<Record<string, string>>({});
const choice = ref<Record<string, { decision: string; reason: string; parent_request_ref: string }>>({});
const closure = ref<{ blocking_count: number; ready: boolean; detail: string } | null>(null);
const issued = ref<IssuedDocument | null>(null);

const labels = computed(() => Object.fromEntries(sections.value.map((s) => [s.id, s.label])));
const names = computed(() => Object.fromEntries(decisions.value.map((d) => [d.learner_id, d.child_name ?? d.learner_id])));

async function load(): Promise<void> {
  if (!section.value) return;
  decisions.value = (await run(() => nursery.decisions({ section_id: section.value }))) ?? [];
  sheets.value = (await run(() => nursery.liaisons({ section_id: section.value }))) ?? [];
  for (const d of decisions.value) choice.value[d.id] ??= { decision: d.decision ?? d.proposal, reason: d.decision_reason ?? '', parent_request_ref: d.parent_request_ref ?? '' };
}
watch(section, () => void load());
async function propose(): Promise<void> {
  if (await run(() => nursery.propose(section.value))) await load();
}
async function giveOpinion(d: TransitionDecision): Promise<void> {
  if (await run(() => nursery.opinion(d.id, (opinions.value[d.id] ?? '').trim()))) await load();
}
async function decide(d: TransitionDecision): Promise<void> {
  const c = choice.value[d.id];
  if (!c) return;
  if (await run(() => nursery.decide(d.id, { decision: c.decision, reason: c.reason.trim() || null, parent_request_ref: c.parent_request_ref.trim() || null }))) await load();
}
async function createSheet(learnerId: string): Promise<void> {
  if (await run(() => nursery.createLiaison(learnerId))) await load();
}
async function saveSheet(s: LiaisonSheet): Promise<void> {
  if (await run(() => nursery.updateLiaison(s.id, { path_summary: s.path_summary, strengths: s.strengths, needs: s.needs, destination: s.destination }))) await load();
}
async function signSheet(s: LiaisonSheet): Promise<void> {
  const signed = await run(() => nursery.signLiaison(s.id));
  if (signed) {
    issued.value = signed.document;
    await load();
  }
}
async function checkClosure(): Promise<void> {
  const year = decisions.value[0]?.academic_year_id;
  if (year) closure.value = (await run(() => nursery.closure(year))) ?? null;
}
const goesToPrimary = (d: TransitionDecision): boolean => (d.decision ?? d.proposal) === 'to_primary';
const sheetOf = (learnerId: string): LiaisonSheet | undefined => sheets.value.find((s) => s.learner_id === learnerId);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.trans.title') }}</h1>
      <p class="lede">{{ t('nur.trans.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="issued" class="notice" role="status" aria-live="polite">{{ t('nur.doc.issued', { number: issued.number }) }}</p>

    <section class="card row">
      <SectionPicker v-model="section" id="tr-section" @loaded="sections = $event" />
      <button type="button" class="btn" :disabled="busy || !section" @click="propose">{{ t('nur.trans.propose') }}</button>
      <button v-if="can(...P.transitionDecide)" type="button" class="btn btn--quiet" :disabled="busy || decisions.length === 0" @click="checkClosure">{{ t('nur.trans.closure') }}</button>
    </section>
    <p v-if="closure" class="card" role="status" aria-live="polite">
      <span :class="['tag', closure.ready ? 'tag--ok' : 'tag--warn']">{{ closure.ready ? t('nur.trans.ready') : t('nur.trans.notReady') }}</span> {{ closure.detail }}
    </p>

    <section v-if="section" class="card">
      <p v-if="decisions.length === 0" class="muted">{{ t('nur.trans.none') }}</p>
      <ul class="list">
        <li v-for="d in decisions" :key="d.id">
          <p>
            <strong>{{ names[d.learner_id] }}</strong> ·
            {{ t(`nur.trans.d.${d.proposal}` as MessageKey) }}<span v-if="d.target_section_id && d.proposal === 'promote'"> ({{ labels[d.target_section_id] ?? '' }})</span>
            <span class="tag tag--neutral">{{ t(`nur.trans.s.${d.status}` as MessageKey) }}</span>
          </p>
          <p v-if="d.educator_opinion" class="muted">{{ t('nur.trans.opinion') }} : {{ d.educator_opinion }}</p>
          <p v-if="d.decision">{{ t('nur.trans.decision') }} : {{ t(`nur.trans.d.${d.decision}` as MessageKey) }}<span v-if="d.decision_reason"> · {{ d.decision_reason }}</span></p>
          <div v-if="d.status !== 'applied' && d.status !== 'decided' && can('nursery.transition.opinion')" class="row">
            <input v-model="opinions[d.id]" type="text" maxlength="500" :aria-label="t('nur.trans.opinion')" :placeholder="t('nur.trans.opinion')" />
            <button type="button" class="btn btn--quiet" :disabled="busy || !(opinions[d.id] ?? '').trim()" @click="giveOpinion(d)">{{ t('nur.trans.opinionDo') }}</button>
          </div>
          <fieldset v-if="d.status !== 'applied' && can(...P.transitionDecide) && choice[d.id]" class="decide">
            <legend>{{ t('nur.trans.decide') }}</legend>
            <label :for="`tr-dec-${d.id}`">{{ t('nur.trans.decision') }}</label>
            <select :id="`tr-dec-${d.id}`" v-model="choice[d.id]!.decision">
              <option value="promote">{{ t('nur.trans.d.promote') }}</option>
              <option value="to_primary">{{ t('nur.trans.d.to_primary') }}</option>
              <option value="retain">{{ t('nur.trans.d.retain') }}</option>
            </select>
            <template v-if="choice[d.id]!.decision === 'retain'">
              <label :for="`tr-reason-${d.id}`">{{ t('nur.trans.reason') }}</label>
              <input :id="`tr-reason-${d.id}`" v-model="choice[d.id]!.reason" type="text" maxlength="500" />
              <label :for="`tr-req-${d.id}`">{{ t('nur.trans.request') }}</label>
              <input :id="`tr-req-${d.id}`" v-model="choice[d.id]!.parent_request_ref" type="text" maxlength="120" />
            </template>
            <button type="button" class="btn btn--primary" :disabled="busy" @click="decide(d)">{{ t('nur.trans.decideDo') }}</button>
          </fieldset>

          <div v-if="goesToPrimary(d)" class="sheet">
            <button v-if="!sheetOf(d.learner_id)" type="button" class="btn btn--quiet" :disabled="busy" @click="createSheet(d.learner_id)">{{ t('nur.trans.sheetCreate') }}</button>
            <template v-else>
              <h3>{{ t('nur.trans.sheet') }} <span class="tag tag--neutral">{{ t(`nur.trans.sheetStatus.${sheetOf(d.learner_id)!.status}` as MessageKey) }}</span></h3>
              <template v-if="sheetOf(d.learner_id)!.status === 'draft'">
                <label :for="`tr-path-${d.id}`">{{ t('nur.trans.path') }}</label>
                <textarea :id="`tr-path-${d.id}`" v-model="sheetOf(d.learner_id)!.path_summary" rows="2" maxlength="1000"></textarea>
                <label :for="`tr-str-${d.id}`">{{ t('nur.trans.strengths') }}</label>
                <textarea :id="`tr-str-${d.id}`" v-model="sheetOf(d.learner_id)!.strengths" rows="3" maxlength="2000"></textarea>
                <label :for="`tr-needs-${d.id}`">{{ t('nur.trans.needs') }}</label>
                <textarea :id="`tr-needs-${d.id}`" v-model="sheetOf(d.learner_id)!.needs" rows="2" maxlength="1000"></textarea>
                <label :for="`tr-dest-${d.id}`">{{ t('nur.trans.destination') }}</label>
                <input :id="`tr-dest-${d.id}`" v-model="sheetOf(d.learner_id)!.destination" type="text" maxlength="160" />
                <div class="row">
                  <button type="button" class="btn" :disabled="busy" @click="saveSheet(sheetOf(d.learner_id)!)">{{ t('nur.books.save') }}</button>
                  <button v-if="can(...P.transitionDecide)" type="button" class="btn btn--primary" :disabled="busy" @click="signSheet(sheetOf(d.learner_id)!)">{{ t('nur.trans.sign') }}</button>
                </div>
              </template>
              <p v-else class="muted">{{ t('nur.trans.signed') }}</p>
            </template>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
.decide, .sheet { display: grid; gap: 6px; margin-top: 8px; }
textarea { width: 100%; }
</style>
