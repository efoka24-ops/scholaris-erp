<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { APP_STATUSES, actions, fetchPlatformCatalog, type CatalogApplication } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { appStatusKey, appStatusTone, targetKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';

// Catalogue des applications (administrateur) : tarif, statut, version, essai, délai de grâce — design : Dashboard.html, Fondations.html.
const { t, locale } = useI18n();
const items = ref<CatalogApplication[]>([]);
const loaded = ref(false);
const loadError = ref<unknown>(null);
const editing = ref<string | null>(null);
const form = reactive({ label: '', status: 'available', version: '', monthly_price_xaf: '', trial_days: '', grace_days: '' });
type FormField = keyof typeof form;
const errors = ref<Partial<Record<FormField, string>>>({});
const info = ref<string | null>(null);
const action = useSensitiveAction();

async function load(): Promise<void> {
  loadError.value = null;
  try {
    items.value = await fetchPlatformCatalog();
  } catch (e) {
    loadError.value = e;
  } finally {
    loaded.value = true;
  }
}

function edit(app: CatalogApplication): void {
  if (editing.value === app.key) {
    editing.value = null;
    return;
  }
  editing.value = app.key;
  form.label = app.label;
  form.status = app.status;
  form.version = app.version;
  form.monthly_price_xaf = String(app.monthly_price_xaf);
  form.trial_days = String(app.trial_days);
  form.grace_days = String(app.grace_days);
  errors.value = {};
  info.value = null;
  action.dismiss();
}

function int(raw: string, max: number): number | null {
  const v = raw.trim().replace(/\s/g, '');
  return /^\d+$/.test(v) && Number(v) <= max ? Number(v) : null;
}

async function save(app: CatalogApplication): Promise<void> {
  const e: Partial<Record<FormField, string>> = {};
  const changes: Record<string, unknown> = {};
  const label = form.label.replace(/\s+/g, ' ').trim();
  if (label.length < 2 || label.length > 120 || /[<>]/.test(label)) e.label = t('plat.cat.err.label');
  else if (label !== app.label) changes.label = label;
  if (form.status !== app.status) changes.status = form.status;
  const version = form.version.trim();
  if (!/^[0-9A-Za-z.\-+]{1,20}$/.test(version)) e.version = t('plat.cat.err.version');
  else if (version !== app.version) changes.version = version;
  const numbers: Array<[FormField, number, string]> = [
    ['monthly_price_xaf', 100_000_000, t('plat.cat.err.price')],
    ['trial_days', 365, t('plat.cat.err.trial')],
    ['grace_days', 90, t('plat.cat.err.grace')],
  ];
  for (const [field, max, message] of numbers) {
    const value = int(form[field], max);
    if (value === null) e[field] = message;
    else if (value !== app[field as 'monthly_price_xaf' | 'trial_days' | 'grace_days']) changes[field] = value;
  }
  errors.value = e;
  info.value = null;
  if (Object.keys(e).length > 0) return;
  if (Object.keys(changes).length === 0) {
    info.value = t('plat.cat.noChange');
    return;
  }
  const res = await action.run<CatalogApplication>(actions.updateCatalog(app.key, changes), t('plat.act.catalog', { app: app.label }));
  if (!res) return;
  editing.value = null;
  info.value = t('plat.cat.saved');
  await load();
}

const described = (f: FormField): string | undefined => (errors.value[f] ? `cat-${f}-err` : undefined);

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('plat.cat.title') }}</h1>
      <p class="lede">{{ t('plat.cat.lede') }}</p>
    </header>
    <ErrorNotice :error="loadError" />
    <ReplayNotice @done="load" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="!loadError && items.length === 0" class="card muted">{{ t('plat.cat.empty') }}</p>
    <ul v-else class="apps">
      <li v-for="app in items" :key="app.key" class="card">
        <div class="row row--between">
          <div>
            <h2 class="app__name">{{ app.label }} <code class="key">{{ app.key }}</code></h2>
            <p class="muted small">{{ t(targetKey(app.target)) }} · {{ t('pub.catalog.version', { version: app.version }) }}</p>
          </div>
          <span class="tag" :class="appStatusTone(app.status) ? `tag--${appStatusTone(app.status)}` : ''">{{ t(appStatusKey(app.status)) }}</span>
        </div>
        <dl class="facts">
          <div><dt>{{ t('plat.col.price') }}</dt><dd>{{ formatXaf(app.monthly_price_xaf, locale) }}</dd></div>
          <div><dt>{{ t('plat.col.trialDays') }}</dt><dd>{{ app.trial_days }}</dd></div>
          <div><dt>{{ t('plat.col.graceDays') }}</dt><dd>{{ app.grace_days }}</dd></div>
        </dl>
        <p v-if="app.required_modules.length" class="muted small">{{ t('plat.cat.modules', { modules: app.required_modules.join(', ') }) }}</p>
        <button type="button" class="btn" :aria-expanded="editing === app.key" :aria-controls="`cat-form-${app.key}`" :aria-label="t('plat.cat.editNamed', { label: app.label })" @click="edit(app)">{{ t('plat.cat.edit') }}</button>

        <form v-if="editing === app.key" :id="`cat-form-${app.key}`" class="panel" novalidate @submit.prevent="save(app)">
          <div class="cols">
            <div class="field">
              <label for="cat-label">{{ t('plat.cat.label') }}</label>
              <input id="cat-label" v-model="form.label" type="text" maxlength="120" :aria-invalid="!!errors.label" :aria-describedby="described('label')" />
              <span v-if="errors.label" id="cat-label-err" class="error">{{ errors.label }}</span>
            </div>
            <div class="field">
              <label for="cat-status">{{ t('plat.cat.status') }}</label>
              <select id="cat-status" v-model="form.status">
                <option v-for="s in APP_STATUSES" :key="s" :value="s">{{ t(appStatusKey(s)) }}</option>
              </select>
            </div>
            <div class="field">
              <label for="cat-version">{{ t('plat.cat.version') }}</label>
              <input id="cat-version" v-model="form.version" type="text" maxlength="20" :aria-invalid="!!errors.version" :aria-describedby="described('version')" />
              <span v-if="errors.version" id="cat-version-err" class="error">{{ errors.version }}</span>
            </div>
            <div class="field">
              <label for="cat-price">{{ t('plat.cat.price') }}</label>
              <input id="cat-price" v-model="form.monthly_price_xaf" type="text" inputmode="numeric" :aria-invalid="!!errors.monthly_price_xaf" :aria-describedby="described('monthly_price_xaf')" />
              <span v-if="errors.monthly_price_xaf" id="cat-monthly_price_xaf-err" class="error">{{ errors.monthly_price_xaf }}</span>
            </div>
            <div class="field">
              <label for="cat-trial">{{ t('plat.cat.trialDays') }}</label>
              <input id="cat-trial" v-model="form.trial_days" type="text" inputmode="numeric" :aria-invalid="!!errors.trial_days" :aria-describedby="described('trial_days')" />
              <span v-if="errors.trial_days" id="cat-trial_days-err" class="error">{{ errors.trial_days }}</span>
            </div>
            <div class="field">
              <label for="cat-grace">{{ t('plat.cat.graceDays') }}</label>
              <input id="cat-grace" v-model="form.grace_days" type="text" inputmode="numeric" :aria-invalid="!!errors.grace_days" :aria-describedby="described('grace_days')" />
              <span v-if="errors.grace_days" id="cat-grace_days-err" class="error">{{ errors.grace_days }}</span>
            </div>
          </div>
          <div class="row">
            <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('plat.save') }}</button>
            <button type="button" class="btn btn--quiet" @click="editing = null">{{ t('plat.cancel') }}</button>
          </div>
        </form>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.apps {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.app__name {
  margin: 0;
  font-size: 20px;
}
.key {
  font-size: 13px;
  font-weight: 400;
  color: var(--muted);
}
.small {
  font-size: 13px;
  margin: 2px 0 8px;
}
.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 32px;
  margin: 12px 0;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.facts dd {
  margin: 2px 0 0;
  font-weight: 700;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
}
.cols {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: 0 16px;
}
</style>
