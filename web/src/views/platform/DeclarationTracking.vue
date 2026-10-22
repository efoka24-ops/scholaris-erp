<script setup lang="ts">
import type { RegularisationDeclaration } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { channelKey } from './display';

// Suivi côté établissement des paiements déclarés : déclarée (en vérification), confirmée, rejetée (avec le motif), expirée.
defineProps<{ requests: RegularisationDeclaration[] }>();
const { t, locale } = useI18n();

const tone = (state: string): string => (state === 'verified' ? 'tag--success' : state === 'declared' ? 'tag--accent' : 'tag--warning');
</script>

<template>
  <section v-if="requests.length > 0" class="card" aria-labelledby="rg-track">
    <h2 id="rg-track">{{ t('rg.decl.trackTitle') }}</h2>
    <ul class="list">
      <li v-for="r in requests" :key="r.id" class="entry">
        <div class="row row--between">
          <strong>{{ r.label }}</strong>
          <span class="tag" :class="tone(r.state)">{{ t(`rg.decl.state.${r.state}`) }}</span>
        </div>
        <p class="muted line">
          {{ t('rg.decl.line', { amount: formatXaf(r.declared_amount_xaf, locale), channel: t(channelKey(r.channel)), phone: r.payer_phone_masked, reference: r.reference }) }}
          · {{ formatDateTime(r.declared_at, locale) }}
        </p>
        <p v-if="r.state === 'declared'" class="hint">{{ t('rg.decl.hint.declared', { date: formatDate(r.expires_on, locale) }) }}</p>
        <p v-else-if="r.state === 'verified'" class="hint">
          {{ t('rg.decl.hint.verified', { amount: formatXaf(r.confirmed_amount_xaf ?? r.declared_amount_xaf, locale) }) }}
          <template v-if="r.verified_via"> {{ t(`rg.decl.via.${r.verified_via}`) }}</template>
        </p>
        <p v-else-if="r.state === 'rejected'" class="hint">{{ t('rg.decl.hint.rejected', { reason: r.decision_reason ?? '—' }) }}</p>
        <p v-else class="hint">{{ t('rg.decl.hint.expired') }}</p>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.entry {
  border-top: 1px solid var(--border);
  padding-top: 10px;
}
.line,
.hint {
  margin: 4px 0 0;
  font-size: 13px;
}
</style>
