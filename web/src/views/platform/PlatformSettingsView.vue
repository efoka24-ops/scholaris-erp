<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchPlatformSettings, type PlatformSetting } from '@/api/platform';
import { useI18n } from '@/i18n';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';
import ContactSettingsPanel from './ContactSettingsPanel.vue';

// Réglages de la plateforme : durée de validité des codes d'accès créés depuis le back-office (5 minutes à 7 jours), niveau de blocage
// par défaut des impayés, vérification des paiements déclarés (expiration, tolérance, paiement partiel), contact et numéros de paiement.
const { t } = useI18n();
const action = useSensitiveAction();
const KEY = 'access_code_ttl_minutes';
const setting = ref<PlatformSetting | null>(null);
const LEVEL_KEY = 'regularisation_default_level';
const level = ref<'0' | '1'>('0');
const levelSaved = ref(false);
const minutes = ref('');
const invalid = ref(false);
const info = ref('');
const loadError = ref<unknown>(null);

// Vérification des paiements déclarés.
const VERIFY_KEYS = ['regularisation_request_expiry_days', 'regularisation_amount_tolerance_xaf', 'regularisation_partial_min_xaf', 'regularisation_partial_mode'] as const;
type VerifyKey = (typeof VERIFY_KEYS)[number];
const allSettings = ref<Record<string, PlatformSetting>>({});
const verify = ref<Record<VerifyKey, string>>({
  regularisation_request_expiry_days: '',
  regularisation_amount_tolerance_xaf: '',
  regularisation_partial_min_xaf: '',
  regularisation_partial_mode: '0',
});
const verifyErrors = ref<Partial<Record<VerifyKey, string>>>({});
const verifySaved = ref(false);

async function load(): Promise<void> {
  loadError.value = null;
  try {
    const all = await fetchPlatformSettings();
    setting.value = all[KEY] ?? null;
    minutes.value = setting.value ? String(setting.value.value) : '';
    level.value = all[LEVEL_KEY]?.value === 1 ? '1' : '0';
    allSettings.value = all;
    for (const key of VERIFY_KEYS) verify.value[key] = String(all[key]?.value ?? '');
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

async function saveLevel(): Promise<void> {
  levelSaved.value = false;
  const res = await action.run<{ data: Record<string, PlatformSetting> }>(actions.updateSettings({ [LEVEL_KEY]: Number(level.value) }), t('rg.set.title'));
  if (res) levelSaved.value = true;
}

async function saveVerify(): Promise<void> {
  verifySaved.value = false;
  const errs: Partial<Record<VerifyKey, string>> = {};
  const values: Record<string, number> = {};
  for (const key of VERIFY_KEYS) {
    const def = allSettings.value[key];
    const raw = String(verify.value[key]).trim();
    const n = Number(raw);
    if (!def || raw === '' || !Number.isInteger(n) || n < def.min || n > def.max) {
      errs[key] = t('rg.set.verifyInvalid', { min: def?.min ?? 0, max: def?.max ?? 0 });
    } else {
      values[key] = n;
    }
  }
  verifyErrors.value = errs;
  if (Object.keys(errs).length > 0) return;
  const res = await action.run<{ data: Record<string, PlatformSetting> }>(actions.updateSettings(values), t('rg.set.verifyTitle'));
  if (res) verifySaved.value = true;
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

    <form v-if="setting" class="card stack" novalidate @submit.prevent="saveLevel">
      <h2>{{ t('rg.set.title') }}</h2>
      <p class="muted">{{ t('rg.set.lede') }}</p>
      <div class="field">
        <label for="reg-level">{{ t('rg.set.label') }}</label>
        <select id="reg-level" v-model="level">
          <option value="0">{{ t('rg.level.read_only') }}</option>
          <option value="1">{{ t('rg.level.locked') }}</option>
        </select>
        <span class="hint">{{ t('rg.act.levelHint') }}</span>
      </div>
      <p v-if="levelSaved" class="notice notice--success" role="status">{{ t('rg.set.saved') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('admin.save') }}</button>
    </form>
  
    <form v-if="setting" class="card stack" novalidate aria-labelledby="rg-verify-title" @submit.prevent="saveVerify">
      <h2 id="rg-verify-title">{{ t('rg.set.verifyTitle') }}</h2>
      <p class="muted">{{ t('rg.set.verifyLede') }}</p>
      <div class="field">
        <label for="rg-v-expiry">{{ t('rg.set.expiry') }}</label>
        <input id="rg-v-expiry" v-model="verify.regularisation_request_expiry_days" type="number" inputmode="numeric" :aria-invalid="!!verifyErrors.regularisation_request_expiry_days" />
        <span v-if="verifyErrors.regularisation_request_expiry_days" class="error">{{ verifyErrors.regularisation_request_expiry_days }}</span>
      </div>
      <div class="field">
        <label for="rg-v-tol">{{ t('rg.set.tolerance') }}</label>
        <input id="rg-v-tol" v-model="verify.regularisation_amount_tolerance_xaf" type="number" inputmode="numeric" :aria-invalid="!!verifyErrors.regularisation_amount_tolerance_xaf" aria-describedby="rg-v-tol-hint" />
        <span id="rg-v-tol-hint" class="hint">{{ t('rg.set.toleranceHint') }}</span>
        <span v-if="verifyErrors.regularisation_amount_tolerance_xaf" class="error">{{ verifyErrors.regularisation_amount_tolerance_xaf }}</span>
      </div>
      <div class="field">
        <label for="rg-v-min">{{ t('rg.set.partialMin') }}</label>
        <input id="rg-v-min" v-model="verify.regularisation_partial_min_xaf" type="number" inputmode="numeric" :aria-invalid="!!verifyErrors.regularisation_partial_min_xaf" />
        <span v-if="verifyErrors.regularisation_partial_min_xaf" class="error">{{ verifyErrors.regularisation_partial_min_xaf }}</span>
      </div>
      <div class="field">
        <label for="rg-v-mode">{{ t('rg.set.partialMode') }}</label>
        <select id="rg-v-mode" v-model="verify.regularisation_partial_mode" aria-describedby="rg-v-mode-hint">
          <option value="0">{{ t('rg.set.partialMode0') }}</option>
          <option value="1">{{ t('rg.set.partialMode1') }}</option>
        </select>
        <span id="rg-v-mode-hint" class="hint">{{ t('rg.set.partialHint') }}</span>
      </div>
      <p v-if="verifySaved" class="notice notice--success" role="status">{{ t('rg.set.verifySaved') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('admin.save') }}</button>
    </form>

    <ContactSettingsPanel />
  </section>
</template>
