<script setup lang="ts">
// Paquet minimum de l'État et dons en nature (EX-APE-05) : bordereau, quantités, répartition par classe, reçus par le directeur ;
// bordereau de réception DOC-27 à faire signer par le livreur.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicSchools, publicSchoolsP2, type ClassRow, type SupplyReceipt } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const receipts = ref<SupplyReceipt[]>([]);
const classes = ref<ClassRow[]>([]);
const form = ref({ source: 'minimum_package', slip_ref: '', received_on: todayIso(), deliverer_name: '' });
const lines = ref<{ item_label: string; quantity: number; structure_id: string }[]>([{ item_label: '', quantity: 1, structure_id: '' }]);
const canWrite = computed(() => ctx.can('public_schools.school.write'));

onMounted(async () => {
  await run(async () => {
    receipts.value = await publicSchoolsP2.supplies();
    const year = await currentYear();
    if (year && canWrite.value) classes.value = await publicSchools.classes(year.id);
  });
});

async function save(): Promise<void> {
  await run(async () => {
    const r = await publicSchoolsP2.receiveSupply({
      source: form.value.source,
      slip_ref: form.value.slip_ref || undefined,
      received_on: form.value.received_on,
      deliverer_name: form.value.deliverer_name || undefined,
      lines: lines.value.filter((l) => l.item_label.trim() !== '').map((l) => ({ item_label: l.item_label.trim(), quantity: Number(l.quantity), ...(l.structure_id ? { structure_id: l.structure_id } : {}) })),
    });
    receipts.value = [...receipts.value, r];
    lines.value = [{ item_label: '', quantity: 1, structure_id: '' }];
  });
}

async function slip(r: SupplyReceipt): Promise<void> {
  await run(async () => openDocument((await publicSchoolsP2.supplySlip(r.id)).document.id));
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.supplies.title') }}</h1>
      <p class="lede">{{ t('pp.supplies.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <form v-if="canWrite" class="card controls" @submit.prevent="save">
      <div class="field">
        <label for="su-src">{{ t('pp.supplies.source') }}</label>
        <select id="su-src" v-model="form.source"><option value="minimum_package">{{ t('pp.supplies.minimum') }}</option><option value="donation">{{ t('pp.supplies.donation') }}</option></select>
      </div>
      <div class="field"><label for="su-ref">{{ t('pp.supplies.slip') }}</label><input id="su-ref" v-model="form.slip_ref" type="text" maxlength="60" /></div>
      <div class="field"><label for="su-on">{{ t('pp.supplies.receivedOn') }}</label><input id="su-on" v-model="form.received_on" type="date" required /></div>
      <div class="field"><label for="su-by">{{ t('pp.supplies.deliverer') }}</label><input id="su-by" v-model="form.deliverer_name" type="text" maxlength="120" /></div>
      <fieldset v-for="(l, i) in lines" :key="i" class="line">
        <legend>{{ t('pp.supplies.item', { n: i + 1 }) }}</legend>
        <div class="field"><label :for="`sl-${i}`">{{ t('pp.supplies.label') }}</label><input :id="`sl-${i}`" v-model="l.item_label" type="text" maxlength="120" /></div>
        <div class="field"><label :for="`sq-${i}`">{{ t('pp.supplies.quantity') }}</label><input :id="`sq-${i}`" v-model.number="l.quantity" type="number" min="1" /></div>
        <div class="field">
          <label :for="`sc-${i}`">{{ t('pp.common.class') }}</label>
          <select :id="`sc-${i}`" v-model="l.structure_id"><option value="">{{ t('pp.supplies.wholeSchool') }}</option><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select>
        </div>
      </fieldset>
      <button type="button" class="btn" @click="lines = [...lines, { item_label: '', quantity: 1, structure_id: '' }]">{{ t('pp.batch.add') }}</button>
      <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.common.save') }}</button>
    </form>

    <section class="card" aria-labelledby="su-list">
      <h2 id="su-list">{{ t('pp.supplies.list') }}</h2>
      <p v-if="receipts.length === 0" class="muted">{{ t('pp.supplies.none') }}</p>
      <ul>
        <li v-for="r in receipts" :key="r.id">
          {{ formatDate(r.received_on) }} · {{ r.source === 'minimum_package' ? t('pp.supplies.minimum') : t('pp.supplies.donation') }} · {{ r.slip_ref ?? '' }}
          <button v-if="canWrite" type="button" class="btn btn--small" :disabled="busy" @click="slip(r)">{{ t('pp.supplies.slipPdf') }}</button>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.line { border: 1px solid var(--input-border); padding: 8px; display: grid; gap: 6px; }
</style>
