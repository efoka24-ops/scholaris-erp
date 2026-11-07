<script setup lang="ts">
// Registre du jour : arrivées, sorties (et à qui), refus, alertes et « encore à l'école », par section. Imprimable.
import { onMounted, ref } from 'vue';
import { nursery, type IssuedDocument, type Register } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatTime } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import { today, useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const date = ref(today());
const register = ref<Register | null>(null);
const issued = ref<IssuedDocument | null>(null);
const { can } = usePermissions();

async function load(): Promise<void> {
  register.value = (await run(() => nursery.register(date.value))) ?? null;
}
const print = (): void => window.print();
/** Registre du jour émis par le module Documents (numéro, contenu figé) ; une réédition révoque la précédente. */
async function issue(): Promise<void> {
  issued.value = (await run(() => nursery.registerDocument(date.value))) ?? null;
}
const hhmm = (iso: string | null): string => (iso ? formatTime(iso.slice(11, 16)) : '');
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.register.title') }}</h1>
      <p class="lede">{{ t('nur.register.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="issued" class="notice" role="status" aria-live="polite">{{ t('nur.doc.issued', { number: issued.number }) }}</p>
    <form class="card row noprint" @submit.prevent="load">
      <div class="field"><label for="rg-date">{{ t('nur.register.date') }}</label><input id="rg-date" v-model="date" type="date" required /></div>
      <button type="submit" class="btn" :disabled="busy">{{ t('nur.register.show') }}</button>
      <button type="button" class="btn btn--quiet" @click="print">{{ t('nur.register.print') }}</button>
      <button v-if="can(...P.dashboard)" type="button" class="btn btn--quiet" :disabled="busy" @click="issue">{{ t('nur.doc.register') }}</button>
    </form>

    <template v-if="register">
      <p class="card" role="status">
        {{ t('nur.register.totals', { children: register.totals.children ?? 0, present: register.totals.present ?? 0, absent: register.totals.absent ?? 0, left: register.totals.left ?? 0, still: register.totals.still_at_school ?? 0 }) }}
      </p>
      <section v-for="s in register.sections" :key="s.id" class="card" :aria-labelledby="`rg-${s.id}`">
        <h2 :id="`rg-${s.id}`">{{ s.label }}</h2>
        <div class="scroll">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">{{ t('nur.register.child') }}</th><th scope="col">{{ t('nur.register.status') }}</th><th scope="col">{{ t('nur.register.arrived') }}</th>
                <th scope="col">{{ t('nur.register.left') }}</th><th scope="col">{{ t('nur.register.leftTo') }}</th><th scope="col">{{ t('nur.register.notes') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in s.children" :key="c.id" :class="{ alert: c.status === 'still_at_school' && c.alerts.length > 0 }">
                <th scope="row">{{ c.name }}</th>
                <td>{{ t(`nur.register.s.${c.status}` as MessageKey) }}</td>
                <td>{{ hhmm(c.arrived_at) }}</td>
                <td>{{ hhmm(c.left_at) }}</td>
                <td>{{ c.left_to ?? '' }}</td>
                <td>
                  <span v-if="c.refusals > 0" class="tag tag--danger">{{ t('nur.register.refusals', { count: c.refusals }) }}</span>
                  <span v-if="c.alerts.length > 0" class="tag tag--warning">{{ t('nur.register.alerts') }}</span>
                  <span v-if="c.kept" class="tag tag--neutral">{{ t('nur.register.kept') }}</span>
                  <span v-if="c.stale" class="tag tag--warning">{{ t('nur.register.stale') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.scroll { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { text-align: left; padding: 8px; border-bottom: 1px solid var(--border, #dcdcd8); }
.alert { background: var(--warning-bg, #fff4d6); }
@media print { .noprint { display: none; } }
</style>
