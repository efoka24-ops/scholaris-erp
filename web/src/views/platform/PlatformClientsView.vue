<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { APP_TARGETS, CLIENT_STATES, actions, fetchClients, normalizePhone, type AppTarget, type ClientState, type Tenant } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { stateKey, stateTone, targetKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';

// Liste des clients (filtre par état ; les archivés à part) et création directe d'un client, sans demande d'accès.
// Aucune donnée métier — design : Dashboard.html (tableau), Fondations.html (formulaires).
const { t, locale } = useI18n();
const router = useRouter();
const items = ref<Tenant[]>([]);
const loaded = ref(false);
const error = ref<unknown>(null);
const query = ref('');
const state = ref<ClientState | 'current'>('current');
const creating = ref(false);
const form = reactive({ name: '', contact_name: '', contact_email: '', contact_phone: '', city: '', region: '', establishment_type: '' as AppTarget | '' });
const errors = ref<Record<string, string>>({});
const action = useSensitiveAction();
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const clientStateKey = (s: string): MessageKey => `pa.cli.state.${s}` as MessageKey;
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q === '' ? items.value : items.value.filter((c) => c.name.toLowerCase().includes(q) || c.id.toLowerCase().includes(q) || (c.city ?? '').toLowerCase().includes(q));
});

async function load(): Promise<void> {
  error.value = null;
  try {
    items.value = await fetchClients(state.value === 'current' ? null : state.value);
  } catch (e) {
    error.value = e;
  } finally {
    loaded.value = true;
  }
}

async function create(): Promise<void> {
  const e: Record<string, string> = {};
  const email = form.contact_email.trim();
  const phone = form.contact_phone.trim() === '' ? null : normalizePhone(form.contact_phone);
  if (form.name.trim().length < 3) e.name = t('pa.cli.nameInvalid');
  if (form.contact_name.trim().length < 2) e.contact_name = t('pa.required');
  if (email !== '' && !EMAIL.test(email)) e.contact_email = t('pa.emailInvalid');
  if (form.contact_phone.trim() !== '' && phone === null) e.contact_phone = t('pa.phoneInvalid');
  if (email === '' && form.contact_phone.trim() === '') e.contact_email = t('pa.cli.contactRequired');
  errors.value = e;
  if (Object.keys(e).length > 0) return;
  const res = await action.run<Tenant>(
    actions.createClient({
      name: form.name.trim(),
      contact_name: form.contact_name.trim(),
      ...(email ? { contact_email: email } : {}),
      ...(phone ? { contact_phone: phone } : {}),
      ...(form.city.trim() ? { city: form.city.trim() } : {}),
      ...(form.region.trim() ? { region: form.region.trim() } : {}),
      ...(form.establishment_type ? { establishment_type: form.establishment_type } : {}),
    }),
    t('pa.cli.create'),
  );
  if (!res) return;
  await router.push({ name: 'platform-client', params: { tenant: res.id } });
}

watch(state, load);
onMounted(load);
const filters: Array<ClientState | 'current'> = ['current', ...CLIENT_STATES];
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
        <h1>{{ t('plat.cli.title') }}</h1>
        <p class="lede">{{ t('plat.cli.lede') }}</p>
      </div>
      <button type="button" class="btn btn--primary" :aria-expanded="creating" aria-controls="cli-create" @click="creating = !creating">{{ t('pa.cli.create') }}</button>
    </header>
    <ReplayNotice @done="load" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <form v-if="creating" id="cli-create" class="card form" novalidate @submit.prevent="create">
      <h2>{{ t('pa.cli.create') }}</h2>
      <p class="muted">{{ t('pa.cli.createHint') }}</p>
      <div class="grid2">
        <div class="field">
          <label for="cc-name">{{ t('pa.cli.name') }}</label>
          <input id="cc-name" v-model="form.name" type="text" maxlength="160" :aria-invalid="!!errors.name" />
          <span v-if="errors.name" class="error-line">{{ errors.name }}</span>
        </div>
        <div class="field">
          <label for="cc-contact">{{ t('pa.cli.contact') }}</label>
          <input id="cc-contact" v-model="form.contact_name" type="text" maxlength="160" :aria-invalid="!!errors.contact_name" />
          <span v-if="errors.contact_name" class="error-line">{{ errors.contact_name }}</span>
        </div>
        <div class="field">
          <label for="cc-email">{{ t('pa.email') }}</label>
          <input id="cc-email" v-model="form.contact_email" type="email" maxlength="190" :aria-invalid="!!errors.contact_email" />
          <span v-if="errors.contact_email" class="error-line">{{ errors.contact_email }}</span>
        </div>
        <div class="field">
          <label for="cc-phone">{{ t('pa.phone') }}</label>
          <input id="cc-phone" v-model="form.contact_phone" type="tel" maxlength="25" :aria-invalid="!!errors.contact_phone" />
          <span v-if="errors.contact_phone" class="error-line">{{ errors.contact_phone }}</span>
        </div>
        <div class="field"><label for="cc-city">{{ t('plat.col.city') }}</label><input id="cc-city" v-model="form.city" type="text" maxlength="80" /></div>
        <div class="field"><label for="cc-region">{{ t('pa.region') }}</label><input id="cc-region" v-model="form.region" type="text" maxlength="80" /></div>
        <div class="field">
          <label for="cc-type">{{ t('pa.cli.firstEstablishment') }}</label>
          <select id="cc-type" v-model="form.establishment_type">
            <option value="">{{ t('pa.cli.noEstablishment') }}</option>
            <option v-for="tp in APP_TARGETS" :key="tp" :value="tp">{{ t(targetKey(tp)) }}</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('pa.cli.create') }}</button>
    </form>

    <fieldset class="filters">
      <legend class="sr-only">{{ t('pa.cli.filter') }}</legend>
      <label v-for="f in filters" :key="f" class="chip" :class="{ 'chip--on': state === f }">
        <input v-model="state" type="radio" name="cli-state" :value="f" class="sr-only" />
        {{ f === 'current' ? t('pa.cli.current') : t(clientStateKey(f)) }}
      </label>
    </fieldset>

    <ErrorNotice :error="error" />
    <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="!error && items.length === 0" class="card muted">{{ t('plat.cli.empty') }}</p>
    <template v-else-if="items.length > 0">
      <div class="field search">
        <label for="cli-search">{{ t('plat.cli.search') }}</label>
        <input id="cli-search" v-model="query" type="search" autocomplete="off" />
      </div>
      <p v-if="filtered.length === 0" class="card muted" role="status">{{ t('plat.cli.noMatch') }}</p>
      <div v-else class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('plat.cli.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('plat.col.client') }}</th>
              <th scope="col">{{ t('plat.col.status') }}</th>
              <th scope="col">{{ t('plat.col.city') }}</th>
              <th scope="col">{{ t('plat.col.created') }}</th>
              <th scope="col">{{ t('plat.col.apps') }}</th>
              <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in filtered" :key="c.id">
              <th scope="row">{{ c.name }}<br /><code class="id">{{ c.id }}</code></th>
              <td><span class="tag" :class="(c.state ?? 'active') === 'active' ? 'tag--success' : 'tag--warning'">{{ t(clientStateKey(c.state ?? 'active')) }}</span></td>
              <td>{{ c.city ?? '—' }}</td>
              <td>{{ formatDate(c.created_at, locale) }}</td>
              <td class="wrap">
                <span v-if="c.applications.length === 0" class="muted">{{ t('plat.cli.noApps') }}</span>
                <span v-for="a in c.applications" :key="a.id" class="app">{{ a.label }} <span class="tag" :class="stateTone(a.state) ? `tag--${stateTone(a.state)}` : ''">{{ t(stateKey(a.state)) }}</span></span>
              </td>
              <td><router-link class="btn btn--quiet" :to="{ name: 'platform-client', params: { tenant: c.id } }" :aria-label="t('plat.detailsNamed', { name: c.name })">{{ t('plat.details') }}</router-link></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.search {
  max-width: 420px;
}
.id {
  font-size: 12px;
  font-weight: 400;
  color: var(--muted);
}
.wrap {
  white-space: normal;
}
.app {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 2px 12px 2px 0;
}
.form {
  margin-bottom: 20px;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.filters {
  border: 0;
  padding: 0;
  margin: 0 0 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.chip {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
.chip--on {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.chip:focus-within {
  outline: 3px solid var(--ink);
  outline-offset: 2px;
}
.error-line {
  display: block;
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
