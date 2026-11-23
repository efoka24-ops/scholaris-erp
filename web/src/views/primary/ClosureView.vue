<script setup lang="ts">
// Clôture de l'année : contrôles bloquants (feuilles verrouillées, table de passage, conseils validés, bulletins émis), aperçu du
// passage de classe (décisions des conseils et classe suivante), puis renvoi vers la clôture du socle qui fait passer les admis en
// brouillon d'inscription. Les paramètres « à confirmer » sont listés avec leur source.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primary, primaryP2, type ClosureReport, type PromotionPreview, type SettingDefault } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { usePicker } from './usePicker';
import { useAction } from './usePri';

const { t } = useI18n();
const { error, busy, run } = useAction();
const picker = usePicker();
const report = ref<ClosureReport | null>(null);
const preview = ref<PromotionPreview | null>(null);
const defaults = ref<SettingDefault[]>([]);
const yearLabel = computed(() => picker.year.value?.label ?? '');
const show = (v: unknown): string => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));
const checkLabel = (key: string, fallback: string): string => {
  const known: Record<string, MessageKey> = {
    'primary.competency_sheets': 'pri.dash.check.sheets', 'primary.promotion_map': 'pri.dash.check.map', 'primary.councils': 'pri.close.councils', 'primary.report_cards': 'pri.close.cards',
  };
  const k = known[key];
  return k ? t(k) : fallback;
};

async function check(): Promise<void> {
  const year = picker.year.value;
  if (!year) return;
  await run(async () => {
    report.value = await primary.closureCheck(year.id);
    preview.value = await primaryP2.promotionPreview(year.id);
  });
}

onMounted(async () => {
  await run(async () => {
    await picker.load();
    defaults.value = await primaryP2.settingsDefaults();
  });
  await check();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.close.title') }}</h1>
      <p class="lede">{{ t('pri.close.intro', { year: yearLabel }) }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="report" class="card" aria-labelledby="cl-checks">
      <h2 id="cl-checks">{{ t('pri.close.checks') }}</h2>
      <p class="tag" :class="report.ready ? 'tag--success' : 'tag--warning'" role="status">{{ report.ready ? t('pri.dash.ready') : t('pri.dash.notReady', { count: report.blocking_count }) }}</p>
      <ul class="rows">
        <li v-for="c in report.checks" :key="c.key">
          <span>{{ checkLabel(c.key, c.label) }}<template v-if="c.detail"> · <span class="muted">{{ c.detail }}</span></template></span>
          <span class="tag" :class="c.status === 'ok' ? 'tag--success' : 'tag--warning'">{{ t(`pri.dash.status.${c.status}` as MessageKey) }}</span>
        </li>
      </ul>
      <ul v-if="report.councils_missing?.length" class="rows">
        <li v-for="m in report.councils_missing" :key="m.class_id"><span>{{ m.name }}</span><span>{{ t('pri.close.councilsCount', { validated: m.validated, expected: m.expected }) }}</span></li>
      </ul>
      <ul v-if="report.report_cards_missing?.length" class="rows">
        <li v-for="m in report.report_cards_missing" :key="m.class_id + m.period_label"><span>{{ picker.className(m.class_id) || t('pri.dash.classUnknown') }} · {{ m.period_label }}</span><span>{{ t('pri.close.cardsCount', { count: m.missing }) }}</span></li>
      </ul>
      <button type="button" class="btn" :disabled="busy" @click="check">{{ t('pri.close.recheck') }}</button>
    </section>

    <section v-if="preview" class="card" aria-labelledby="cl-promo">
      <h2 id="cl-promo">{{ t('pri.close.promotion') }}</h2>
      <p>{{ t('pri.close.promotionTotals', preview.totals) }}</p>
      <p v-if="preview.classes.length === 0" class="muted">{{ t('pri.close.noFinalCouncil') }}</p>
      <ul class="rows">
        <li v-for="c in preview.classes" :key="c.class_id">
          <span><strong>{{ picker.className(c.class_id) || t('pri.dash.classUnknown') }}</strong> → {{ c.next_class_id ? picker.className(c.next_class_id) : t('pri.close.noNext') }}</span>
          <span>{{ t('pri.close.classCounts', { admitted: c.admitted, adjourned: c.adjourned, cycle_end: c.cycle_end, undecided: c.undecided }) }}</span>
          <span v-if="c.missing_next_class" class="tag tag--warning">{{ t('pri.close.missingNext') }}</span>
        </li>
      </ul>
      <p class="muted">{{ t('pri.close.howTo') }}</p>
    </section>

    <section class="card" aria-labelledby="cl-defaults">
      <h2 id="cl-defaults">{{ t('pri.close.defaults') }}</h2>
      <p class="muted">{{ t('pri.close.defaultsIntro') }}</p>
      <ul class="rows">
        <li v-for="d in defaults" :key="d.key">
          <span><strong>{{ d.label }}</strong><br /><span class="muted small">{{ d.source }}</span></span>
          <span>{{ show(d.current) }}</span>
          <span class="tag" :class="d.customised ? 'tag--success' : 'tag--warning'">{{ d.customised ? t('pri.close.customised') : t('pri.close.toConfirm') }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.rows { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.rows li { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border, #dcdcd8); padding: 6px 0; overflow-wrap: anywhere; }
.small { font-size: 0.85rem; }
</style>
