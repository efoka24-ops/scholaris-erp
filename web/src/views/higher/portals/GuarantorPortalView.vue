<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { portal, type GuarantorPortal } from '@/api/higher';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useXt } from '../xi18n';
import FinancePanel from './FinancePanel.vue';
import MobilePay from './MobilePay.vue';

// Portail garant de l'IPES (POR, T317) : pour chaque étudiant rattaché, inscription, frais, reçus et paiement mobile money
// (toujours visibles) ; notes et absences seulement si l'étudiant y consent (RG-INS-7) : sinon le serveur ne les envoie pas.
const { t } = useXt();
const higher = useHigherStore();
const load = useHigherAction();
const data = ref<GuarantorPortal | null>(null);

async function fetchPortal(): Promise<void> {
  const res = await load.run(() => portal.guarantor());
  if (res) data.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('years', 'groups');
  await fetchPortal();
});
</script>

<template>
  <HigherFrame :title="t('hi.por.guarantorTitle')" :lede="t('hi.por.lede')" section="portal" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <template v-if="data">
      <p v-if="data.students.length === 0" class="notice" role="status">{{ t('hi.por.noStudents') }}</p>
      <article v-for="s in data.students" :key="s.person_id" class="gp-student hi-section" :data-student="s.person_id">
        <h2>{{ s.full_name }}</h2>
        <div class="hi-two">
          <div><FinancePanel :enrolments="s.enrolments" :finance="s.finance" :receipts="s.receipts" /></div>
          <div>
            <MobilePay :accounts="s.finance" :student-id="s.person_id" :id-prefix="`gp-${s.person_id}`" :title="t('hx.por.payFor', { name: s.full_name })" class="hi-section" @paid="fetchPortal" />
            <section class="card hi-section" data-test="consent">
              <h3>{{ t('hi.por.grades') }} · {{ t('hi.por.attendance') }}</h3>
              <p v-if="!s.consent" class="notice notice--warning" data-test="consent-required">{{ t('hi.por.consentRequired') }}</p>
              <template v-else>
                <p class="muted">{{ t('hx.por.consentGiven') }}</p>
                <p v-if="!s.grades.available || !s.attendance.available" class="muted hi-small">{{ t('hi.por.unavailable') }}</p>
              </template>
            </section>
          </div>
        </div>
      </article>
    </template>
  </HigherFrame>
</template>

<style scoped>
.gp-student + .gp-student {
  border-top: 2px solid var(--ink);
  padding-top: 16px;
}
</style>
