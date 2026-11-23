<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ChildCard from '@/components/ChildCard.vue';
import FamilyTabBar from '@/components/FamilyTabBar.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import MobilePayPanel from '@/components/MobilePayPanel.vue';
import { ApiError, http } from '@/api/http';
import type { FamilyPortal } from '@/api/types';
import { dueByEstablishment } from '@/lib/family';
import { formatDate, formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import { cacheGet, cachePut } from '@/offline/db';

// Portail famille — design : Parent.html (maquette 04) : solde de la famille, échéance, « Tout payer », une carte par enfant, tous établissements.
const { t, locale } = useI18n();
const portal = ref<FamilyPortal | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const fromCache = ref(false);
const showPay = ref(false);

const dues = computed(() => (portal.value ? dueByEstablishment(portal.value.children) : []));
const nextDue = computed(() => portal.value?.next_due);
const nextDueText = computed(() => {
  const n = nextDue.value;
  if (!n || n.available === false || !n.due_on) return t('family.nextDueUnavailable');
  return t('family.nextDue', { date: formatDate(n.due_on, locale.value), amount: n.amount_xaf !== undefined ? formatXaf(n.amount_xaf, locale.value) : '' });
});

const schoolCount = computed(() => new Set(portal.value?.children.flatMap((c) => c.enrollments.map((e) => e.establishment_id)) ?? []).size);
const amountText = computed(() => formatXaf(portal.value?.family_balance_xaf ?? 0, locale.value).replace(' FCFA', ''));
const dueTag = computed(() => {
  const n = nextDue.value;
  if (!n || n.available === false || !n.due_on) return '';
  return t('family.dueTag', { date: formatDate(n.due_on, locale.value) });
});

function openPay(): void {
  if (!portal.value || portal.value.family_balance_xaf <= 0) return;
  showPay.value = true;
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const res = await http.get<{ data: FamilyPortal }>('/portal/family');
    portal.value = res.data;
    fromCache.value = false;
    await cachePut('portal:family', res.data);
  } catch (e) {
    // Hors ligne : dernier état connu, clairement signalé comme tel.
    const cached = e instanceof ApiError && e.network ? await cacheGet<FamilyPortal>('portal:family') : undefined;
    if (cached) {
      portal.value = cached;
      fromCache.value = true;
    } else {
      error.value = e;
    }
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="page fam">
    <header class="page-head">
      <p class="eyebrow">{{ t('family.eyebrow') }}</p>
      <h1>{{ t('family.title') }}</h1>
      <p v-if="portal" class="lede">{{ t('family.summary', { children: portal.children.length, schools: schoolCount }) }}</p>
    </header>

    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
    <p v-if="fromCache" class="notice notice--warning" role="status">{{ t('family.fromCache') }}</p>

    <template v-if="portal">
      <section class="card card--strong balance" aria-labelledby="bal-title">
        <p id="bal-title" class="eyebrow">{{ t('family.balance') }}</p>
        <div class="row row--between">
          <span class="amount">{{ amountText }}<span class="cur"> FCFA</span></span>
          <span v-if="dueTag" class="tag tag--warning">{{ dueTag }}</span>
        </div>
        <p class="muted small">{{ nextDueText }}</p>
        <button v-if="portal.family_balance_xaf > 0" type="button" class="payall" :aria-expanded="showPay" @click="showPay = !showPay">
          {{ t('family.payAll') }}
        </button>
        <p v-else class="uptodate">{{ t('family.upToDate') }}</p>
      </section>

      <MobilePayPanel v-if="showPay && dues.length" :dues="dues" :guarantor-id="portal.guarantor_id" @paid="load" />

      <section aria-labelledby="kids-title" class="kids">
        <h2 id="kids-title" class="sr-only">{{ t('family.children') }}</h2>
        <p v-if="portal.children.length === 0" class="card muted">{{ t('family.noChildren') }}</p>
        <ChildCard v-for="c in portal.children" :key="c.person.id" :child="c" />
      </section>
    </template>
    <FamilyTabBar :active="showPay ? 'pay' : 'home'" @pay="openPay" />
  </div>
</template>

<style scoped>
.fam {
  max-width: 760px;
}
@media (max-width: 720px) {
  .fam {
    padding-bottom: 72px;
  }
}
.balance {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 12px;
}
.balance p {
  margin: 0;
}
.amount {
  font-size: 30px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}
.cur {
  font-size: 15px;
}
.small {
  font-size: 13px;
}
.payall {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  border: 0;
  background: var(--accent);
  color: var(--on-accent);
  font: 800 15px var(--font);
  cursor: pointer;
}
.uptodate {
  font-weight: 700;
  color: var(--success);
}
.kids {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 12px;
}
.kids .card + .card {
  margin-top: 0;
}
</style>
