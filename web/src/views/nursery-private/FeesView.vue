<script setup lang="ts">
// Frais : solde de chaque enfant de la section (Billing du socle) et facture d'inscription. Aucun blocage du carnet pour impayé par défaut.
import { ref, watch } from 'vue';
import { nursery, type Balance, type Child, type Invoice } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import SectionPicker from './SectionPicker.vue';
import { useAction } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const balances = ref<Record<string, Balance>>({});
const invoices = ref<Record<string, Invoice>>({});

watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
  balances.value = {};
  invoices.value = {};
});
async function balance(child: Child): Promise<void> {
  const b = await run(() => nursery.balance(child.id));
  if (b) balances.value = { ...balances.value, [child.id]: b };
}
async function invoice(child: Child): Promise<void> {
  const enrollmentId = (await run(() => nursery.child(child.id)))?.enrollment_id;
  if (!enrollmentId) return;
  const i = await run(() => nursery.invoiceEnrolment(enrollmentId));
  if (i) invoices.value = { ...invoices.value, [child.id]: i };
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.fees.title') }}</h1>
      <p class="lede">{{ t('nur.fees.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <SectionPicker v-model="section" id="fe-section" />
      <ul class="list">
        <li v-for="c in children" :key="c.id">
          <div class="row row--between">
            <strong>{{ c.full_name }}</strong>
            <span class="row">
              <button type="button" class="btn btn--quiet" :disabled="busy" @click="balance(c)">{{ t('nur.fees.balance') }}</button>
              <button type="button" class="btn btn--quiet" :disabled="busy" @click="invoice(c)">{{ t('nur.fees.invoice') }}</button>
            </span>
          </div>
          <p v-if="balances[c.id]" role="status">
            {{ t('nur.fees.owed', { amount: formatXaf(balances[c.id]!.balance_xaf) }) }}
            <span v-if="balances[c.id]!.in_arrears" class="tag tag--warning">{{ t('nur.fees.arrears') }}</span>
          </p>
          <p v-if="invoices[c.id]" role="status">{{ t('nur.enrol.invoiced', { net: formatXaf(invoices[c.id]!.net_xaf), discount: formatXaf(invoices[c.id]!.discount_xaf), due: formatXaf(invoices[c.id]!.due_at_enrolment_xaf) }) }}</p>
        </li>
      </ul>
      <p v-if="section && children.length === 0" class="muted">{{ t('nur.section.empty') }}</p>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
</style>
