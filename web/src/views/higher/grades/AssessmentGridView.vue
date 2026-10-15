<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, markConflicts, type EnterResult, type Grid, type GridLine, type MarkConflict, type MarkEntry } from '@/api/higher-grades';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import CorrectionRequest from './CorrectionRequest.vue';
import GradesNav from './GradesNav.vue';
import ImportPanel from './ImportPanel.vue';
import LifecyclePanel from './LifecyclePanel.vue';
import MarkHistory from './MarkHistory.vue';
import { describe, formatCell, parseCell, sameValue, type CellValue } from './marks';
import { G } from './perms';
import { gk, useGradesI18n } from './i18n';

// Grille de saisie d'une évaluation (API-09, maquettes Notes.html et NotesMobile.html) : une case par étudiant (note ou code
// d'absence), Entrée passe à la ligne suivante, lot atomique avec contrôle de version. Un 409 n'écrase jamais : les valeurs
// du serveur sont affichées en regard des vôtres, qui restent à l'écran.
const props = defineProps<{ id: string }>();
const { tg, locale } = useGradesI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const save = useHigherAction();

const grid = ref<Grid | null>(null);
const drafts = reactive<Record<string, string>>({});
const conflicts = ref<MarkConflict[]>([]);
const saved = ref<EnterResult | null>(null);
const search = ref('');
const historyOf = ref<{ markId: string; title: string } | null>(null);
const correcting = ref<{ markId: string; title: string } | null>(null);
const inputs = ref<HTMLInputElement[]>([]);

const a = computed(() => grid.value?.assessment ?? null);
const hidden = computed(() => !!a.value && a.value.anonymous && !a.value.anonymity_lifted);
const codes = computed(() => grid.value?.absence_codes ?? {});
const editable = computed(() => !!grid.value?.editable && !higher.readOnly && ctx.can(...G.enter));
const canCorrect = computed(() => !!a.value && ['locked', 'published'].includes(a.value.status) && ctx.can(...G.enter) && !higher.readOnly);
const conflictById = computed(() => new Map(conflicts.value.map((c) => [c.mark_id, c])));

const nameOf = (l: GridLine): string => (l.person_id ? `${l.last_name ?? ''} ${l.first_name ?? ''}`.trim() : (l.anonymous_code ?? '—'));
const serverValue = (l: GridLine): CellValue => ({ score: l.score, absence: l.absence });
const textOf = (l: GridLine): string => drafts[l.mark_id] ?? formatCell(serverValue(l), codes.value, locale.value);
const parsedOf = (l: GridLine) => parseCell(textOf(l), a.value?.max_score ?? 20, a.value?.score_step ?? 0.25, codes.value);
const isDirty = (l: GridLine): boolean => {
  if (drafts[l.mark_id] === undefined) return false;
  const p = parsedOf(l);
  return !p.ok || !sameValue(p.value, serverValue(l));
};
const isInvalid = (l: GridLine): boolean => drafts[l.mark_id] !== undefined && !parsedOf(l).ok;

const lines = computed(() => {
  const all = grid.value?.lines ?? [];
  const q = search.value.trim().toLowerCase();
  if (!q) return all;
  return all.filter((l) => [nameOf(l), l.matricule ?? '', l.anonymous_code ?? ''].some((s) => s.toLowerCase().includes(q)));
});
const dirty = computed(() => (grid.value?.lines ?? []).filter(isDirty));
const anyInvalid = computed(() => dirty.value.some(isInvalid));

async function reload(): Promise<void> {
  const res = await load.run(() => grades.grid(props.id));
  if (!res) return;
  grid.value = res;
  // Les saisies identiques au serveur sont abandonnées ; les autres (dont celles en conflit) restent en regard.
  for (const l of res.lines) if (drafts[l.mark_id] !== undefined && !isDirty(l)) delete drafts[l.mark_id];
}

function onInput(l: GridLine, value: string): void {
  drafts[l.mark_id] = value;
  saved.value = null;
}

async function next(index: number): Promise<void> {
  await nextTick();
  const target = inputs.value[index + 1];
  if (target) {
    target.focus();
    target.select();
  }
}

async function submit(): Promise<void> {
  if (!grid.value || dirty.value.length === 0) return;
  if (anyInvalid.value) return;
  const entries: MarkEntry[] = dirty.value.map((l) => {
    const p = parsedOf(l);
    const v = p.ok ? p.value : { score: null, absence: null };
    const key = hidden.value || !l.person_id ? { anonymous_code: l.anonymous_code ?? '' } : { person_id: l.person_id };
    return { ...key, ...(v.absence ? { absence: v.absence } : { score: v.score }), expected_version: l.version };
  });
  conflicts.value = [];
  const res = await save.run(() => grades.enter(props.id, entries));
  if (res) {
    saved.value = res;
    for (const l of dirty.value) delete drafts[l.mark_id];
    await reload();
    return;
  }
  const found = markConflicts(save.error.value);
  if (found) conflicts.value = found;
}

async function afterImport(): Promise<void> {
  await reload();
}

async function corrected(): Promise<void> {
  correcting.value = null;
  await reload();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups');
  await reload();
});
</script>

<template>
  <HigherFrame :title="a ? `${a.element_code} · ${a.label}` : tg('hg.grid.title')" :lede="a ? `${tg(gk('hg.kind.', a.kind))} · ${a.group_id ? higher.groupName(a.group_id) : tg('hg.allGroups')} · /${a.max_score_label} · ${a.weight_percent} %` : ''" section="portal" :selector="false">
    <GradesNav />
    <ErrorNotice :error="load.error.value" />
    <template v-if="grid && a">
      <p class="row row--between">
        <strong data-test="grid-count">{{ tg('hg.grid.count', { entered: grid.entered, total: grid.total }) }}</strong>
        <router-link :to="{ name: 'higher-grades-stats', params: { id: a.id } }">{{ tg('hg.stats') }}</router-link>
      </p>
      <p v-if="hidden" class="notice">{{ tg('hg.grid.anonHidden') }}</p>
      <p v-if="!grid.editable" class="notice notice--warning" data-test="grid-closed">{{ tg('hg.grid.closed', { state: tg(gk('hg.state.', a.status)) }) }}</p>
      <p v-else-if="editable" class="muted hi-small">{{ tg('hg.grid.keyHint') }}</p>

      <section v-if="conflicts.length" class="notice notice--warning" role="alert" data-test="grid-conflict">
        <p><strong>{{ tg('hg.conflict.title') }}</strong></p>
        <p>{{ tg('hg.conflict.text') }}</p>
        <ul class="hi-errors">
          <li v-for="c in conflicts" :key="c.mark_id">
            {{ tg('hg.conflict.line', { line: c.line }) }} ·
            {{ tg('hg.conflict.server', { value: describe(c.current, locale), version: c.current_version }) }} ·
            {{ tg('hg.conflict.mine', { value: drafts[c.mark_id] ?? '—' }) }}
          </li>
        </ul>
        <button type="button" class="btn btn--ink" data-test="conflict-reload" @click="reload">{{ tg('hg.conflict.reload') }}</button>
      </section>
      <ErrorNotice v-if="!conflicts.length" :error="save.error.value" />
      <p v-if="saved" class="notice" role="status" data-test="grid-saved">
        {{ tg('hg.grid.saved', { written: saved.written, unchanged: saved.unchanged }) }}
        <template v-if="saved.discipline_referrals"> {{ tg('hg.grid.referrals', { count: saved.discipline_referrals }) }}</template>
      </p>

      <div class="field hg-search">
        <label for="grid-search">{{ tg('hg.grid.search') }}</label>
        <input id="grid-search" v-model="search" type="search" />
      </div>

      <div class="table-wrap">
        <table class="hg-grid">
          <caption class="sr-only">{{ tg('hg.grid.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">{{ hidden ? tg('hg.grid.code') : tg('hg.grid.student') }}</th>
              <th v-if="!hidden" scope="col" class="hg-hide-sm">{{ tg('hg.grid.matricule') }}</th>
              <th scope="col" class="num">{{ tg('hg.grid.score', { max: a.max_score_label }) }}</th>
              <th scope="col">{{ tg('hg.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in lines" :key="l.mark_id" :class="{ 'hg-dirty': isDirty(l), 'hg-conflict': conflictById.has(l.mark_id) }" data-test="grid-line">
              <td>{{ i + 1 }}</td>
              <th scope="row">{{ nameOf(l) }}<span v-if="l.anonymous_code && !hidden" class="muted hi-small"> · {{ l.anonymous_code }}</span></th>
              <td v-if="!hidden" class="hi-mono hg-hide-sm">{{ l.matricule ?? '—' }}</td>
              <td class="num">
                <input
                  :ref="(el) => { if (el) inputs[i] = el as HTMLInputElement }"
                  class="hg-cell"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  enterkeyhint="next"
                  :aria-label="`${nameOf(l)} · ${tg('hg.grid.score', { max: a.max_score_label })}`"
                  :value="textOf(l)"
                  :disabled="!editable"
                  :aria-invalid="isInvalid(l)"
                  @input="onInput(l, ($event.target as HTMLInputElement).value)"
                  @keydown.enter.prevent="next(i)"
                />
                <span v-if="isInvalid(l)" class="error hi-small">{{ tg('hg.grid.badScore', { max: a.max_score_label, step: String(a.score_step).replace('.', locale === 'fr' ? ',' : '.') }) }}</span>
                <span v-if="conflictById.has(l.mark_id)" class="tag tag--warning">{{ describe(conflictById.get(l.mark_id)!.current, locale) }}</span>
              </td>
              <td class="hi-actions">
                <button v-if="l.version > 0" type="button" class="hi-linkbtn" @click="historyOf = { markId: l.mark_id, title: nameOf(l) }">{{ tg('hg.grid.history') }}</button>
                <button v-if="canCorrect" type="button" class="hi-linkbtn" :data-test="`correct-${l.mark_id}`" @click="correcting = { markId: l.mark_id, title: nameOf(l) }">{{ tg('hg.grid.correct') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="editable" class="hg-savebar">
        <span v-if="dirty.length" class="muted">{{ tg('hg.grid.dirty') }}</span>
        <span v-if="anyInvalid" class="error">{{ tg('hg.grid.fixFirst') }}</span>
        <button type="button" class="btn btn--primary" :disabled="dirty.length === 0 || anyInvalid || save.busy.value" data-test="grid-save" @click="submit">{{ tg('hg.grid.save', { count: dirty.length }) }}</button>
      </div>

      <MarkHistory v-if="historyOf" :key="historyOf.markId" :mark-id="historyOf.markId" :title="historyOf.title" @close="historyOf = null" />
      <CorrectionRequest v-if="correcting" :key="correcting.markId" :mark-id="correcting.markId" :title="correcting.title" :max="a.max_score" :step="a.score_step" :codes="codes" @close="correcting = null" @sent="corrected" />
      <ImportPanel v-if="editable" :assessment-id="a.id" @imported="afterImport" />
      <LifecyclePanel :assessment="a" :next-states="grid.next_states" @changed="reload" />
    </template>
  </HigherFrame>
</template>

<style scoped>
.hg-search {
  max-width: 360px;
}
.hg-cell {
  width: 6rem;
  min-height: var(--tap);
  text-align: right;
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}
.hg-dirty {
  background: var(--warning-bg);
}
.hg-conflict th {
  box-shadow: inset 4px 0 0 var(--warning);
}
.hg-savebar {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: flex-end;
  padding: 12px 0;
  background: var(--bg);
  border-top: 1px solid var(--border);
}
@media (max-width: 640px) {
  .hg-hide-sm {
    display: none;
  }
  .hg-savebar .btn {
    flex: 1 1 100%;
  }
}
</style>
