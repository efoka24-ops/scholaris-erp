<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchPlatformSettings, type PlatformSetting } from '@/api/platform';
import { fetchSecuritySettings, securityActions, type SecuritySettings } from '@/api/platformBackoffice';
import { useI18n, type MessageKey } from '@/i18n';
import { platformCan } from './platformAccess';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Réglages de la plateforme par rôle (C-112 point 6, super administrateur seul) : seuils des sondes de santé, clients inactifs,
// plafond d'envoi en masse, durée de session et double authentification obligatoire par rôle.
const { t, locale } = useI18n();
const action = useSensitiveAction();
const canManage = platformCan('platform.settings.manage');
const security = ref<SecuritySettings | null>(null);
const settings = ref<Record<string, PlatformSetting>>({});
const values = ref<Record<string, string>>({});
const hours = ref('');
const error = ref<unknown>(null);
const saved = ref<string | null>(null);
const numericKeys = computed(() => Object.keys(settings.value).filter((k) => k.startsWith('health_') || k === 'kpi_inactive_days' || k === 'broadcast_max_recipients'));
const label = (key: string): string => t(`pset.${key}` as MessageKey);

async function load(): Promise<void> {
  try {
    const [s, all] = await Promise.all([fetchSecuritySettings(), fetchPlatformSettings()]);
    security.value = s;
    settings.value = all;
    hours.value = String(s.session_hours.value);
    values.value = Object.fromEntries(Object.entries(all).map(([k, v]) => [k, String(v.value)]));
  } catch (e) {
    error.value = e;
  }
}

async function saveThresholds(): Promise<void> {
  saved.value = null;
  const changes: Record<string, number> = {};
  for (const key of numericKeys.value) {
    const n = Number(values.value[key]);
    if (Number.isInteger(n) && n !== settings.value[key]?.value) changes[key] = n;
  }
  if (Object.keys(changes).length === 0) return;
  const res = await action.run<Record<string, PlatformSetting>>(actions.updateSettings(changes), t('pset.thresholds'));
  if (res) {
    saved.value = t('pa.done');
    await load();
  }
}

async function saveSecurity(twoFactor?: Record<string, boolean>): Promise<void> {
  saved.value = null;
  const res = await action.run<SecuritySettings>(securityActions.update(twoFactor ? { two_factor: twoFactor } : { session_hours: Number(hours.value) }), t('pset.security'));
  if (res) {
    security.value = res;
    saved.value = t('pa.done');
  }
}

onMounted(load);
</script>

<template>
  <section class="block" aria-labelledby="pset-title">
    <h2 id="pset-title">{{ t('pset.title') }}</h2>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <ErrorNotice :error="error" />
    <p v-if="saved" class="notice notice--success" role="status">{{ saved }}</p>

    <form v-if="numericKeys.length" class="card form" novalidate @submit.prevent="saveThresholds">
      <h3>{{ t('pset.thresholds') }}</h3>
      <div class="grid">
        <div v-for="key in numericKeys" :key="key" class="field">
          <label :for="`pset-${key}`">{{ label(key) }}</label>
          <input :id="`pset-${key}`" v-model="values[key]" type="number" inputmode="numeric" :min="settings[key]?.min" :max="settings[key]?.max" :disabled="!canManage" />
          <span class="hint">{{ t('pset.bounds', { min: settings[key]?.min ?? 0, max: settings[key]?.max ?? 0 }) }}</span>
        </div>
      </div>
      <button v-if="canManage" type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('pa.save') }}</button>
    </form>

    <div v-if="security" class="card form block2">
      <h3>{{ t('pset.security') }}</h3>
      <form class="row hours" novalidate @submit.prevent="saveSecurity()">
        <div class="field">
          <label for="pset-hours">{{ t('pset.sessionHours') }}</label>
          <input id="pset-hours" v-model="hours" type="number" :min="security.session_hours.min" :max="security.session_hours.max" :disabled="!canManage" />
        </div>
        <button v-if="canManage" type="submit" class="btn" :disabled="action.busy.value" data-test="pset-hours-save">{{ t('pa.save') }}</button>
      </form>
      <div class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('pset.twoFactorByRole') }}</caption>
          <thead><tr><th scope="col">{{ t('pa.role.label') }}</th><th scope="col">{{ t('pset.twoFactorByRole') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in security.roles" :key="r.code">
              <th scope="row">{{ locale === 'en' ? r.label_en : r.label_fr }}</th>
              <td>
                <label class="check">
                  <input type="checkbox" :checked="r.two_factor_required" :disabled="!canManage || r.locked || action.busy.value" :data-test="`twofa-${r.code}`" @change="saveSecurity({ [r.code]: ($event.target as HTMLInputElement).checked })" />
                  {{ r.locked ? t('pset.always') : r.two_factor_required ? t('pa.yes') : t('pa.no') }}
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<style scoped>
.block {
  margin-top: 28px;
}
.block2 {
  margin-top: 16px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.hours {
  gap: 12px;
  align-items: end;
}
</style>
