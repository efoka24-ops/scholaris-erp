<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchPlatformSettings, type PlatformSetting } from '@/api/platform';
import { useI18n } from '@/i18n';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';

// Réglages de la plateforme : durée de validité des codes d'accès créés depuis le back-office (5 minutes à 7 jours).
const { t } = useI18n();
const action = useSensitiveAction();
const KEY = 'access_code_ttl_minutes';
const setting = ref<PlatformSetting | null>(null);
const minutes = ref('');
const invalid = ref(false);
const info = ref('');
const loadError = ref<unknown>(null);

async function load(): Promise<void> {
  loadError.value = null;
  try {
    setting.value = (await fetchPlatformSettings())[KEY] ?? null;
    minutes.value = setting.value ? String(setting.value.value) : '';
  } catch (e) {
    loadError.value = e;
  }
}

function human(total: number): string {
  if (total % 1440 === 0) return t('pa.set.days', { count: total / 1440 });
  if (total % 60 === 0) return t('pa.set.hours', { count: total / 60 });
  return t('pa.set.minutes', { count: total });
}

async function save(): Promise<void> {
  info.value = '';
  const value = Number(minutes.value);
  const s = setting.value;
  invalid.value = !s || !Number.isInteger(value) || value < s.min || value > s.max;
  if (invalid.value) return;
  const res = await action.run<{ data: Record<string, PlatformSetting> }>(actions.updateSettings({ [KEY]: value }), t('pa.set.title'));
  if (res) {
    setting.value = res.data[KEY] ?? setting.value;
    info.value = t('pa.set.saved', { duration: human(value) });
  }
}

onMounted(load);
</script>

<template>
  <section class="stack">
    <p class="eyebrow">{{ t('pa.set.eyebrow') }}</p>
    <h1>{{ t('pa.set.title') }}</h1>
    <ErrorNotice :error="loadError" />
    <ReplayNotice @done="load" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>

    <form v-if="setting" class="card stack" novalidate @submit.prevent="save">
      <h2>{{ t('pa.set.codeTitle') }}</h2>
      <p class="muted">{{ t('pa.set.codeLede') }}</p>
      <div class="field">
        <label for="ttl">{{ t('pa.set.codeLabel') }}</label>
        <input id="ttl" v-model="minutes" type="number" inputmode="numeric" :min="setting.min" :max="setting.max" :aria-invalid="invalid" />
        <span class="hint">{{ t('pa.set.codeHint', { current: human(setting.value), min: setting.min, max: setting.max }) }}</span>
        <span v-if="invalid" class="error">{{ t('pa.set.codeError', { min: setting.min, max: setting.max }) }}</span>
      </div>
      <div class="row">
        <button type="button" class="btn" @click="minutes = '60'">{{ t('pa.set.hours', { count: 1 }) }}</button>
        <button type="button" class="btn" @click="minutes = '1440'">{{ t('pa.set.hours', { count: 24 }) }}</button>
        <button type="button" class="btn" @click="minutes = '4320'">{{ t('pa.set.days', { count: 3 }) }}</button>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('admin.save') }}</button>
    </form>
  </section>
</template>
