<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { BILLING_PERIODS, REGULARISATION_LEVELS, actions, type Entitlement, type ServiceOption } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import RegularisationActions from './RegularisationActions.vue';

// Fiche client, par application : montant dû (tarif de base + options), délais propres au client (essai, grâce, périodicité,
// niveau de blocage), options de service et mode régularisation de paiement. Chaque modification exige un motif.
const props = defineProps<{ tenant: string; clientName: string; app: Entitlement; serviceOptions: ServiceOption[] }>();
const emit = defineEmits<{ changed: [] }>();
const { t, locale } = useI18n();
const action = useSensitiveAction();

const terms = computed(() => props.app.terms);
const periodKey = (months: number): MessageKey => `rg.period.${months}` as MessageKey;
const form = reactive({ trial: '', grace: '', period: '', level: '', reason: '' });
const formError = ref<string | null>(null);
const saved = ref<string | null>(null);

function reset(): void {
  const x = props.app.terms;
  form.trial = x?.trial_days_overridden ? String(x.trial_days) : '';
  form.grace = x?.grace_days_overridden ? String(x.grace_days) : '';
  form.period = x?.billing_period_overridden ? String(x.billing_period_months) : '';
  form.level = x?.regularisation_level_overridden ? x.regularisation_level : '';
  form.reason = '';
}
watch(() => props.app, reset, { immediate: true });

const inState = (...states: string[]): boolean => states.includes(props.app.state);
const blocked = computed(() => props.app.state === 'suspended' && !!props.app.regularisation);
const canTrial = computed(() => props.app.paid_until === null);

function intOrNull(raw: string, max: number): number | null | undefined {
  const text = raw.trim();
  if (text === '') return null;
  return /^\d+$/.test(text) && Number(text) <= max ? Number(text) : undefined;
}

async function saveTerms(): Promise<void> {
  formError.value = null;
  saved.value = null;
  const x = props.app.terms;
  const changes: Record<string, number | string | null> = {};
  const trial = intOrNull(form.trial, 365);
  const grace = intOrNull(form.grace, 90);
  if (trial === undefined || grace === undefined) {
    formError.value = t('rg.terms.invalid');
    return;
  }
  if (canTrial.value && trial !== (x?.trial_days_overridden ? x.trial_days : null)) changes.trial_days = trial;
  if (grace !== (x?.grace_days_overridden ? x.grace_days : null)) changes.grace_days = grace;
  const period = form.period === '' ? null : Number(form.period);
  if (period !== (x?.billing_period_overridden ? x.billing_period_months : null)) changes.billing_period_months = period;
  const level = form.level === '' ? null : form.level;
  if (level !== (x?.regularisation_level_overridden ? x.regularisation_level : null)) changes.regularisation_level = level;
  if (Object.keys(changes).length === 0) {
    formError.value = t('rg.terms.noChange');
    return;
  }
  const text = form.reason.trim();
  if (text === '' || text.length > 500) {
    formError.value = t('rg.act.reasonRequired');
    return;
  }
  const res = await action.run<Entitlement>(actions.setTerms(props.tenant, props.app.app_key, changes, text), t('rg.terms.label', { app: props.app.label, client: props.clientName }));
  if (!res) return;
  saved.value = t('rg.terms.saved');
  emit('changed');
}

// --- Options de service ---
const optionForm = reactive({ key: '', price: '', reason: '' });
const optionError = ref<string | null>(null);
const available = computed(() => {
  const taken = new Set((props.app.options ?? []).map((o) => o.option_key));
  return props.serviceOptions.filter((o) => o.active && !taken.has(o.option_key) && (o.app_keys.length === 0 || o.app_keys.includes(props.app.app_key)));
});
const optionLabel = (o: { label_fr: string; label_en: string }): string => (locale.value === 'en' ? o.label_en : o.label_fr);

async function addOption(): Promise<void> {
  optionError.value = null;
  const raw = optionForm.price.trim().replace(/\s/g, '');
  if (optionForm.key === '' || (raw !== '' && !/^\d+$/.test(raw))) {
    optionError.value = t('rg.opt.invalid');
    return;
  }
  const text = optionForm.reason.trim();
  if (text === '' || text.length > 500) {
    optionError.value = t('rg.act.reasonRequired');
    return;
  }
  const res = await action.run<Entitlement>(actions.subscribeOption(props.tenant, props.app.app_key, optionForm.key, raw === '' ? null : Number(raw), text), t('rg.opt.labelAdd', { client: props.clientName }));
  if (!res) return;
  Object.assign(optionForm, { key: '', price: '', reason: '' });
  emit('changed');
}

const removing = ref<string | null>(null);
const removeReason = ref('');
async function removeOption(key: string): Promise<void> {
  const text = removeReason.value.trim();
  if (text === '' || text.length > 500) {
    optionError.value = t('rg.act.reasonRequired');
    return;
  }
  optionError.value = null;
  const res = await action.run<Entitlement>(actions.unsubscribeOption(props.tenant, props.app.app_key, key, text), t('rg.opt.labelRemove', { client: props.clientName }));
  if (!res) return;
  removing.value = null;
  removeReason.value = '';
  emit('changed');
}

// --- Régularisation ---
const lockKind = ref<'lock' | 'unlock' | 'delay' | null>(null);
function done(): void {
  lockKind.value = null;
  emit('changed');
}
const toggleKind = (k: 'lock' | 'unlock' | 'delay'): void => {
  lockKind.value = lockKind.value === k ? null : k;
};
const id = computed(() => `bp-${props.app.id}`);
</script>

<template>
  <div class="billing stack">
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <section :aria-labelledby="`${id}-due`">
      <h4 :id="`${id}-due`">{{ t('rg.due.title') }}</h4>
      <dl class="facts">
        <div><dt>{{ t('rg.due.base') }}</dt><dd>{{ formatXaf(app.monthly_price_xaf, locale) }}</dd></div>
        <div v-for="o in app.options ?? []" :key="o.option_key"><dt>{{ optionLabel(o) }}</dt><dd>{{ formatXaf(o.price_xaf, locale) }}</dd></div>
        <div><dt><strong>{{ t('rg.due.total') }}</strong></dt><dd><strong>{{ formatXaf(app.monthly_total_xaf ?? app.monthly_price_xaf, locale) }}</strong></dd></div>
        <div v-if="terms"><dt>{{ t('rg.due.period') }}</dt><dd>{{ t(periodKey(terms.billing_period_months)) }}</dd></div>
        <div v-if="app.promise_until"><dt>{{ t('rg.due.promise') }}</dt><dd>{{ formatDate(app.promise_until, locale) }}</dd></div>
      </dl>
      <p v-if="app.regularisation" class="notice notice--warning" role="status">
        {{ t('rg.due.blocked', { level: t(`rg.level.${app.regularisation.level}`), days: app.regularisation.days_elapsed }) }}
      </p>
    </section>

    <section :aria-labelledby="`${id}-reg`">
      <h4 :id="`${id}-reg`">{{ t('rg.reg.title') }}</h4>
      <div class="row" role="group" :aria-label="t('rg.reg.actionsFor', { app: app.label })">
        <button v-if="inState('trial', 'active', 'past_due', 'suspended')" type="button" class="btn" :aria-expanded="lockKind === 'lock'" :aria-controls="`${id}-form`" @click="toggleKind('lock')">{{ t('rg.reg.lock') }}</button>
        <button v-if="blocked" type="button" class="btn" :aria-expanded="lockKind === 'unlock'" :aria-controls="`${id}-form`" @click="toggleKind('unlock')">{{ t('rg.reg.unlock') }}</button>
        <button v-if="inState('past_due') || blocked" type="button" class="btn" :aria-expanded="lockKind === 'delay'" :aria-controls="`${id}-form`" @click="toggleKind('delay')">{{ t('rg.reg.delay') }}</button>
      </div>
      <div v-if="lockKind" :id="`${id}-form`">
        <RegularisationActions :tenant="tenant" :client-name="clientName" :app-key="app.app_key" :app-label="app.label" :kind="lockKind" :id-prefix="`${id}-act`" @done="done" @cancel="lockKind = null" />
      </div>
    </section>

    <section :aria-labelledby="`${id}-terms`">
      <h4 :id="`${id}-terms`">{{ t('rg.terms.title') }}</h4>
      <p class="muted small">{{ t('rg.terms.lede') }}</p>
      <form class="panel" novalidate @submit.prevent="saveTerms">
        <div class="cols">
          <div v-if="canTrial" class="field">
            <label :for="`${id}-trial`">{{ t('rg.terms.trial') }}</label>
            <input :id="`${id}-trial`" v-model="form.trial" type="text" inputmode="numeric" autocomplete="off" :placeholder="terms ? String(terms.trial_days) : ''" :aria-describedby="`${id}-trial-hint`" />
            <span :id="`${id}-trial-hint`" class="hint">{{ t('rg.terms.catalogHint') }}</span>
          </div>
          <div class="field">
            <label :for="`${id}-grace`">{{ t('rg.terms.grace') }}</label>
            <input :id="`${id}-grace`" v-model="form.grace" type="text" inputmode="numeric" autocomplete="off" :placeholder="terms ? String(terms.grace_days) : ''" :aria-describedby="`${id}-grace-hint`" />
            <span :id="`${id}-grace-hint`" class="hint">{{ t('rg.terms.catalogHint') }}</span>
          </div>
          <div class="field">
            <label :for="`${id}-period`">{{ t('rg.terms.period') }}</label>
            <select :id="`${id}-period`" v-model="form.period">
              <option value="">{{ t('rg.terms.periodDefault') }}</option>
              <option v-for="p in BILLING_PERIODS" :key="p" :value="String(p)">{{ t(`rg.period.${p}`) }}</option>
            </select>
          </div>
          <div class="field">
            <label :for="`${id}-level`">{{ t('rg.terms.level') }}</label>
            <select :id="`${id}-level`" v-model="form.level">
              <option value="">{{ t('rg.terms.levelDefault') }}</option>
              <option v-for="l in REGULARISATION_LEVELS" :key="l" :value="l">{{ t(`rg.level.${l}`) }}</option>
            </select>
            <span class="hint">{{ t('rg.terms.levelHint') }}</span>
          </div>
        </div>
        <div class="field">
          <label :for="`${id}-treason`">{{ t('rg.act.reason') }}</label>
          <textarea :id="`${id}-treason`" v-model="form.reason" rows="2" maxlength="500" />
        </div>
        <p v-if="formError" class="error-line" role="alert">{{ formError }}</p>
        <p v-if="saved" class="notice notice--success" role="status">{{ saved }}</p>
        <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('rg.terms.save') }}</button>
      </form>
    </section>

    <section :aria-labelledby="`${id}-opt`">
      <h4 :id="`${id}-opt`">{{ t('rg.opt.title') }}</h4>
      <p v-if="(app.options ?? []).length === 0" class="muted">{{ t('rg.opt.none') }}</p>
      <ul v-else class="list">
        <li v-for="o in app.options" :key="o.option_key" class="row row--between">
          <span>{{ optionLabel(o) }} · {{ formatXaf(o.price_xaf, locale) }}<span v-if="o.price_overridden" class="tag tag--accent over">{{ t('plat.cli.overridden') }}</span></span>
          <button type="button" class="btn btn--quiet" :aria-expanded="removing === o.option_key" @click="removing = removing === o.option_key ? null : o.option_key">{{ t('rg.opt.remove') }}</button>
        </li>
      </ul>
      <form v-if="removing" class="panel" novalidate @submit.prevent="removeOption(removing)">
        <div class="field">
          <label :for="`${id}-rmreason`">{{ t('rg.act.reason') }}</label>
          <textarea :id="`${id}-rmreason`" v-model="removeReason" rows="2" maxlength="500" />
        </div>
        <button type="submit" class="btn" :disabled="action.busy.value">{{ t('rg.opt.confirmRemove') }}</button>
      </form>
      <form v-if="available.length > 0" class="panel" novalidate @submit.prevent="addOption">
        <div class="cols">
          <div class="field">
            <label :for="`${id}-optkey`">{{ t('rg.opt.add') }}</label>
            <select :id="`${id}-optkey`" v-model="optionForm.key">
              <option value="">{{ t('rg.opt.choose') }}</option>
              <option v-for="o in available" :key="o.option_key" :value="o.option_key">{{ optionLabel(o) }} ({{ formatXaf(o.monthly_price_xaf, locale) }})</option>
            </select>
          </div>
          <div class="field">
            <label :for="`${id}-optprice`">{{ t('rg.opt.price') }}</label>
            <input :id="`${id}-optprice`" v-model="optionForm.price" type="text" inputmode="numeric" autocomplete="off" :aria-describedby="`${id}-optprice-hint`" />
            <span :id="`${id}-optprice-hint`" class="hint">{{ t('rg.opt.priceHint') }}</span>
          </div>
        </div>
        <div class="field">
          <label :for="`${id}-optreason`">{{ t('rg.act.reason') }}</label>
          <textarea :id="`${id}-optreason`" v-model="optionForm.reason" rows="2" maxlength="500" />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('rg.opt.subscribe') }}</button>
      </form>
      <p v-if="optionError" class="error-line" role="alert">{{ optionError }}</p>
    </section>
  </div>
</template>

<style scoped>
.billing {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
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
  margin-top: 12px;
  padding-top: 12px;
  max-width: 560px;
}
.list {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  display: grid;
  gap: 8px;
}
.small {
  font-size: 13px;
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
.over {
  margin-left: 8px;
}
h4 {
  margin: 0 0 8px;
}
</style>
