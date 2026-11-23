<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { decideTransition, listTransitionDecisions, proposeTransitionDecisions, type PublicNurseryTransitionDecision } from '@/api/publicNursery';
import { useI18n } from '@/i18n';

const { t } = useI18n();
const error = ref<unknown>(null);
const loading = ref(false);
type Choice = { decision: 'promote' | 'to_primary' | 'retain'; parent_request_ref: string; inspector_opinion: 'favourable' | 'unfavourable' };
type Item = { row: PublicNurseryTransitionDecision; choice: Choice };

const items = ref<Item[]>([]);
const batchSummary = ref('');
const form = reactive({ schoolYear: '2026-2027', academicYearId: '', nextYearId: '' });

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    items.value = (await listTransitionDecisions(form.schoolYear)).data.map((row) => ({
      row,
      choice: {
        decision: (row.decision ?? row.proposal) as Choice['decision'],
        parent_request_ref: row.parent_request_ref ?? '',
        inspector_opinion: (row.inspector_opinion as Choice['inspector_opinion'] | undefined) ?? 'favourable',
      },
    }));
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function propose(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const result = await proposeTransitionDecisions({ academic_year_id: form.academicYearId.trim(), next_year_id: form.nextYearId.trim() });
    batchSummary.value = result.data.summary;
    await load();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function save(item: Item): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const choice = item.choice;
    await decideTransition(item.row.id, {
      decision: choice.decision,
      parent_request_ref: choice.decision === 'retain' ? choice.parent_request_ref.trim() || null : null,
      inspector_opinion: choice.decision === 'retain' ? choice.inspector_opinion : null,
    });
    await load();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.transition.eyebrow') }}</p>
      <h1>{{ t('pn.transition.title') }}</h1>
      <p class="lead">{{ t('pn.transition.intro') }}</p>
    </header>

    <ErrorNotice :error="error" />

    <section class="card panel">
      <div class="grid3">
        <div class="field">
          <label for="td-school-year">{{ t('pn.transition.schoolYear') }}</label>
          <input id="td-school-year" v-model="form.schoolYear" type="text" placeholder="2026-2027" />
        </div>
        <div class="field">
          <label for="td-year">{{ t('pn.transition.academicYear') }}</label>
          <input id="td-year" v-model="form.academicYearId" type="text" placeholder="y2026" />
        </div>
        <div class="field">
          <label for="td-next-year">{{ t('pn.transition.nextYear') }}</label>
          <input id="td-next-year" v-model="form.nextYearId" type="text" placeholder="y2027" />
        </div>
      </div>
      <div class="row">
        <button type="button" class="btn btn--primary" :disabled="loading || form.academicYearId.trim() === '' || form.nextYearId.trim() === ''" @click="propose">{{ t('pn.transition.propose') }}</button>
        <button type="button" class="btn" :disabled="loading" @click="load">{{ t('pn.transition.refresh') }}</button>
      </div>
      <p v-if="batchSummary" class="muted">{{ t('pn.transition.summary') }} : {{ batchSummary }}</p>
    </section>

    <section class="card panel">
      <p v-if="items.length === 0" class="muted">{{ t('pn.transition.none') }}</p>
      <article v-for="item in items" :key="item.row.id" class="decision-row">
        <header>
          <strong>{{ item.row.learner_id }}</strong>
          <div class="muted small">{{ item.row.school_year }} · {{ item.row.from_level }} · {{ item.row.status }}</div>
        </header>
        <div class="grid3 compact">
          <div class="field">
            <label :for="`dec-${item.row.id}`">{{ t('pn.transition.decision') }}</label>
            <select :id="`dec-${item.row.id}`" v-model="item.choice.decision">
              <option value="promote">promote</option>
              <option value="to_primary">to_primary</option>
              <option value="retain">retain</option>
            </select>
          </div>
          <div class="field">
            <label :for="`req-${item.row.id}`">{{ t('pn.transition.parentRequest') }}</label>
            <input :id="`req-${item.row.id}`" v-model="item.choice.parent_request_ref" type="text" :disabled="item.choice.decision !== 'retain'" />
          </div>
          <div class="field">
            <label :for="`insp-${item.row.id}`">{{ t('pn.transition.inspectorOpinion') }}</label>
            <select :id="`insp-${item.row.id}`" v-model="item.choice.inspector_opinion" :disabled="item.choice.decision !== 'retain'">
              <option value="favourable">favourable</option>
              <option value="unfavourable">unfavourable</option>
            </select>
          </div>
        </div>
        <button type="button" class="btn" :disabled="loading" @click="save(item)">{{ t('pn.transition.saveDecision') }}</button>
      </article>
    </section>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.lead { max-width: 760px; color: var(--muted, #5f5b52); }
.panel { display: flex; flex-direction: column; gap: 12px; }
.grid3 { display: grid; grid-template-columns: 1fr; gap: 12px; }
.decision-row { display: grid; gap: 12px; padding: 12px; border: 1px solid var(--border, #dcdcd8); border-radius: 12px; }
.compact { align-items: end; }
@media (min-width: 901px) { .grid3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>