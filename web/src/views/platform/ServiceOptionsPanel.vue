<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchPlatformCatalog, fetchServiceOptions, type CatalogApplication, type ServiceOption } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Catalogue des options de service (SMS, portail parents, import…) : clé, libellés fr/en, prix mensuel par défaut et applications
// concernées. Une option déjà souscrite se désactive mais ne se supprime pas.
const { t, locale } = useI18n();
const action = useSensitiveAction();
const options = ref<ServiceOption[]>([]);
const apps = ref<CatalogApplication[]>([]);
const loadError = ref<unknown>(null);
const editing = ref<string | null>(null);
const creating = ref(false);
const form = reactive({ key: '', labelFr: '', labelEn: '', price: '', apps: [] as string[] });
const formError = ref<string | null>(null);
const label = (o: ServiceOption): string => (locale.value === 'en' ? o.label_en : o.label_fr);
const appLabels = computed(() => Object.fromEntries(apps.value.map((a) => [a.key, a.label])));

async function load(): Promise<void> {
  loadError.value = null;
  try {
    options.value = await fetchServiceOptions();
    apps.value = await fetchPlatformCatalog();
  } catch (e) {
    loadError.value = e;
  }
}

function startCreate(): void {
  creating.value = !creating.value;
  editing.value = null;
  Object.assign(form, { key: '', labelFr: '', labelEn: '', price: '', apps: [] });
  formError.value = null;
}

function startEdit(o: ServiceOption): void {
  editing.value = editing.value === o.option_key ? null : o.option_key;
  creating.value = false;
  Object.assign(form, { key: o.option_key, labelFr: o.label_fr, labelEn: o.label_en, price: String(o.monthly_price_xaf), apps: [...o.app_keys] });
  formError.value = null;
}

function parsed(): { price: number } | null {
  const raw = form.price.trim().replace(/\s/g, '');
  const keyOk = creating.value ? /^[a-z][a-z0-9_]{1,29}$/.test(form.key) : true;
  if (!keyOk || form.labelFr.trim().length < 2 || form.labelEn.trim().length < 2 || !/^\d+$/.test(raw) || Number(raw) > 100_000_000) return null;
  return { price: Number(raw) };
}

async function save(): Promise<void> {
  formError.value = null;
  const ok = parsed();
  if (!ok) {
    formError.value = t('rg.svc.invalid');
    return;
  }
  const body = { label_fr: form.labelFr.trim(), label_en: form.labelEn.trim(), monthly_price_xaf: ok.price, app_keys: [...form.apps] };
  const res = creating.value
    ? await action.run<ServiceOption>(actions.createServiceOption({ option_key: form.key, ...body }), t('rg.svc.label.create'))
    : await action.run<ServiceOption>(actions.updateServiceOption(editing.value as string, body), t('rg.svc.label.update'));
  if (!res) return;
  creating.value = false;
  editing.value = null;
  await load();
}

async function toggle(o: ServiceOption): Promise<void> {
  if (await action.run<ServiceOption>(actions.updateServiceOption(o.option_key, { active: !o.active }), t('rg.svc.label.update'))) await load();
}

async function remove(o: ServiceOption): Promise<void> {
  if (await action.run<{ deleted: boolean }>(actions.deleteServiceOption(o.option_key), t('rg.svc.label.delete'))) await load();
}

onMounted(load);
</script>

<template>
  <section class="card block" aria-labelledby="svc-title">
    <div class="row row--between">
      <h2 id="svc-title">{{ t('rg.svc.title') }}</h2>
      <button type="button" class="btn" :aria-expanded="creating" aria-controls="svc-form" @click="startCreate">{{ t('rg.svc.add') }}</button>
    </div>
    <p class="muted">{{ t('rg.svc.lede') }}</p>
    <ErrorNotice :error="loadError" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <p v-if="options.length === 0 && !loadError" class="muted">{{ t('rg.svc.empty') }}</p>
    <ul class="list">
      <li v-for="o in options" :key="o.option_key" class="item">
        <div class="row row--between">
          <div>
            <strong>{{ label(o) }}</strong> <code>{{ o.option_key }}</code>
            <span v-if="!o.active" class="tag">{{ t('rg.svc.inactive') }}</span>
            <p class="muted small">
              {{ formatXaf(o.monthly_price_xaf, locale) }} · {{ o.app_keys.length === 0 ? t('rg.svc.appsAll') : o.app_keys.map((k) => appLabels[k] ?? k).join(', ') }} · {{ t('rg.svc.subscribers', { count: o.subscribers }) }}
            </p>
          </div>
          <div class="row" role="group" :aria-label="label(o)">
            <button type="button" class="btn btn--quiet" :aria-expanded="editing === o.option_key" aria-controls="svc-form" @click="startEdit(o)">{{ t('rg.svc.edit') }}</button>
            <button type="button" class="btn btn--quiet" @click="toggle(o)">{{ o.active ? t('rg.svc.deactivate') : t('rg.svc.activate') }}</button>
            <button v-if="o.subscribers === 0" type="button" class="btn btn--quiet" @click="remove(o)">{{ t('rg.svc.delete') }}</button>
          </div>
        </div>
      </li>
    </ul>

    <form v-if="creating || editing" id="svc-form" class="panel" novalidate @submit.prevent="save">
      <div class="cols">
        <div v-if="creating" class="field">
          <label for="svc-key">{{ t('rg.svc.key') }}</label>
          <input id="svc-key" v-model="form.key" type="text" maxlength="30" autocomplete="off" />
        </div>
        <div class="field">
          <label for="svc-fr">{{ t('rg.svc.labelFr') }}</label>
          <input id="svc-fr" v-model="form.labelFr" type="text" maxlength="120" />
        </div>
        <div class="field">
          <label for="svc-en">{{ t('rg.svc.labelEn') }}</label>
          <input id="svc-en" v-model="form.labelEn" type="text" maxlength="120" />
        </div>
        <div class="field">
          <label for="svc-price">{{ t('rg.svc.price') }}</label>
          <input id="svc-price" v-model="form.price" type="text" inputmode="numeric" autocomplete="off" />
        </div>
      </div>
      <fieldset class="apps">
        <legend>{{ t('rg.svc.apps') }}</legend>
        <p class="hint">{{ t('rg.svc.appsHint') }}</p>
        <label v-for="a in apps" :key="a.key" class="chip">
          <input v-model="form.apps" type="checkbox" :value="a.key" />
          <span>{{ a.label }}</span>
        </label>
      </fieldset>
      <p v-if="formError" class="error-line" role="alert">{{ formError }}</p>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ creating ? t('rg.svc.add') : t('rg.svc.save') }}</button>
    </form>
  </section>
</template>

<style scoped>
.block {
  margin-top: 28px;
}
.list {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  display: grid;
  gap: 8px;
}
.item {
  border-top: 1px solid var(--border);
  padding-top: 8px;
}
.small {
  font-size: 13px;
  margin: 2px 0 0;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 12px;
  padding-top: 12px;
  max-width: 640px;
}
.apps {
  border: 0;
  padding: 0;
  margin: 0 0 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.apps legend {
  font-weight: 700;
  font-size: 13px;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: var(--tap);
  padding: 0 12px;
  border: 1px solid var(--border);
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
