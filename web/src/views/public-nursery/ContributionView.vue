<script setup lang="ts">
// Contribution exigible de la maternelle publique : montant, nombre de tranches et quotas de répartition viennent des paramètres
// (valeur de départ : 7 500 FCFA, une tranche à la rentrée, circulaire du MINEDUB). Chaque encaissement produit un reçu numéroté
// (QR de vérification) ; une annulation exige un motif et la souche reste. La contribution ne conditionne jamais l'accueil de l'enfant.
import { onMounted, ref } from 'vue';
import { contributions, type ContributionReceipt, type ContributionSummary } from '@/api/publicNursery';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';

const { t } = useI18n();
const error = ref<unknown>(null);
const busy = ref(false);
const summary = ref<ContributionSummary | null>(null);
const receipts = ref<ContributionReceipt[]>([]);
const unpaid = ref<{ learner_id: string; name: string }[]>([]);
const form = ref({ learner_id: '', payer_name: '' });
const reasons = ref<Record<string, string>>({});
const last = ref<ContributionReceipt | null>(null);

async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;
  try {
    return await action();
  } catch (e) {
    error.value = e;
    return undefined;
  } finally {
    busy.value = false;
  }
}
async function load(): Promise<void> {
  summary.value = (await run(() => contributions.summary())) ?? null;
  receipts.value = (await run(() => contributions.list())) ?? [];
  unpaid.value = (await run(() => contributions.unpaid())) ?? [];
}
async function collect(): Promise<void> {
  const receipt = await run(() => contributions.collect({ learner_id: form.value.learner_id, payer_name: form.value.payer_name.trim() }));
  if (receipt) {
    last.value = receipt;
    form.value = { learner_id: '', payer_name: '' };
    await load();
  }
}
async function cancel(r: ContributionReceipt): Promise<void> {
  if (await run(() => contributions.cancel(r.id, (reasons.value[r.id] ?? '').trim()))) await load();
}
onMounted(() => void load());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.contrib.eyebrow') }}</p>
      <h1>{{ t('pn.contrib.title') }}</h1>
      <p class="lead">{{ t('pn.contrib.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="last" class="notice" role="status" aria-live="polite">{{ t('pn.contrib.receipt', { number: last.receipt_number, amount: formatXaf(last.amount_xaf) }) }}</p>

    <section v-if="summary" class="card" aria-labelledby="ct-rules">
      <h2 id="ct-rules">{{ t('pn.contrib.rules') }}</h2>
      <p v-if="summary.rules.amount_xaf !== null">{{ t('pn.contrib.amount', { amount: formatXaf(summary.rules.amount_xaf), installments: summary.rules.installments ?? 0 }) }}</p>
      <p v-else class="warn">{{ t('pn.contrib.notSet') }}</p>
      <p v-if="summary.rules.source" class="muted">{{ t('pn.contrib.source') }} : {{ summary.rules.source }}</p>
      <p>{{ t('pn.contrib.progress', { paid: summary.paid, enrolled: summary.enrolled, collected: formatXaf(summary.collected_xaf) }) }}</p>
      <h3>{{ t('pn.contrib.quotas') }}</h3>
      <p v-if="!summary.quotas_configured" class="muted">{{ t('pn.contrib.quotasToConfirm') }}</p>
      <ul v-else class="plain">
        <li v-for="q in summary.split.lines" :key="q.code">{{ q.label }} ({{ q.percent }} %) : {{ formatXaf(q.amount_xaf) }}</li>
        <li v-if="summary.split.unallocated_xaf > 0" class="muted">{{ t('pn.contrib.unallocated', { amount: formatXaf(summary.split.unallocated_xaf) }) }}</li>
      </ul>
    </section>

    <form class="card form" @submit.prevent="collect">
      <h2>{{ t('pn.contrib.collect') }}</h2>
      <div class="field">
        <label for="ct-child">{{ t('nursery.child') }}</label>
        <select id="ct-child" v-model="form.learner_id" required>
          <option value="">{{ t('pn.contrib.pickChild') }}</option>
          <option v-for="u in unpaid" :key="u.learner_id" :value="u.learner_id">{{ u.name }}</option>
        </select>
      </div>
      <div class="field"><label for="ct-payer">{{ t('pn.contrib.payer') }}</label><input id="ct-payer" v-model="form.payer_name" type="text" maxlength="160" required /></div>
      <button type="submit" class="btn btn--primary" :disabled="busy || !form.learner_id || !form.payer_name.trim()">{{ t('pn.contrib.collectDo') }}</button>
    </form>

    <section class="card" aria-labelledby="ct-stubs">
      <h2 id="ct-stubs">{{ t('pn.contrib.stubs') }}</h2>
      <p v-if="receipts.length === 0" class="muted">{{ t('pn.contrib.noStub') }}</p>
      <ul class="stubs">
        <li v-for="r in receipts" :key="r.id">
          <p>
            <strong>{{ r.receipt_number }}</strong> · {{ r.learner_name }} · {{ formatXaf(r.amount_xaf) }} · {{ r.paid_on }}
            <span :class="['tag', r.status === 'valid' ? 'tag--ok' : 'tag--neutral']">{{ t(`pn.contrib.status.${r.status}` as MessageKey) }}</span>
          </p>
          <p v-if="r.cancel_reason" class="muted">{{ r.cancel_reason }}</p>
          <div v-if="r.status === 'valid'" class="row">
            <input v-model="reasons[r.id]" type="text" maxlength="255" :aria-label="t('pn.contrib.reason')" :placeholder="t('pn.contrib.reason')" />
            <button type="button" class="btn btn--quiet" :disabled="busy || !(reasons[r.id] ?? '').trim()" @click="cancel(r)">{{ t('pn.contrib.cancel') }}</button>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.form { display: grid; gap: 10px; }
.stubs { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.stubs > li { border-top: 1px solid var(--border, #dcdcd8); padding-top: 8px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; }
.warn { color: var(--warn, #b45309); }
</style>
