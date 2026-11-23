<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { assignTerritory, createTerritory, listTerritories, type Territory, type TerritoryType } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';
import { formatDate, todayIso } from '@/lib/format';

// Rattachement daté de l'établissement actif à un territoire (C-115, défaut C110-PP2) : réservé au droit client
// organisation.territories.manage (administrateur fonctionnel des écoles publiques). Routes du socle /territories.
const { t, locale } = useI18n();
const ctx = useContextStore();

const TYPES: TerritoryType[] = ['region', 'departement', 'arrondissement'];
const territories = ref<Territory[]>([]);
const loaded = ref(false);
const done = ref<string | null>(null);
const list = useAction();
const assignAction = useAction();
const createAction = useAction();

const form = reactive({ territory_id: '', valid_from: todayIso() });
const touched = ref(false);
const draft = reactive({ type: 'region' as TerritoryType, code: '', label_fr: '', label_en: '', parent_id: '', valid_from: '' });
const draftTouched = ref(false);

const label = (x: Territory): string => (locale.value === 'en' ? x.label_en : x.label_fr);
const typeLabel = (type: TerritoryType): string => t(`admin.terr.type.${type}` as AdminKey);
const sorted = computed(() => [...territories.value].sort((a, b) => TYPES.indexOf(a.type) - TYPES.indexOf(b.type) || label(a).localeCompare(label(b))));
const draftInvalid = computed(() => draft.code.trim() === '' || draft.label_fr.trim() === '' || draft.label_en.trim() === '' || draft.valid_from === '');
// Une date seule est un jour d'effet à Douala (début de journée, UTC+1) : borne incluse côté serveur.
const atDouala = (day: string): string => `${day}T00:00:00+01:00`;

async function load(): Promise<void> {
  const res = await list.run(() => listTerritories());
  if (res) territories.value = res;
  loaded.value = true;
}

async function assign(): Promise<void> {
  touched.value = true;
  done.value = null;
  const establishment = ctx.establishmentId;
  if (form.territory_id === '' || form.valid_from === '' || !establishment) return;
  const res = await assignAction.run(() => assignTerritory(form.territory_id, [establishment], atDouala(form.valid_from)));
  if (!res) return;
  const target = territories.value.find((x) => x.id === res.territory_id);
  done.value = t('admin.terr.done', { name: target ? label(target) : res.territory_id, date: formatDate(form.valid_from, locale.value) });
  touched.value = false;
}

async function create(): Promise<void> {
  draftTouched.value = true;
  if (draftInvalid.value) return;
  const res = await createAction.run(() =>
    createTerritory({ type: draft.type, code: draft.code.trim(), label_fr: draft.label_fr.trim(), label_en: draft.label_en.trim(), parent_id: draft.parent_id || null, valid_from: atDouala(draft.valid_from) }),
  );
  if (!res) return;
  territories.value = [...territories.value, res];
  form.territory_id = res.id;
  Object.assign(draft, { code: '', label_fr: '', label_en: '', parent_id: '' });
  draftTouched.value = false;
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.terr.title') }}</h1>
      <p class="lede">{{ t('admin.terr.lede', { name: ctx.activeTile?.name ?? '' }) }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="list.error.value" />

    <p v-if="loaded && territories.length === 0 && !list.error.value" class="card muted">{{ t('admin.terr.none') }}</p>

    <form v-if="territories.length > 0" class="card" novalidate aria-labelledby="t-assign" @submit.prevent="assign">
      <h2 id="t-assign">{{ t('admin.terr.assign') }}</h2>
      <ErrorNotice :error="assignAction.error.value" />
      <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
      <div class="grid grid--kpi">
        <div class="field">
          <label for="t-territory">{{ t('admin.terr.territory') }}</label>
          <select id="t-territory" v-model="form.territory_id" :aria-invalid="touched && form.territory_id === ''" aria-describedby="t-territory-err">
            <option value="" disabled>—</option>
            <option v-for="x in sorted" :key="x.id" :value="x.id">{{ typeLabel(x.type) }} · {{ label(x) }} ({{ x.code }})</option>
          </select>
          <span v-if="touched && form.territory_id === ''" id="t-territory-err" class="error">{{ t('admin.terr.territoryRequired') }}</span>
        </div>
        <div class="field">
          <label for="t-from">{{ t('admin.terr.validFrom') }}</label>
          <input id="t-from" v-model="form.valid_from" type="date" :aria-invalid="touched && form.valid_from === ''" aria-describedby="t-from-hint" />
          <span id="t-from-hint" class="hint" :class="{ error: touched && form.valid_from === '' }">{{ touched && form.valid_from === '' ? t('admin.terr.validFromRequired') : t('admin.terr.validFromHint') }}</span>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="assignAction.busy.value">{{ t('admin.terr.submit') }}</button>
    </form>

    <section v-if="territories.length > 0" class="card block" aria-labelledby="t-list">
      <h2 id="t-list">{{ t('admin.terr.list') }}</h2>
      <ul class="plain">
        <li v-for="x in sorted" :key="x.id">
          <strong>{{ label(x) }}</strong> <span class="tag tag--neutral">{{ typeLabel(x.type) }}</span> {{ x.code }} · {{ t('admin.terr.since', { date: formatDate(x.valid_from, locale) }) }}
        </li>
      </ul>
    </section>

    <form class="card block" novalidate aria-labelledby="t-new" @submit.prevent="create">
      <h2 id="t-new">{{ t('admin.terr.new') }}</h2>
      <ErrorNotice :error="createAction.error.value" />
      <div class="grid grid--kpi">
        <div class="field">
          <label for="tn-type">{{ t('admin.terr.type') }}</label>
          <select id="tn-type" v-model="draft.type">
            <option v-for="type in TYPES" :key="type" :value="type">{{ typeLabel(type) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="tn-code">{{ t('admin.terr.code') }}</label>
          <input id="tn-code" v-model="draft.code" type="text" maxlength="80" autocomplete="off" />
        </div>
        <div class="field">
          <label for="tn-fr">{{ t('admin.terr.labelFr') }}</label>
          <input id="tn-fr" v-model="draft.label_fr" type="text" maxlength="160" autocomplete="off" />
        </div>
        <div class="field">
          <label for="tn-en">{{ t('admin.terr.labelEn') }}</label>
          <input id="tn-en" v-model="draft.label_en" type="text" maxlength="160" autocomplete="off" />
        </div>
        <div class="field">
          <label for="tn-parent">{{ t('admin.terr.parent') }}</label>
          <select id="tn-parent" v-model="draft.parent_id">
            <option value="">{{ t('admin.terr.noParent') }}</option>
            <option v-for="x in sorted" :key="x.id" :value="x.id">{{ typeLabel(x.type) }} · {{ label(x) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="tn-from">{{ t('admin.terr.startsOn') }}</label>
          <input id="tn-from" v-model="draft.valid_from" type="date" />
        </div>
      </div>
      <p v-if="draftTouched && draftInvalid" class="error" role="alert">{{ t('admin.terr.fieldsRequired') }}</p>
      <button type="submit" class="btn" :disabled="createAction.busy.value">{{ t('admin.terr.create') }}</button>
    </form>
  </div>
</template>

<style scoped>
.block {
  margin-top: 24px;
}
.plain {
  list-style: none;
  margin: 0;
  padding: 0;
}
.plain li {
  padding: 8px 0;
  border-top: 1px solid var(--border);
}
</style>
