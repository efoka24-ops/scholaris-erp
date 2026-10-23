<script setup lang="ts">
// Classes de l'école (effectifs, alerte de seuil, titulaire daté, création) et répartition proposée des élèves (suggestion
// en lecture seule : aucune affectation n'est faite sans décision du directeur).
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listOrgUnits, type OrgUnit } from '@/api/admin';
import {
  publicSchools,
  type ClassHeadcount,
  type ClassRow,
  type DistributionProposal,
  type HomeroomEntry,
  type StaffMember,
} from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePs';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const yearId = ref('');
const classes = ref<ClassRow[]>([]);
const staff = ref<StaffMember[]>([]);
const orgUnits = ref<OrgUnit[]>([]);
const opened = ref<string | null>(null);
const headcount = ref<ClassHeadcount | null>(null);
const history = ref<HomeroomEntry[]>([]);
const homeroom = reactive({ staffId: '', startsOn: todayIso() });
const selection = ref<string[]>([]);
const proposal = ref<DistributionProposal | null>(null);
const form = reactive({ name: '', orgUnitId: '', levels: '', shift: 'full_day' as 'full_day' | 'morning' | 'afternoon', capacity: '' });
const canWrite = computed(() => can(...P.pupilsWrite));
const canRegister = computed(() => can(...P.attendanceRead));

const staffName = (id: string | null): string => (id ? (staff.value.find((s) => s.staff_id === id)?.name ?? id) : t('ps.classes.noHomeroom'));

async function load(): Promise<void> {
  await run(async () => {
    const year = await currentYear();
    if (!year) return;
    yearId.value = year.id;
    classes.value = await publicSchools.classes(year.id);
    if (can(...P.staffRead)) staff.value = await publicSchools.staff().catch(() => []);
    if (canWrite.value) orgUnits.value = await listOrgUnits().catch(() => []);
  });
}

async function toggle(c: ClassRow): Promise<void> {
  if (opened.value === c.structure_id) {
    opened.value = null;
    return;
  }
  opened.value = c.structure_id;
  headcount.value = null;
  history.value = [];
  homeroom.staffId = c.homeroom_staff_id ?? '';
  await run(async () => {
    headcount.value = await publicSchools.headcount(c.structure_id);
    history.value = await publicSchools.homeroomHistory(c.structure_id);
  });
}

async function assign(c: ClassRow): Promise<void> {
  const done = await run(() => publicSchools.assignHomeroom(c.structure_id, homeroom.staffId || null, homeroom.startsOn));
  if (done) {
    c.homeroom_staff_id = done.homeroom_staff_id;
    history.value = await publicSchools.homeroomHistory(c.structure_id).catch(() => history.value);
  }
}

async function create(): Promise<void> {
  const levels = form.levels.split(/[\s,;]+/).filter(Boolean);
  const capacity = Number.parseInt(form.capacity, 10);
  const created = await run(() =>
    publicSchools.createClass({
      name: form.name.trim(),
      academic_year_id: yearId.value,
      org_unit_id: form.orgUnitId,
      level_codes: levels,
      shift: form.shift,
      ...(Number.isFinite(capacity) ? { capacity } : {}),
    }),
  );
  if (created) {
    form.name = '';
    form.levels = '';
    form.capacity = '';
    await load();
  }
}

async function propose(): Promise<void> {
  proposal.value = null;
  proposal.value = (await run(() => publicSchools.distributionProposal(selection.value))) ?? null;
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.classes.title') }}</h1>
      <p class="lede">{{ t('ps.classes.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card" aria-labelledby="cl-list">
      <h2 id="cl-list">{{ t('ps.classes.listTitle') }}</h2>
      <p v-if="classes.length === 0" class="muted">{{ t('ps.classes.none') }}</p>
      <ul class="list">
        <li v-for="c in classes" :key="c.structure_id">
          <div class="row row--between">
            <label v-if="canWrite" class="check">
              <input v-model="selection" type="checkbox" :value="c.structure_id" :aria-label="t('ps.classes.select', { name: c.label })" />
            </label>
            <div class="grow">
              <strong>{{ c.label }}</strong>
              <span v-if="c.twinned" class="tag tag--neutral">{{ t('ps.classes.twinned') }}</span>
              <div class="muted">{{ c.level_codes.join(' · ') }} — {{ staffName(c.homeroom_staff_id) }}</div>
            </div>
            <span class="count" :class="{ 'count--alert': c.capacity_alert }">
              {{ t('ps.classes.headcount', { count: c.headcount ?? 0 }) }}
              <span v-if="c.capacity_alert" class="tag tag--warning">{{ t('ps.classes.alert', { threshold: c.alert_threshold ?? 0 }) }}</span>
            </span>
            <button type="button" class="btn btn--quiet" :aria-expanded="opened === c.structure_id" @click="toggle(c)">
              {{ opened === c.structure_id ? t('ps.classes.close') : t('ps.classes.details') }}
            </button>
          </div>

          <div v-if="opened === c.structure_id" class="detail">
            <p v-if="canRegister">
              <router-link :to="{ name: 'ps-register', params: { id: c.structure_id } }">{{ t('ps.classes.register', { name: c.label }) }}</router-link>
            </p>
            <div v-if="headcount">
              <h3>{{ t('ps.classes.breakdown') }}</h3>
              <p>
                {{ t('ps.classes.bySex', { female: headcount.by_sex.female, male: headcount.by_sex.male }) }}
                <span v-if="headcount.by_sex.unknown > 0"> · {{ t('ps.classes.sexUnknown', { count: headcount.by_sex.unknown }) }}</span>
              </p>
              <ul class="ages">
                <li v-for="(n, age) in headcount.by_age" :key="age">{{ t('ps.classes.age', { age, count: n }) }}</li>
              </ul>
            </div>
            <div v-if="canWrite">
              <h3>{{ t('ps.classes.homeroom') }}</h3>
              <form class="grid2" @submit.prevent="assign(c)">
                <div class="field">
                  <label :for="`hr-staff-${c.structure_id}`">{{ t('ps.classes.teacher') }}</label>
                  <select :id="`hr-staff-${c.structure_id}`" v-model="homeroom.staffId">
                    <option value="">{{ t('ps.classes.noHomeroom') }}</option>
                    <option v-for="s in staff" :key="s.staff_id" :value="s.staff_id">{{ s.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label :for="`hr-date-${c.structure_id}`">{{ t('ps.classes.startsOn') }}</label>
                  <input :id="`hr-date-${c.structure_id}`" v-model="homeroom.startsOn" type="date" required />
                </div>
                <button type="submit" class="btn" :disabled="busy">{{ t('ps.classes.assign') }}</button>
              </form>
              <ul v-if="history.length > 0" class="ages">
                <li v-for="h in history" :key="h.starts_on + h.staff_id">
                  {{ staffName(h.staff_id) }} : {{ formatDate(h.starts_on) }} → {{ h.ends_on ? formatDate(h.ends_on) : t('ps.classes.current') }}
                </li>
              </ul>
            </div>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="canWrite" class="card" aria-labelledby="cl-dist">
      <h2 id="cl-dist">{{ t('ps.classes.distTitle') }}</h2>
      <p class="muted">{{ t('ps.classes.distIntro') }}</p>
      <button type="button" class="btn" :disabled="busy || selection.length < 2" @click="propose">{{ t('ps.classes.distRun', { count: selection.length }) }}</button>
      <div v-if="proposal" class="proposal" role="region" :aria-label="t('ps.classes.distResult')">
        <ul class="ages">
          <li v-for="p in proposal.classes" :key="p.structure_id">
            <strong>{{ p.label }}</strong> : {{ t('ps.classes.distLine', { count: p.headcount, female: p.female, male: p.male }) }}
          </li>
        </ul>
        <p>{{ t('ps.classes.distMoves', { count: proposal.moves.length }) }}</p>
      </div>
    </section>

    <section v-if="canWrite" class="card" aria-labelledby="cl-new">
      <h2 id="cl-new">{{ t('ps.classes.newTitle') }}</h2>
      <form class="grid2" @submit.prevent="create">
        <div class="field">
          <label for="cl-name">{{ t('ps.classes.name') }}</label>
          <input id="cl-name" v-model="form.name" type="text" maxlength="160" required />
        </div>
        <div class="field">
          <label for="cl-org">{{ t('ps.classes.orgUnit') }}</label>
          <select id="cl-org" v-model="form.orgUnitId" required>
            <option value="" disabled>{{ t('ps.classes.pick') }}</option>
            <option v-for="o in orgUnits" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="cl-levels">{{ t('ps.classes.levels') }}</label>
          <input id="cl-levels" v-model="form.levels" type="text" required aria-describedby="cl-levels-hint" />
          <span id="cl-levels-hint" class="hint">{{ t('ps.classes.levelsHint') }}</span>
        </div>
        <div class="field">
          <label for="cl-shift">{{ t('ps.classes.shift') }}</label>
          <select id="cl-shift" v-model="form.shift">
            <option value="full_day">{{ t('ps.classes.shift.full_day') }}</option>
            <option value="morning">{{ t('ps.classes.shift.morning') }}</option>
            <option value="afternoon">{{ t('ps.classes.shift.afternoon') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="cl-cap">{{ t('ps.classes.capacity') }}</label>
          <input id="cl-cap" v-model="form.capacity" type="number" min="1" max="10000" inputmode="numeric" />
        </div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy || !yearId || form.name.trim() === '' || form.orgUnitId === '' || form.levels.trim() === ''">{{ t('ps.classes.create') }}</button></div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.grow { flex: 1; }
.count { font-weight: 700; }
.detail { display: grid; gap: 12px; padding: 12px 0 0; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.ages { margin: 6px 0; padding-left: 1.2rem; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
