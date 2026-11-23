<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { http } from '@/api/http';
import { createObservation } from '@/api/publicNursery';
import { todayIso } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useI18n } from '@/i18n';

interface FrameRow {
  id: string;
  frame_code: string;
  label_fr: string;
  label_en: string;
  version: number;
  status: string;
}

const { t } = useI18n();
const auth = useAuthStore();
const frames = ref<FrameRow[]>([]);
const saved = ref<Record<string, unknown> | null>(null);
const error = ref<unknown>(null);
const loading = ref(false);
const form = reactive({
  learner_id: '',
  structure_id: '',
  frame_id: '',
  domain_id: '',
  item_id: '',
  level_code: 'in_progress' as 'acquired' | 'in_progress' | 'not_yet',
  remark: '',
  observed_on: todayIso(),
});

const frameLabel = (row: FrameRow): string => `${row.label_fr} · v${row.version}`;
const canSubmit = computed(
  () =>
    auth.me !== null &&
    form.learner_id.trim() !== '' &&
    form.structure_id.trim() !== '' &&
    form.frame_id.trim() !== '' &&
    form.domain_id.trim() !== '' &&
    form.item_id.trim() !== '',
);

async function loadFrames(): Promise<void> {
  try {
    // Le serveur liste tous les cadres du client ; seuls les cadres actifs servent à observer.
    const rows = await http.all<FrameRow>('/public-nursery/development/frames', {}, {}, 3);
    frames.value = rows.filter((row) => row.status === 'active');
    if (!form.frame_id && frames.value[0]) form.frame_id = frames.value[0].id;
  } catch (e) {
    error.value = e;
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value || auth.me === null) return;
  loading.value = true;
  error.value = null;
  saved.value = null;
  try {
    const response = await createObservation({
      learner_id: form.learner_id.trim(),
      structure_id: form.structure_id.trim(),
      observer_id: auth.me.id,
      frame_id: form.frame_id.trim(),
      domain_id: form.domain_id.trim(),
      item_id: form.item_id.trim(),
      level_code: form.level_code,
      remark: form.remark.trim() === '' ? undefined : form.remark.trim(),
      observed_on: form.observed_on,
      client_uuid: `pn-${Date.now()}`,
    });
    saved.value = response.data as Record<string, unknown>;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

onMounted(() => void loadFrames());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.observation.eyebrow') }}</p>
      <h1>{{ t('pn.observation.title') }}</h1>
      <p class="lead">{{ t('pn.observation.intro') }}</p>
    </header>

    <ErrorNotice :error="error" />

    <section class="grid">
      <form class="card panel" @submit.prevent="submit">
        <h2>{{ t('pn.observation.formTitle') }}</h2>
        <div class="field">
          <label for="obs-learner">{{ t('nursery.child') }}</label>
          <input id="obs-learner" v-model="form.learner_id" type="text" placeholder="learner-123" autocomplete="off" />
        </div>
        <div class="field">
          <label for="obs-section">{{ t('pn.observation.section') }}</label>
          <input id="obs-section" v-model="form.structure_id" type="text" placeholder="section-a" autocomplete="off" />
        </div>
        <div class="field">
          <label for="obs-frame">{{ t('pn.observation.frame') }}</label>
          <select v-if="frames.length > 0" id="obs-frame" v-model="form.frame_id">
            <option value="" disabled>{{ t('grades.choose') }}</option>
            <option v-for="frame in frames" :key="frame.id" :value="frame.id">{{ frameLabel(frame) }}</option>
          </select>
          <template v-else>
            <input id="obs-frame" v-model="form.frame_id" type="text" placeholder="frame-1" autocomplete="off" aria-describedby="obs-frame-help" />
            <p id="obs-frame-help" class="muted small">{{ t('pn.observation.noFrame') }}</p>
          </template>
        </div>
        <div class="field grid2">
          <div>
            <label for="obs-domain">{{ t('pn.observation.domain') }}</label>
            <input id="obs-domain" v-model="form.domain_id" type="text" placeholder="domain-1" autocomplete="off" />
          </div>
          <div>
            <label for="obs-item">{{ t('pn.observation.item') }}</label>
            <input id="obs-item" v-model="form.item_id" type="text" placeholder="item-1" autocomplete="off" />
          </div>
        </div>
        <div class="field grid2">
          <div>
            <label for="obs-level">{{ t('pn.observation.level') }}</label>
            <select id="obs-level" v-model="form.level_code">
              <option value="acquired">{{ t('pn.observation.level.acquired') }}</option>
              <option value="in_progress">{{ t('pn.observation.level.in_progress') }}</option>
              <option value="not_yet">{{ t('pn.observation.level.not_yet') }}</option>
            </select>
          </div>
          <div>
            <label for="obs-date">{{ t('pn.observation.observedOn') }}</label>
            <input id="obs-date" v-model="form.observed_on" type="date" />
          </div>
        </div>
        <div class="field">
          <label for="obs-remark">{{ t('pn.observation.remark') }}</label>
          <textarea id="obs-remark" v-model="form.remark" rows="4" :placeholder="t('pn.observation.helper')"></textarea>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="loading || !canSubmit">{{ t('pn.observation.submit') }}</button>
      </form>

      <aside class="card panel" role="status" aria-live="polite">
        <h2>{{ t('pn.observation.saved') }}</h2>
        <p v-if="saved === null" class="muted">{{ t('pn.observation.helper') }}</p>
        <pre v-else class="preview">{{ JSON.stringify(saved, null, 2) }}</pre>
      </aside>
    </section>
  </div>
</template>

<style scoped>
.pn-screen {
  max-width: 1120px;
}
.lead {
  max-width: 760px;
  color: var(--muted, #5f5b52);
}
.grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.grid2 {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
.small {
  font-size: 13px;
}
.preview {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: rgba(0, 0, 0, 0.03);
  padding: 12px;
  border-radius: 12px;
}
@media (min-width: 600px) {
  .grid2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (min-width: 901px) {
  .grid {
    grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
  }
}
</style>