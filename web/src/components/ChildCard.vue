<script setup lang="ts">
import { computed } from 'vue';
import StatusTag from './StatusTag.vue';
import type { FamilyChild } from '@/api/types';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';

// Carte enfant — design : Parent.html (avatar à initiales, étiquette de solde, trois indicateurs).
const props = defineProps<{ child: FamilyChild }>();
const { t, tp, locale } = useI18n();

const latest = computed(() => props.child.results?.latest ?? null);
const schools = computed(() => [...new Set(props.child.enrollments.map((e) => e.establishment_name).filter(Boolean))].join(' · '));
const att = computed(() => props.child.attendance);
const initials = computed(() =>
  props.child.person.name
    .split(/\s+/)
    .map((w) => w.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase(),
);
const nf = computed(() => new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const rankText = computed(() => {
  const r = latest.value?.rank;
  if (r === null || r === undefined) return null;
  return locale.value === 'en' ? `${r}` : r === 1 ? '1er' : `${r}e`;
});
</script>

<template>
  <article class="card card--tight child" :aria-label="child.person.name">
    <header class="row head">
      <span class="avatar-sq" aria-hidden="true">{{ initials }}</span>
      <div class="grow">
        <h3 class="name">{{ child.person.name }}</h3>
        <p class="muted small">{{ schools }}</p>
      </div>
      <StatusTag :tone="child.balance_xaf > 0 ? 'warning' : 'success'">
        {{ child.balance_xaf > 0 ? t('family.due', { amount: formatXaf(child.balance_xaf, locale) }) : t('family.upToDate') }}
      </StatusTag>
    </header>

    <p v-if="child.access === 'restricted'" class="notice notice--warning" role="note">
      {{ child.restricted_reason || t('family.restricted') }}
    </p>

    <dl v-else class="facts">
      <div>
        <dd v-if="latest && latest.average !== null">{{ nf.format(latest.average) }}<small class="muted"> / 20</small></dd>
        <dd v-else-if="child.results?.blocked" class="muted off">{{ t('family.blocked') }}</dd>
        <dd v-else class="muted off">{{ t('family.noResult') }}</dd>
        <dt>{{ t('family.average') }}<template v-if="latest?.period_label"> · {{ latest.period_label }}</template></dt>
      </div>
      <div>
        <dd v-if="att?.available">{{ att.absences ?? 0 }}</dd>
        <dd v-else class="muted off">{{ t('common.unavailable') }}</dd>
        <dt>{{ t('family.absences') }}<template v-if="att?.available"> · {{ tp('family.unjustified', att.unjustified_absences ?? 0) }}</template></dt>
      </div>
      <div>
        <dd v-if="rankText">{{ rankText }}</dd>
        <dd v-else class="muted off">{{ t('common.unavailable') }}</dd>
        <dt>{{ t('family.rank') }}<template v-if="latest?.class_size"> · {{ t('family.outOf', { count: latest.class_size }) }}</template></dt>
      </div>
      <div v-if="child.documents">
        <dd v-if="child.documents.blocked" class="muted off">{{ t('family.blocked') }}</dd>
        <dd v-else>{{ child.documents.items.length }}</dd>
        <dt>{{ t('family.documents') }}</dt>
      </div>
    </dl>

    <p v-if="child.access === 'full' && child.life_book && child.life_book.available === false" class="muted small lifebook">
      {{ t('family.lifeBook') }} : {{ t('common.unavailable') }}
    </p>
  </article>
</template>

<style scoped>
.child {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.head {
  flex-wrap: nowrap;
  gap: 12px;
}
.grow {
  flex: 1;
  min-width: 0;
}
.name {
  margin: 0;
  font-size: 17px;
}
.small {
  font-size: 12px;
  margin: 0;
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  border-top: 1px solid var(--border);
  padding-top: 12px;
  margin: 0;
  text-align: center;
}
.facts > div + div {
  border-left: 1px solid var(--border);
}
.facts dd {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
}
.facts dd.off {
  font-size: 13px;
  font-weight: 600;
}
.facts dt {
  font-size: 11px;
  color: var(--muted);
}
.lifebook {
  margin: 0;
  border-top: 1px solid var(--border);
  padding-top: 12px;
}
</style>
