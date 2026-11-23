<script setup lang="ts">
// Inscription d'un enfant en section : identité, parent garant, allergies, accord photo ; avertissements (âge, effectif) sans blocage ;
// facture d'inscription par le socle Billing (remise de fratrie appliquée par le serveur).
import { ref } from 'vue';
import { nursery, type EnrolResult, type Invoice } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const blank = () => ({ first_name: '', last_name: '', birth_date: '', gender: '', section_id: '', allergies: '', photo_consent: false, parent_first: '', parent_last: '', parent_phone: '' });
const form = ref(blank());
const result = ref<EnrolResult | null>(null);
const invoice = ref<Invoice | null>(null);

async function submit(): Promise<void> {
  const f = form.value;
  const done = await run(() =>
    nursery.enrol({
      first_name: f.first_name.trim(),
      last_name: f.last_name.trim(),
      birth_date: f.birth_date,
      ...(f.gender ? { gender: f.gender as 'male' | 'female' } : {}),
      section_id: f.section_id,
      ...(f.allergies.trim() ? { allergies: f.allergies.trim() } : {}),
      photo_consent: f.photo_consent,
      guardians: [{ kind: 'financial_guarantor', identity: { first_name: f.parent_first.trim(), last_name: f.parent_last.trim(), ...(f.parent_phone.trim() ? { phone: f.parent_phone.trim() } : {}) } }],
    }),
  );
  if (done) {
    result.value = done;
    invoice.value = null;
    form.value = blank();
  }
}
async function bill(): Promise<void> {
  if (result.value) invoice.value = (await run(() => nursery.invoiceEnrolment(result.value!.enrollment_id))) ?? null;
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.enrol.title') }}</h1>
      <p class="lede">{{ t('nur.enrol.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div v-if="result" class="notice" role="status">
      <p><strong>{{ t('nur.enrol.done') }}</strong></p>
      <p v-for="w in result.warnings" :key="w.code" class="warn">{{ w.detail }}</p>
      <button v-if="can(...P.fees)" type="button" class="btn" :disabled="busy" @click="bill">{{ t('nur.enrol.invoice') }}</button>
      <p v-if="invoice">{{ t('nur.enrol.invoiced', { net: formatXaf(invoice.net_xaf), discount: formatXaf(invoice.discount_xaf), due: formatXaf(invoice.due_at_enrolment_xaf) }) }}</p>
    </div>

    <form class="card grid2" @submit.prevent="submit">
      <h2 class="span2">{{ t('nur.enrol.child') }}</h2>
      <div class="field"><label for="en-first">{{ t('nur.enrol.first') }}</label><input id="en-first" v-model="form.first_name" type="text" maxlength="120" required autocomplete="off" /></div>
      <div class="field"><label for="en-last">{{ t('nur.enrol.last') }}</label><input id="en-last" v-model="form.last_name" type="text" maxlength="120" required autocomplete="off" /></div>
      <div class="field"><label for="en-birth">{{ t('nur.enrol.birth') }}</label><input id="en-birth" v-model="form.birth_date" type="date" required /></div>
      <div class="field">
        <label for="en-gender">{{ t('nur.enrol.gender') }}</label>
        <select id="en-gender" v-model="form.gender">
          <option value="">{{ t('nur.enrol.genderNone') }}</option>
          <option value="female">{{ t('nur.enrol.female') }}</option>
          <option value="male">{{ t('nur.enrol.male') }}</option>
        </select>
      </div>
      <SectionPicker v-model="form.section_id" id="en-section" />
      <div class="field"><label for="en-allergy">{{ t('nur.enrol.allergies') }}</label><input id="en-allergy" v-model="form.allergies" type="text" maxlength="500" /></div>
      <h2 class="span2">{{ t('nur.enrol.parent') }}</h2>
      <div class="field"><label for="en-pf">{{ t('nur.enrol.first') }}</label><input id="en-pf" v-model="form.parent_first" type="text" maxlength="120" required autocomplete="off" /></div>
      <div class="field"><label for="en-pl">{{ t('nur.enrol.last') }}</label><input id="en-pl" v-model="form.parent_last" type="text" maxlength="120" required autocomplete="off" /></div>
      <div class="field"><label for="en-pp">{{ t('nur.enrol.phone') }}</label><input id="en-pp" v-model="form.parent_phone" type="tel" maxlength="30" autocomplete="off" /></div>
      <div class="field span2">
        <label class="check"><input v-model="form.photo_consent" type="checkbox" /> {{ t('nur.enrol.photoConsent') }}</label>
        <span class="hint">{{ t('nur.enrol.photoHint') }}</span>
      </div>
      <div class="span2"><button type="submit" class="btn btn--primary btn--large" :disabled="busy || !form.section_id">{{ t('nur.enrol.submit') }}</button></div>
    </form>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.span2 { grid-column: 1 / -1; }
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
.warn { color: var(--warning-ink, #7a4b00); }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
