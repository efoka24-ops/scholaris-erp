<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  DECLARATION_STATES,
  actions,
  fetchDeclarations,
  fetchDeclarationsCsv,
  type DeclarationState,
  type MobileMoneyChannel,
  type RegularisationDeclaration,
} from '@/api/platform';
import { saveBlob } from '@/api/exports';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { channelKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Paiements déclarés par les établissements, à vérifier par le super admin : il constate le paiement sur son relevé puis
// « Confirme le paiement » (montant réellement reçu, référence) ou « Rejette » (motif, le client est prévenu). Seule cette
// confirmation (ou celle de la passerelle de paiement) débloque. Filtres par état et moyen, export CSV.
const emit = defineEmits<{ changed: [] }>();
const { t, locale } = useI18n();
const action = useSensitiveAction();

const items = ref<RegularisationDeclaration[]>([]);
const counts = ref<Record<DeclarationState, number>>({ declared: 0, verified: 0, rejected: 0, expired: 0, cancelled: 0 });
const state = ref<DeclarationState | ''>('declared');
const channel = ref<MobileMoneyChannel | ''>('');
const loading = ref(true);
const loadError = ref<unknown>(null);
const exportError = ref<unknown>(null);
const info = ref<string | null>(null);
const open = ref<{ id: string; kind: 'confirm' | 'reject' } | null>(null);

const received = ref('');
const reference = ref('');
const note = ref('');
const promise = ref('');
const reason = ref('');
const fieldError = ref<string | null>(null);

const day = (offset: number): string => new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
const minPromise = computed(() => day(1));
const maxPromise = computed(() => day(90));
const STATES: Array<DeclarationState | ''> = ['', ...DECLARATION_STATES];
const MOBILE_MONEY: MobileMoneyChannel[] = ['orange_money', 'mtn_momo'];

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    const res = await fetchDeclarations({ state: state.value, channel: channel.value });
    items.value = res.items;
    counts.value = res.counts;
  } catch (e) {
    loadError.value = e;
  } finally {
    loading.value = false;
  }
}

function toggle(item: RegularisationDeclaration, kind: 'confirm' | 'reject'): void {
  const same = open.value?.id === item.id && open.value.kind === kind;
  open.value = same ? null : { id: item.id, kind };
  info.value = null;
  fieldError.value = null;
  received.value = String(item.declared_amount_xaf);
  reference.value = '';
  note.value = '';
  promise.value = '';
  reason.value = '';
}

async function submit(item: RegularisationDeclaration): Promise<void> {
  fieldError.value = null;
  const client = item.tenant_name ?? item.tenant_id;
  if (open.value?.kind === 'reject') {
    const text = reason.value.trim();
    if (text === '' || text.length > 500) {
      fieldError.value = t('rg.dadm.reasonRequired');
      return;
    }
    const done = await action.run<RegularisationDeclaration>(actions.rejectDeclaration(item.id, text), t('rg.dadm.labelReject', { client }));
    if (done) await finished(t('rg.dadm.rejectedDone'));
    return;
  }
  const amount = Number(received.value);
  if (!Number.isInteger(amount) || amount <= 0) {
    fieldError.value = t('rg.dadm.receivedInvalid');
    return;
  }
  if (promise.value !== '' && (promise.value < minPromise.value || promise.value > maxPromise.value)) {
    fieldError.value = t('rg.act.untilInvalid');
    return;
  }
  const done = await action.run<RegularisationDeclaration>(
    actions.confirmDeclaration(item.id, {
      received_amount_xaf: amount,
      ...(reference.value.trim() !== '' ? { reference: reference.value.trim() } : {}),
      ...(note.value.trim() !== '' ? { note: note.value.trim() } : {}),
      ...(promise.value !== '' ? { promise_until: promise.value } : {}),
    }),
    t('rg.dadm.labelConfirm', { client }),
  );
  if (done) await finished(done.unlocked === false ? t('rg.dadm.confirmedPartial') : t('rg.dadm.confirmedFull'));
}

async function finished(message: string): Promise<void> {
  open.value = null;
  info.value = message;
  await load();
  emit('changed');
}

async function exportCsv(): Promise<void> {
  exportError.value = null;
  try {
    saveBlob(await fetchDeclarationsCsv({ state: state.value, channel: channel.value }), 'paiements-declares.csv');
  } catch (e) {
    exportError.value = e;
  }
}

const kindOf = (r: RegularisationDeclaration): string => t(`rg.dadm.kind.${r.kind}`);
const canDecide = (r: RegularisationDeclaration): boolean => r.state === 'declared' || r.state === 'expired';
const pendingCount = computed(() => counts.value.declared);

defineExpose({ reload: load });
onMounted(load);
</script>

<template>
  <section class="card declared" aria-labelledby="rg-dadm-title">
    <div class="row row--between">
      <div>
        <h2 id="rg-dadm-title">
          {{ t('rg.dadm.title') }}
          <span class="count" :aria-label="`${pendingCount} ${t('rg.dadm.pendingCount')}`">{{ pendingCount }}</span>
        </h2>
        <p class="muted">{{ t('rg.dadm.lede') }}</p>
      </div>
      <button type="button" class="btn" :disabled="items.length === 0" @click="exportCsv">{{ t('rg.dadm.export') }}</button>
    </div>

    <ErrorNotice :error="loadError" />
    <ErrorNotice :error="exportError" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>

    <div class="filters">
      <fieldset class="filter">
        <legend>{{ t('rg.dadm.state') }}</legend>
        <label v-for="s in STATES" :key="s || 'all'" class="chip">
          <input v-model="state" type="radio" name="rg-dadm-state" :value="s" @change="load" />
          <span>{{ s === '' ? t('rg.dadm.all') : `${t(`rg.decl.state.${s}`)} (${counts[s]})` }}</span>
        </label>
      </fieldset>
      <div class="field filter">
        <label for="rg-dadm-channel">{{ t('rg.dadm.channel') }}</label>
        <select id="rg-dadm-channel" v-model="channel" @change="load">
          <option value="">{{ t('rg.dadm.all') }}</option>
          <option v-for="c in MOBILE_MONEY" :key="c" :value="c">{{ t(channelKey(c)) }}</option>
        </select>
      </div>
    </div>

    <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="!loadError && items.length === 0" class="muted">{{ t('rg.dadm.empty') }}</p>

    <ul v-if="items.length > 0" class="rows">
      <li v-for="item in items" :key="item.id" class="entry">
        <div class="row row--between">
          <div>
            <h3 class="name">
              <router-link :to="{ name: 'platform-client', params: { tenant: item.tenant_id } }">{{ item.tenant_name ?? item.tenant_id }}</router-link>
            </h3>
            <p class="muted small">{{ item.label }} · {{ kindOf(item) }}</p>
          </div>
          <span class="tag" :class="item.state === 'verified' ? 'tag--success' : item.state === 'declared' ? 'tag--accent' : 'tag--warning'">{{ t(`rg.decl.state.${item.state}`) }}</span>
        </div>
        <dl class="facts">
          <div><dt>{{ t('rg.dadm.declared') }}</dt><dd>{{ formatXaf(item.declared_amount_xaf, locale) }}</dd></div>
          <div><dt>{{ t('rg.dadm.balance') }}</dt><dd>{{ formatXaf(item.balance_xaf, locale) }}</dd></div>
          <div><dt>{{ t('rg.dadm.channel') }}</dt><dd>{{ t(channelKey(item.channel)) }}</dd></div>
          <div><dt>{{ t('rg.dadm.payer') }}</dt><dd>{{ item.payer_phone_masked }}</dd></div>
          <div><dt>{{ t('rg.dadm.reference') }}</dt><dd><code>{{ item.reference }}</code></dd></div>
          <div><dt>{{ t('rg.dadm.declaredOn') }}</dt><dd>{{ formatDateTime(item.declared_at, locale) }}</dd></div>
          <div v-if="item.state === 'declared'"><dt>{{ t('rg.dadm.expiresOn') }}</dt><dd>{{ formatDate(item.expires_on, locale) }}</dd></div>
          <div v-if="item.confirmed_amount_xaf !== null"><dt>{{ t('rg.dadm.received') }}</dt><dd>{{ formatXaf(item.confirmed_amount_xaf, locale) }}</dd></div>
          <div v-if="item.decision_reason"><dt>{{ t('rg.dadm.reason') }}</dt><dd>{{ item.decision_reason }}</dd></div>
        </dl>
        <p v-if="item.review_note && canDecide(item)" class="notice" role="status">{{ t('rg.dadm.reviewNote', { note: item.review_note }) }}</p>

        <div v-if="canDecide(item)" class="row" role="group" :aria-label="t('rg.dadm.actionsFor', { client: item.tenant_name ?? item.tenant_id, app: item.label })">
          <button type="button" class="btn btn--primary" :aria-expanded="open?.id === item.id && open.kind === 'confirm'" @click="toggle(item, 'confirm')">{{ t('rg.dadm.confirm') }}</button>
          <button type="button" class="btn" :aria-expanded="open?.id === item.id && open.kind === 'reject'" @click="toggle(item, 'reject')">{{ t('rg.dadm.reject') }}</button>
        </div>

        <form v-if="open?.id === item.id" class="panel" novalidate @submit.prevent="submit(item)">
          <ErrorNotice :error="action.error.value" />
          <template v-if="open.kind === 'confirm'">
            <div class="field">
              <label :for="`rg-dadm-recv-${item.id}`">{{ t('rg.dadm.received') }}</label>
              <input :id="`rg-dadm-recv-${item.id}`" v-model="received" type="number" inputmode="numeric" min="1" :aria-invalid="!!fieldError" :aria-describedby="`rg-dadm-recv-${item.id}-hint`" />
              <span :id="`rg-dadm-recv-${item.id}-hint`" class="hint">{{ t('rg.dadm.receivedHint', { declared: formatXaf(item.declared_amount_xaf, locale) }) }}</span>
            </div>
            <div class="field">
              <label :for="`rg-dadm-ref-${item.id}`">{{ t('rg.dadm.refConfirm') }}</label>
              <input :id="`rg-dadm-ref-${item.id}`" v-model="reference" type="text" maxlength="120" autocomplete="off" />
            </div>
            <div class="field">
              <label :for="`rg-dadm-note-${item.id}`">{{ t('rg.dadm.note') }}</label>
              <textarea :id="`rg-dadm-note-${item.id}`" v-model="note" rows="2" maxlength="500" />
            </div>
            <div v-if="item.kind === 'partial'" class="field">
              <label :for="`rg-dadm-promise-${item.id}`">{{ t('rg.dadm.promise') }}</label>
              <input :id="`rg-dadm-promise-${item.id}`" v-model="promise" type="date" :min="minPromise" :max="maxPromise" :aria-describedby="`rg-dadm-promise-${item.id}-hint`" />
              <span :id="`rg-dadm-promise-${item.id}-hint`" class="hint">{{ t('rg.dadm.promiseHint') }}</span>
            </div>
          </template>
          <div v-else class="field">
            <label :for="`rg-dadm-reason-${item.id}`">{{ t('rg.dadm.reason') }}</label>
            <textarea :id="`rg-dadm-reason-${item.id}`" v-model="reason" rows="3" maxlength="500" :aria-invalid="!!fieldError" :aria-describedby="`rg-dadm-reason-${item.id}-hint`" />
            <span :id="`rg-dadm-reason-${item.id}-hint`" class="hint">{{ t('rg.dadm.reasonHint') }}</span>
          </div>
          <p v-if="fieldError" class="error-line" role="alert">{{ fieldError }}</p>
          <div class="row">
            <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ open.kind === 'confirm' ? t('rg.dadm.submitConfirm') : t('rg.dadm.submitReject') }}</button>
            <button type="button" class="btn btn--quiet" @click="open = null">{{ t('rg.dadm.cancel') }}</button>
          </div>
        </form>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.declared {
  margin: 16px 0;
}
.count {
  display: inline-block;
  min-width: 1.6em;
  padding: 0 6px;
  margin-left: 6px;
  border: 1px solid var(--ink);
  font-size: 13px;
  text-align: center;
}
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  margin: 12px 0;
}
.filter {
  border: 0;
  padding: 0;
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.filter legend {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
  margin-bottom: 4px;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: var(--tap);
  padding: 0 12px;
  border: 1px solid var(--border);
}
.rows {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.entry {
  border-top: 1px solid var(--border);
  padding-top: 12px;
}
.name {
  margin: 0;
  font-size: 17px;
}
.small {
  font-size: 13px;
  margin: 2px 0 0;
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
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
