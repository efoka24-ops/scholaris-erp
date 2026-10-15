<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { curricula, type Curriculum } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Liste et création des maquettes (EX-MAQ-01) — design : Fondations.html (tableau, étiquettes de statut).
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<Curriculum[]>([]);
const filters = reactive({ program_id: '', academic_year_id: '', status: '' });
const touched = ref(false);
const form = reactive({ program_id: '', level_id: '', academic_year_id: '', title: '', approved_programme_ref: '' });

const canManage = computed(() => ctx.can(...P.curriculumManage) && !higher.readOnly);
const formLevels = computed(() => higher.levels.filter((l) => l.program_id === form.program_id).sort((a, b) => a.ordinal - b.ordinal));
const isLmd = computed(() => higher.programs.find((p) => p.id === form.program_id)?.regime === 'lmd');

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (filters.program_id) q['filter[program_id]'] = filters.program_id;
  if (filters.academic_year_id) q['filter[academic_year_id]'] = filters.academic_year_id;
  if (filters.status) q['filter[status]'] = filters.status;
  const res = await list.run(() => curricula.list(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.program_id || !form.level_id || !form.academic_year_id) return;
  const res = await save.run(() =>
    curricula.create({
      program_id: form.program_id,
      level_id: form.level_id,
      academic_year_id: form.academic_year_id,
      ...(form.title.trim() ? { title: form.title.trim() } : {}),
      approved_programme_ref: form.approved_programme_ref.trim() || null,
    }),
  );
  if (res) await router.push({ name: 'higher-curriculum', params: { id: res.id } });
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.cur.title')" :lede="t('hi.cur.lede')" section="curriculum" @school-change="load">
    <form class="hi-form" :aria-label="t('hi.filters')" @submit.prevent="load">
      <div class="field">
<label for="cf-prog">{{ t('hi.program') }}</label>
        <select id="cf-prog" v-model="filters.program_id" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="p in higher.programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="cf-year" v-model="filters.academic_year_id" @update:model-value="load" />
      <div class="field">
<label for="cf-status">{{ t('hi.status') }}</label>
        <select id="cf-status" v-model="filters.status" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in ['draft', 'published', 'superseded']" :key="s" :value="s">{{ t(k('hi.cur.state.', s)) }}</option></select>
      </div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.level') }}</th><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.cur.titleField') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <th scope="row">{{ higher.programName(c.program_id) }}</th>
            <td>{{ higher.levelName(c.level_id) }}</td>
            <td>{{ higher.yearName(c.academic_year_id) }}</td>
            <td>{{ c.title ?? '—' }}</td>
            <td><span class="tag" :class="`tag--${toneOf(c.status) ?? 'neutral'}`">{{ t(k('hi.cur.state.', c.status)) }}</span> {{ t('hi.cur.version', { version: c.version }) }}<span v-if="c.published_at" class="muted hi-small"> · {{ formatDate(c.published_at, locale) }}</span></td>
            <td><router-link :to="{ name: 'higher-curriculum', params: { id: c.id } }">{{ t('hi.cur.edit') }}</router-link></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="save.error.value" />
    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.cur.new') }}</h2>
      <div class="field">
<label for="cn-prog">{{ t('hi.program') }}</label>
        <select id="cn-prog" v-model="form.program_id" :aria-invalid="touched && !form.program_id" @change="form.level_id = ''"><option value="" disabled>{{ t('hi.programPick') }}</option><option v-for="p in higher.programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <div class="field">
<label for="cn-level">{{ t('hi.level') }}</label>
        <select id="cn-level" v-model="form.level_id" :aria-invalid="touched && !form.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in formLevels" :key="l.id" :value="l.id">{{ l.code }} · {{ l.name }}</option></select>
      </div>
      <YearField id="cn-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="cn-title">{{ t('hi.cur.titleField') }}</label><input id="cn-title" v-model="form.title" type="text" maxlength="160" /></div>
      <div class="field hi-wide"><label for="cn-ref">{{ t('hi.cur.programmeRef') }}</label><input id="cn-ref" v-model="form.approved_programme_ref" type="text" maxlength="120" aria-describedby="cn-ref-hint" /><span v-if="isLmd" id="cn-ref-hint" class="hint">{{ t('hi.cur.programmeRefHint') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
