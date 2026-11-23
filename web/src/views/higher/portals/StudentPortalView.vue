<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { portal, type StudentPortal } from '@/api/higher';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useXt } from '../xi18n';
import FinancePanel from './FinancePanel.vue';
import MobilePay from './MobilePay.vue';

// Portail étudiant de l'IPES (POR, T241) : inscriptions, frais et échéancier, reçus, paiement mobile money, documents émis.
const { t, tv, locale } = useXt();
const higher = useHigherStore();
const router = useRouter();
const load = useHigherAction();
const data = ref<StudentPortal | null>(null);
const canRequest = router.hasRoute('portal-learner');

async function fetchPortal(): Promise<void> {
  const res = await load.run(() => portal.student());
  if (res) data.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('years', 'groups');
  await fetchPortal();
});
</script>

<template>
  <HigherFrame :title="t('hi.por.studentTitle')" :lede="t('hi.por.lede')" section="portal" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <template v-if="data">
      <p class="eyebrow" data-test="student-name">{{ data.full_name }}</p>
      <div class="hi-two">
        <div>
          <FinancePanel :enrolments="data.enrolments" :finance="data.finance" :receipts="data.receipts" />
        </div>
        <div>
          <MobilePay :accounts="data.finance" id-prefix="sp-pay" class="hi-section" @paid="fetchPortal" />
          <section class="card hi-section" data-test="documents">
            <h2>{{ t('hi.por.documents') }}</h2>
            <ul v-if="data.documents.length" class="hi-list">
              <li v-for="d in data.documents" :key="d.id">
                <span class="hi-grow"><strong class="hi-mono">{{ d.number }}</strong> · {{ d.code }} · {{ formatDate(d.issued_at, locale) }}</span>
                <span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ tv('hi.doc.st.', d.status) }}</span>
                <router-link class="hi-linkbtn" :to="{ name: 'verify', params: { token: d.verification_token } }">{{ t('hi.doc.verifyLink') }}</router-link>
              </li>
            </ul>
            <p v-else class="muted">{{ t('hi.por.documentsNone') }}</p>
            <p class="hint">{{ t('hi.por.requests') }}</p>
            <router-link v-if="canRequest" class="btn btn--quiet" :to="{ name: 'portal-learner' }">{{ t('hx.por.requestDoc') }}</router-link>
          </section>
          <section v-if="!data.timetable.available" class="card hi-section">
            <h2>{{ t('hi.por.timetable') }}</h2>
            <p class="muted">{{ t('hi.por.unavailable') }}</p>
          </section>
        </div>
      </div>
    </template>
  </HigherFrame>
</template>
