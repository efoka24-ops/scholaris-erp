<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import type { MyRegularisation, RegularisationItem } from '@/api/platform';
import { useAuthStore } from '@/stores/auth';
import { usePlatformStore } from '@/stores/platform';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { cachedRegularisation } from '@/views/platform/regularisationGate';

// Bandeau d'abonnement, persistant sur tous les écrans d'un établissement dont le client est en retard de paiement (avec la date et
// les jours restants avant le blocage) ou en lecture seule. Le bouton « Régulariser » n'est montré qu'aux personnes concernées par
// le paiement (droit applications.manage) ; les autres ont un message d'information. La situation est lue avec un cache court.
const { t, locale } = useI18n();
const auth = useAuthStore();
const platform = usePlatformStore();
const route = useRoute();

const data = ref<MyRegularisation | null>(null);

async function refresh(): Promise<void> {
  const me = auth.me;
  // L'administrateur de plateforme n'a pas de client : rien à lire. Hors session, rien non plus.
  if (!me || platform.isAdmin) {
    data.value = null;
    return;
  }
  data.value = await cachedRegularisation(me.id);
}

// Relu à chaque navigation (cache d'une minute côté garde de routes) : un paiement confirmé fait disparaître le bandeau.
watch(() => [auth.me?.id, platform.isAdmin, route.fullPath], refresh, { immediate: true });

/** Application la plus proche du blocage parmi celles en retard. */
const pastDue = computed<RegularisationItem | null>(() => {
  const items = (data.value?.items ?? []).filter((i) => i.bucket === 'past_due' && i.blocks_on);
  return items.sort((a, b) => (a.days_left ?? 0) - (b.days_left ?? 0))[0] ?? null;
});
const kind = computed<'past_due' | 'read_only' | null>(() => {
  const mode = data.value?.mode;
  if (mode === 'read_only') return 'read_only';
  if (mode === 'past_due' && pastDue.value) return 'past_due';
  return null;
});
const pending = computed(() => (data.value?.requests ?? []).some((r) => r.state === 'declared'));
// L'écran de régularisation dit déjà tout ; en niveau « bloqué », il est le seul écran ouvert.
const visible = computed(() => kind.value !== null && route.name !== 'regularisation');
</script>

<template>
  <div v-if="visible" class="sub-banner" :class="`sub-banner--${kind}`" role="region" :aria-label="t('rg.banner.label')">
    <p class="sub-banner__text">
      <strong v-if="kind === 'past_due' && pastDue">
        {{ t('rg.banner.pastDue', { date: formatDate(pastDue.blocks_on, locale) }) }}
      </strong>
      <strong v-else>{{ t('rg.banner.readOnly') }}</strong>
      <span v-if="kind === 'past_due' && pastDue"> {{ t('rg.banner.daysLeft', { count: pastDue.days_left ?? 0 }) }}</span>
      <span v-if="pending"> {{ t('rg.banner.pending') }}</span>
      <span v-if="!data?.can_pay"> {{ t('rg.banner.info') }}</span>
    </p>
    <router-link v-if="data?.can_pay" :to="{ name: 'regularisation' }" class="btn btn--primary sub-banner__action">{{ t('rg.banner.action') }}</router-link>
  </div>
</template>

<style scoped>
.sub-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--ink);
  border-left: 6px solid var(--warning);
  background: var(--surface);
  font-size: 13px;
}
.sub-banner__text {
  margin: 0;
}
.sub-banner__action {
  white-space: nowrap;
}
</style>
