<script setup lang="ts">
// Fiche de l'école et infrastructure versionnée : une version ouverte se modifie, une version close est immuable ; toute
// correction après clôture crée la version suivante, avec son motif.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { publicSchools, type InfrastructureVersion, type SchoolFicheInput } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePs';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const canWrite = computed(() => can(...P.schoolWrite));
const canPhotos = computed(() => can(...P.inspectionRead));

const missing = ref(false);
const savedFiche = ref(false);
const fiche = reactive({
  official_code: '',
  creation_act_ref: '',
  creation_act_date: '',
  subsystem: 'francophone' as 'francophone' | 'anglophone' | 'bilingual',
  cycle: 'primary' as 'nursery' | 'primary' | 'group',
  school_group_ref: '',
  status: 'open' as 'open' | 'closed' | 'suspended',
  latitude: '',
  longitude: '',
  phone: '',
  email: '',
});

const versions = ref<InfrastructureVersion[]>([]);
const editing = ref<InfrastructureVersion | null>(null);
const newYear = ref('');
const reason = ref('');
const draft = reactive({
  desks_count: 0,
  water_points: 0,
  latrines_girls: 0,
  latrines_boys: 0,
  has_electricity: false,
  has_fence: false,
  has_canteen: false,
  has_library: false,
  network_observed: 'unknown' as InfrastructureVersion['network_observed'],
});

const nullable = (v: string): string | null => (v.trim() === '' ? null : v.trim());
const number = (v: string): number | null => (v.trim() === '' || Number.isNaN(Number(v)) ? null : Number(v));

async function load(): Promise<void> {
  await run(async () => {
    try {
      const f = await publicSchools.school();
      Object.assign(fiche, {
        official_code: f.official_code,
        creation_act_ref: f.creation_act_ref ?? '',
        creation_act_date: f.creation_act_date ?? '',
        subsystem: f.subsystem,
        cycle: f.cycle,
        school_group_ref: f.school_group_ref ?? '',
        status: f.status,
        latitude: f.latitude === null ? '' : String(f.latitude),
        longitude: f.longitude === null ? '' : String(f.longitude),
        phone: f.phone ?? '',
        email: f.email ?? '',
      });
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) missing.value = true;
      else throw e;
    }
    versions.value = await publicSchools.infrastructure();
    if (!newYear.value) newYear.value = (await currentYear().catch(() => null))?.label ?? '';
  });
}

async function saveFiche(): Promise<void> {
  savedFiche.value = false;
  const body: SchoolFicheInput = {
    official_code: fiche.official_code.trim(),
    creation_act_ref: nullable(fiche.creation_act_ref),
    creation_act_date: nullable(fiche.creation_act_date),
    subsystem: fiche.subsystem,
    cycle: fiche.cycle,
    school_group_ref: nullable(fiche.school_group_ref),
    status: fiche.status,
    latitude: number(fiche.latitude),
    longitude: number(fiche.longitude),
    phone: nullable(fiche.phone),
    email: nullable(fiche.email),
  };
  if (await run(() => publicSchools.saveSchool(body))) {
    savedFiche.value = true;
    missing.value = false;
  }
}

function edit(v: InfrastructureVersion): void {
  editing.value = v;
  reason.value = '';
  Object.assign(draft, {
    desks_count: v.desks_count,
    water_points: v.water_points,
    latrines_girls: v.latrines_girls,
    latrines_boys: v.latrines_boys,
    has_electricity: v.has_electricity,
    has_fence: v.has_fence,
    has_canteen: v.has_canteen,
    has_library: v.has_library,
    network_observed: v.network_observed,
  });
}

async function reload(): Promise<void> {
  versions.value = await publicSchools.infrastructure();
}

async function open(): Promise<void> {
  const created = await run(() => publicSchools.openInfrastructure({ year_label: newYear.value.trim() }));
  if (created) {
    await reload();
    edit(created);
  }
}

async function saveDraft(): Promise<void> {
  const v = editing.value;
  if (!v) return;
  const updated =
    v.state === 'draft'
      ? await run(() => publicSchools.updateInfrastructure(v.id, { ...draft }))
      : await run(() => publicSchools.correctInfrastructure(v.id, reason.value.trim(), { year_label: v.year_label, ...draft }));
  if (updated) {
    editing.value = null;
    await run(reload);
  }
}

async function close(v: InfrastructureVersion): Promise<void> {
  if (!window.confirm(t('ps.school.confirmClose'))) return;
  if (await run(() => publicSchools.closeInfrastructure(v.id))) await run(reload);
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.school.title') }}</h1>
      <p class="lede">{{ t('ps.school.intro') }}</p>
      <p v-if="canPhotos"><router-link :to="{ name: 'ps-photos' }">{{ t('ps.photos.link') }}</router-link></p>
    </header>
    <ErrorNotice :error="error" />

    <form class="card form" @submit.prevent="saveFiche">
      <h2>{{ t('ps.school.ficheTitle') }}</h2>
      <p v-if="missing" class="notice notice--warning" role="status">{{ t('ps.school.missing') }}</p>
      <fieldset :disabled="!canWrite || busy">
        <div class="field">
          <label for="sc-code">{{ t('ps.school.code') }}</label>
          <input id="sc-code" v-model="fiche.official_code" type="text" maxlength="40" required />
        </div>
        <div class="field">
          <label for="sc-subsystem">{{ t('ps.school.subsystem') }}</label>
          <select id="sc-subsystem" v-model="fiche.subsystem">
            <option value="francophone">{{ t('ps.school.subsystem.francophone') }}</option>
            <option value="anglophone">{{ t('ps.school.subsystem.anglophone') }}</option>
            <option value="bilingual">{{ t('ps.school.subsystem.bilingual') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sc-cycle">{{ t('ps.school.cycle') }}</label>
          <select id="sc-cycle" v-model="fiche.cycle">
            <option value="nursery">{{ t('ps.school.cycle.nursery') }}</option>
            <option value="primary">{{ t('ps.school.cycle.primary') }}</option>
            <option value="group">{{ t('ps.school.cycle.group') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sc-status">{{ t('ps.school.status') }}</label>
          <select id="sc-status" v-model="fiche.status">
            <option value="open">{{ t('ps.school.status.open') }}</option>
            <option value="suspended">{{ t('ps.school.status.suspended') }}</option>
            <option value="closed">{{ t('ps.school.status.closed') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sc-act">{{ t('ps.school.actRef') }}</label>
          <input id="sc-act" v-model="fiche.creation_act_ref" type="text" />
        </div>
        <div class="field">
          <label for="sc-actdate">{{ t('ps.school.actDate') }}</label>
          <input id="sc-actdate" v-model="fiche.creation_act_date" type="date" />
        </div>
        <div class="field">
          <label for="sc-group">{{ t('ps.school.group') }}</label>
          <input id="sc-group" v-model="fiche.school_group_ref" type="text" />
        </div>
        <div class="field">
          <label for="sc-lat">{{ t('ps.school.latitude') }}</label>
          <input id="sc-lat" v-model="fiche.latitude" type="text" inputmode="decimal" />
        </div>
        <div class="field">
          <label for="sc-lon">{{ t('ps.school.longitude') }}</label>
          <input id="sc-lon" v-model="fiche.longitude" type="text" inputmode="decimal" />
        </div>
        <div class="field">
          <label for="sc-phone">{{ t('ps.enrol.phone') }}</label>
          <input id="sc-phone" v-model="fiche.phone" type="tel" inputmode="tel" />
        </div>
        <div class="field">
          <label for="sc-email">{{ t('ps.school.email') }}</label>
          <input id="sc-email" v-model="fiche.email" type="email" />
        </div>
      </fieldset>
      <button v-if="canWrite" type="submit" class="btn btn--primary" :disabled="busy || fiche.official_code.trim() === ''">{{ t('ps.school.save') }}</button>
      <p v-if="savedFiche" class="notice notice--success" role="status">{{ t('ps.school.saved') }}</p>
    </form>

    <section class="card stack" aria-labelledby="sc-infra">
      <h2 id="sc-infra">{{ t('ps.school.infraTitle') }}</h2>
      <p class="muted">{{ t('ps.school.infraIntro') }}</p>
      <p v-if="versions.length === 0" class="muted">{{ t('ps.school.infraNone') }}</p>
      <ul class="versions">
        <li v-for="v in versions" :key="v.id" class="row row--between">
          <div>
            <strong>{{ v.year_label }}</strong> · {{ t('ps.school.version', { n: v.version }) }}
            <span class="tag" :class="v.state === 'closed' ? 'tag--neutral' : 'tag--success'">{{ v.state === 'closed' ? t('ps.school.closed') : t('ps.school.draft') }}</span>
            <div class="muted">{{ t('ps.school.summary', { desks: v.desks_count, water: v.water_points, girls: v.latrines_girls, boys: v.latrines_boys }) }}</div>
            <div v-if="v.correction_reason" class="muted">{{ t('ps.school.correctionOf', { reason: v.correction_reason }) }}</div>
          </div>
          <div class="row">
            <router-link class="btn btn--quiet" :to="{ name: 'ps-rooms', params: { id: v.id } }">{{ t('ps.school.rooms', { year: v.year_label, n: v.version }) }}</router-link>
            <template v-if="canWrite">
              <button type="button" class="btn" @click="edit(v)">{{ v.state === 'draft' ? t('ps.school.edit') : t('ps.school.correct') }}</button>
              <button v-if="v.state === 'draft'" type="button" class="btn btn--quiet" :disabled="busy" @click="close(v)">{{ t('ps.school.close') }}</button>
            </template>
          </div>
        </li>
      </ul>

      <form v-if="canWrite" class="row open" @submit.prevent="open">
        <div class="field">
          <label for="sc-year">{{ t('ps.school.yearLabel') }}</label>
          <input id="sc-year" v-model="newYear" type="text" pattern="\d{4}-\d{4}" placeholder="2026-2027" required />
        </div>
        <button type="submit" class="btn" :disabled="busy || !/^\d{4}-\d{4}$/.test(newYear)">{{ t('ps.school.openVersion') }}</button>
      </form>

      <form v-if="editing" class="infra" @submit.prevent="saveDraft">
        <h3>{{ editing.state === 'draft' ? t('ps.school.editing', { year: editing.year_label }) : t('ps.school.correcting', { year: editing.year_label }) }}</h3>
        <div class="field"><label for="in-desks">{{ t('ps.school.desks') }}</label><input id="in-desks" v-model.number="draft.desks_count" type="number" min="0" /></div>
        <div class="field"><label for="in-water">{{ t('ps.school.water') }}</label><input id="in-water" v-model.number="draft.water_points" type="number" min="0" /></div>
        <div class="field"><label for="in-girls">{{ t('ps.school.latrinesGirls') }}</label><input id="in-girls" v-model.number="draft.latrines_girls" type="number" min="0" /></div>
        <div class="field"><label for="in-boys">{{ t('ps.school.latrinesBoys') }}</label><input id="in-boys" v-model.number="draft.latrines_boys" type="number" min="0" /></div>
        <label class="check"><input v-model="draft.has_electricity" type="checkbox" />{{ t('ps.school.electricity') }}</label>
        <label class="check"><input v-model="draft.has_fence" type="checkbox" />{{ t('ps.school.fence') }}</label>
        <label class="check"><input v-model="draft.has_canteen" type="checkbox" />{{ t('ps.school.canteen') }}</label>
        <label class="check"><input v-model="draft.has_library" type="checkbox" />{{ t('ps.school.library') }}</label>
        <div class="field">
          <label for="in-net">{{ t('ps.school.network') }}</label>
          <select id="in-net" v-model="draft.network_observed">
            <option value="unknown">{{ t('ps.school.network.unknown') }}</option>
            <option value="none">{{ t('ps.school.network.none') }}</option>
            <option value="limited">{{ t('ps.school.network.limited') }}</option>
            <option value="available">{{ t('ps.school.network.available') }}</option>
          </select>
        </div>
        <div v-if="editing.state !== 'draft'" class="field">
          <label for="in-reason">{{ t('ps.school.reason') }}</label>
          <input id="in-reason" v-model="reason" type="text" required />
        </div>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="busy || (editing.state !== 'draft' && reason.trim() === '')">{{ t('ps.school.saveVersion') }}</button>
          <button type="button" class="btn btn--quiet" @click="editing = null">{{ t('ps.school.cancel') }}</button>
        </div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.form { display: grid; gap: 10px; max-width: 40rem; }
fieldset { border: 0; padding: 0; margin: 0; }
.versions { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.open { align-items: flex-end; }
.infra { display: grid; gap: 6px; max-width: 28rem; padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
</style>
