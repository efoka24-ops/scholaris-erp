<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ASSESSMENT_KINDS, ASSESSMENT_STATUSES, grades, type Assessment } from '@/api/higher-grades';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import CreateAssessmentForm from './CreateAssessmentForm.vue';
import GradesNav from './GradesNav.vue';
import { G } from './perms';
import { gk, useGradesI18n } from './i18n';

// Évaluations du périmètre (EX-NOT-01) : filtres EC, groupe, état, nature ; création par l'enseignant affecté.
const { tg, locale } = useGradesI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();

const items = ref<Assessment[]>([]);
/** EC connus (premier chargement sans filtre EC) pour garder la liste de choix stable. */
const knownElements = ref<Array<{ id: string; code: string; title: string }>>([]);
const filters = reactive({ element_id: '', group_id: '', status: '', kind: '' });

const canCreate = computed(() => ctx.can(...G.enter) && !higher.readOnly);

async function load(): Promise<void> {
  await higher.loadRefs('groups');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  for (const [key, value] of Object.entries(filters)) if (value) q[`filter[${key}]`] = value;
  const res = await list.run(() => grades.assessments(q));
  if (!res) return;
  items.value = res;
  const seen = new Map(knownElements.value.map((e) => [e.id, e]));
  for (const a of res) if (!seen.has(a.element_id)) seen.set(a.element_id, { id: a.element_id, code: a.element_code, title: a.element_title });
  knownElements.value = [...seen.values()].sort((a, b) => a.code.localeCompare(b.code));
}

async function created(): Promise<void> {
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="tg('hg.title')" :lede="tg('hg.lede')" section="portal" @school-change="load">
    <GradesNav />
    <div class="hi-form" role="search">
      <div class="field">
        <label for="af-el">{{ tg('hg.filter.element') }}</label>
        <select id="af-el" v-model="filters.element_id" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="e in knownElements" :key="e.id" :value="e.id">{{ e.code }} · {{ e.title }}</option></select>
      </div>
      <div class="field">
        <label for="af-grp">{{ tg('hg.filter.group') }}</label>
        <select id="af-grp" v-model="filters.group_id" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
      </div>
      <div class="field">
        <label for="af-st">{{ tg('hg.filter.status') }}</label>
        <select id="af-st" v-model="filters.status" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="s in ASSESSMENT_STATUSES" :key="s" :value="s">{{ tg(gk('hg.state.', s)) }}</option></select>
      </div>
      <div class="field">
        <label for="af-kind">{{ tg('hg.filter.kind') }}</label>
        <select id="af-kind" v-model="filters.kind" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="k in ASSESSMENT_KINDS" :key="k" :value="k">{{ tg(gk('hg.kind.', k)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ tg('hg.element') }}</th><th scope="col">{{ tg('hg.label') }}</th><th scope="col">{{ tg('hg.kind') }}</th><th scope="col">{{ tg('hg.group') }}</th>
            <th scope="col">{{ tg('hg.dueOn') }}</th><th scope="col" class="num">{{ tg('hg.max') }}</th><th scope="col">{{ tg('hg.status') }}</th><th scope="col">{{ tg('hg.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in items" :key="a.id" data-test="assessment-row">
            <th scope="row">{{ a.element_code }}<br /><small class="muted">{{ a.element_title }}</small></th>
            <td>{{ a.label }}<span v-if="a.anonymous" class="tag tag--neutral">{{ tg('hg.anonymous') }}</span></td>
            <td>{{ tg(gk('hg.kind.', a.kind)) }}</td>
            <td>{{ a.group_id ? higher.groupName(a.group_id) : tg('hg.allGroups') }}</td>
            <td>{{ a.entry_due_on ? formatDate(a.entry_due_on, locale) : '—' }}</td>
            <td class="num">{{ a.max_score_label }}</td>
            <td><span class="tag" :class="`tag--${toneOf(a.status) ?? 'neutral'}`">{{ tg(gk('hg.state.', a.status)) }}</span></td>
            <td class="hi-actions">
              <router-link :to="{ name: 'higher-grades-grid', params: { id: a.id } }">{{ tg('hg.open') }}</router-link>
              <router-link :to="{ name: 'higher-grades-stats', params: { id: a.id } }">{{ tg('hg.stats') }}</router-link>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="8" class="muted">{{ tg('hg.none') }}</td></tr>
        </tbody>
      </table>
    </div>
    <CreateAssessmentForm v-if="canCreate" @created="created" />
  </HigherFrame>
</template>
