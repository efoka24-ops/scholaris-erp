<script setup lang="ts">
// Conduite de la période : un niveau de l'échelle de l'école par élève, reporté au conseil. L'échelle et ses libellés viennent des
// paramètres de l'école (aucune grille officielle n'est imposée).
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listPeriods, listStructures, type Period } from '@/api/admin';
import { primary, type ConductSheet } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const classes = ref<{ id: string; name: string }[]>([]);
const periods = ref<Period[]>([]);
const classId = ref('');
const periodId = ref('');
const sheet = ref<ConductSheet | null>(null);
const values = reactive<Record<string, { code: string; remark: string }>>({});
const saved = ref(false);
const canEnter = computed(() => can(...P.conductEnter));
const periodName = (p: Period): string => `${t(`pri.sheet.kind.${p.kind}` as MessageKey)} ${p.ordinal}`;

async function init(): Promise<void> {
  await run(async () => {
    classes.value = (await listStructures()).filter((s) => s.kind === 'class').map((s) => ({ id: s.id, name: s.name }));
    classId.value = classes.value[0]?.id ?? '';
    const year = await currentYear();
    if (year) {
      periods.value = await listPeriods(year.id);
      const today = new Date().toISOString().slice(0, 10);
      const main = periods.value.filter((p) => p.kind !== 'sequence');
      periodId.value = (main.find((p) => p.starts_on <= today && today <= p.ends_on) ?? main[0] ?? periods.value[0])?.id ?? '';
    }
  });
}

async function open(): Promise<void> {
  saved.value = false;
  sheet.value = null;
  if (classId.value === '' || periodId.value === '') return;
  const loaded = await run(() => primary.conduct(classId.value, periodId.value));
  if (!loaded) return;
  sheet.value = loaded;
  for (const key of Object.keys(values)) delete values[key];
  for (const m of loaded.marks) values[m.person_id] = { code: m.scale_code, remark: m.remark ?? '' };
}

async function save(): Promise<void> {
  const entries = Object.entries(values)
    .filter(([, v]) => v.code !== '')
    .map(([person, v]) => ({ person_id: person, scale_code: v.code, remark: v.remark.trim() === '' ? null : v.remark.trim() }));
  saved.value = (await run(() => primary.saveConduct(classId.value, periodId.value, entries))) !== undefined;
}

function pick(personId: string, code: string): void {
  values[personId] = { code, remark: values[personId]?.remark ?? '' };
  saved.value = false;
}

watch([classId, periodId], () => void open());
onMounted(async () => {
  await init();
  await open();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.conduct.title') }}</h1>
      <p class="lede">{{ t('pri.conduct.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="grid2">
        <div class="field">
          <label for="cd-class">{{ t('pri.call.class') }}</label>
          <select id="cd-class" v-model="classId"><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <div class="field">
          <label for="cd-period">{{ t('pri.sheet.period') }}</label>
          <select id="cd-period" v-model="periodId"><option v-for="p in periods" :key="p.id" :value="p.id">{{ periodName(p) }}</option></select>
        </div>
      </div>
    </section>

    <section v-if="sheet" class="card">
      <p v-if="sheet.learners.length === 0" class="muted">{{ t('pri.sheet.noLearners') }}</p>
      <ul class="list">
        <li v-for="l in sheet.learners" :key="l.person_id">
          <strong>{{ l.name }}</strong>
          <div class="levels" role="group" :aria-label="l.name">
            <button
              v-for="s in sheet.scale"
              :key="s.code"
              type="button"
              class="btn level"
              :class="{ 'btn--primary': values[l.person_id]?.code === s.code }"
              :aria-pressed="values[l.person_id]?.code === s.code"
              :disabled="!canEnter"
              @click="pick(l.person_id, s.code)"
            >{{ locale === 'en' ? s.label_en : s.label_fr }}</button>
          </div>
        </li>
      </ul>
      <button v-if="canEnter" type="button" class="btn btn--primary" :disabled="busy" @click="save">{{ t('pri.conduct.save') }}</button>
      <p v-if="saved" class="notice" role="status">{{ t('pri.conduct.saved') }}</p>
    </section>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.list { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.levels { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.level { min-height: 2.75rem; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
