<script setup lang="ts">
// Présence des enseignants (RG-PRE) : déclaration du jour par le directeur, classes sans enseignant, alertes d'assiduité.
// Une présence contrôlée par l'inspection ne peut plus être modifiée.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicSchools, publicSchoolsP2, type AttendanceAlert, type ClassRow, type Presence, type StaffMember, type TeacherPresence, type UncoveredClass } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePs';

const { t, tp, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const date = ref(todayIso());
const staff = ref<StaffMember[]>([]);
const rows = ref<TeacherPresence[]>([]);
const uncovered = ref<UncoveredClass[]>([]);
const alerts = ref<AttendanceAlert[]>([]);
const classes = ref<ClassRow[]>([]);
const saved = ref('');
const draft = reactive<Record<string, { presence: Presence; reason: string; replacement: string; arrivedAt: string }>>({});
const canWrite = computed(() => can(...P.presenceWrite));
const PRESENCES: Presence[] = ['present', 'absent', 'permission', 'mission'];

const byStaff = computed(() => new Map(rows.value.map((r) => [r.staff_id, r])));
const className = (id: string): string => classes.value.find((c) => c.structure_id === id)?.label ?? id;
const presenceLabel = (p: Presence): string => t(p === 'present' ? 'ps.presence.present' : p === 'absent' ? 'ps.presence.absent' : p === 'permission' ? 'ps.presence.permission' : 'ps.presence.mission');
const needsReason = (p: Presence): boolean => p !== 'present';
/** DOC-22 : synthèse mensuelle numérotée du mois de la date affichée, émise par le directeur. */
async function summaryPdf(): Promise<void> {
  await run(async () => openDocument((await publicSchoolsP2.presenceDocument(date.value.slice(0, 7), locale.value === 'en' ? 'en' : 'fr')).id));
}

function init(): void {
  for (const s of staff.value) {
    const done = byStaff.value.get(s.staff_id);
    draft[s.id] = { presence: done?.presence ?? 'present', reason: done?.reason ?? '', replacement: '', arrivedAt: done?.arrived_at ?? '' };
  }
}

async function load(): Promise<void> {
  saved.value = '';
  await run(async () => {
    staff.value = can(...P.staffRead) ? await publicSchools.staff() : [];
    rows.value = await publicSchools.teacherPresence(date.value);
    uncovered.value = await publicSchools.uncoveredClasses(date.value);
    alerts.value = can(...P.attendanceRead) ? await publicSchools.alerts() : [];
    if (classes.value.length === 0) {
      const year = await currentYear();
      if (year) classes.value = await publicSchools.classes(year.id);
    }
    init();
  });
}

async function declare(s: StaffMember): Promise<void> {
  const d = draft[s.id];
  if (!d) return;
  const done = await run(() =>
    publicSchools.declarePresence(s.id, date.value, {
      presence: d.presence,
      reason: needsReason(d.presence) ? d.reason.trim() : null,
      replacement_person_id: d.presence !== 'present' && d.replacement ? d.replacement : null,
      arrived_at: d.presence === 'present' && d.arrivedAt ? d.arrivedAt : null,
      client_uuid: crypto.randomUUID(),
    }),
  );
  if (done) {
    saved.value = s.name;
    rows.value = [...rows.value.filter((r) => r.staff_id !== done.staff_id), done];
    uncovered.value = await run(() => publicSchools.uncoveredClasses(date.value)).then((r) => r ?? uncovered.value);
  }
}

async function allPresent(): Promise<void> {
  for (const s of staff.value) {
    if (byStaff.value.has(s.staff_id)) continue;
    const d = draft[s.id];
    if (d) d.presence = 'present';
    await declare(s);
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.presence.title') }}</h1>
      <p class="lede">{{ t('ps.presence.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p class="links">
      <router-link :to="{ name: 'ps-alerts' }">{{ t('ps.presence.alertsLink') }}</router-link>
      <router-link v-if="can(...P.presenceValidate)" :to="{ name: 'ps-control' }">{{ t('ps.presence.controlLink') }}</router-link>
    </p>

    <div class="field filter">
      <label for="pr-date">{{ t('ps.presence.date') }}</label>
      <input id="pr-date" v-model="date" type="date" :max="todayIso()" @change="load" />
    </div>
    <p v-if="saved" class="notice notice--success" role="status">{{ t('ps.presence.saved', { name: saved }) }}</p>
    <p v-if="canWrite && can('documents.read')">
      <button type="button" class="btn" :disabled="busy" @click="summaryPdf">{{ t('pp.doc.presencePdf', { month: date.slice(0, 7) }) }}</button>
    </p>

    <section v-if="staff.length > 0" class="card" aria-labelledby="pr-list">
      <div class="row row--between">
        <h2 id="pr-list">{{ t('ps.presence.listTitle', { date: formatDate(date) }) }}</h2>
        <button v-if="canWrite" type="button" class="btn" :disabled="busy" @click="allPresent">{{ t('ps.presence.allPresent') }}</button>
      </div>
      <ul class="people">
        <li v-for="s in staff" :key="s.id" class="person">
          <div class="who">
            <strong>{{ s.name }}</strong>
            <span v-if="byStaff.get(s.staff_id)" class="tag tag--neutral">{{ presenceLabel(byStaff.get(s.staff_id)!.presence) }}</span>
            <span v-else class="tag tag--warning">{{ t('ps.presence.notDeclared') }}</span>
            <span v-if="byStaff.get(s.staff_id)?.check_result" class="tag tag--success">{{ t('ps.presence.checked') }}</span>
          </div>
          <div v-if="canWrite && draft[s.id] && !byStaff.get(s.staff_id)?.check_result" class="editor">
            <div class="field">
              <label :for="`pr-st-${s.id}`">{{ t('ps.presence.state') }}</label>
              <select :id="`pr-st-${s.id}`" v-model="draft[s.id]!.presence">
                <option v-for="p in PRESENCES" :key="p" :value="p">{{ presenceLabel(p) }}</option>
              </select>
            </div>
            <div v-if="draft[s.id]!.presence !== 'present'" class="field">
              <label :for="`pr-re-${s.id}`">{{ t('ps.presence.reason') }}</label>
              <input :id="`pr-re-${s.id}`" v-model="draft[s.id]!.reason" type="text" maxlength="255" />
            </div>
            <div v-if="draft[s.id]!.presence !== 'present'" class="field">
              <label :for="`pr-rp-${s.id}`">{{ t('ps.presence.replacement') }}</label>
              <select :id="`pr-rp-${s.id}`" v-model="draft[s.id]!.replacement">
                <option value="">{{ t('ps.presence.noReplacement') }}</option>
                <option v-for="o in staff.filter((x) => x.id !== s.id)" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </div>
            <button type="button" class="btn btn--primary" :disabled="busy || (draft[s.id]!.presence !== 'present' && draft[s.id]!.reason.trim() === '')" @click="declare(s)">
              {{ t('ps.presence.declare') }}
            </button>
          </div>
        </li>
      </ul>
    </section>
    <p v-else class="muted">{{ t('ps.presence.directorOnly') }}</p>

    <section class="card" aria-labelledby="pr-unc">
      <h2 id="pr-unc">{{ t('ps.presence.uncoveredTitle') }}</h2>
      <p v-if="uncovered.length === 0" class="muted">{{ t('ps.presence.uncoveredNone') }}</p>
      <ul v-else>
        <li v-for="u in uncovered" :key="u.id">{{ className(u.structure_id) }}</li>
      </ul>
    </section>

    <section class="card" aria-labelledby="pr-alerts">
      <h2 id="pr-alerts">{{ t('ps.presence.alertsTitle') }}</h2>
      <p v-if="alerts.length === 0" class="muted">{{ t('ps.presence.alertsNone') }}</p>
      <ul v-else>
        <li v-for="a in alerts" :key="a.id">{{ t('ps.presence.alertLine', { kind: a.kind, date: formatDate(a.raised_on) }) }} · {{ tp('ps.presence.recipients', a.recipients) }}</li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.filter { max-width: 14rem; }
.links { display: flex; flex-wrap: wrap; gap: 8px 20px; }
.people { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.person { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); display: grid; gap: 8px; }
.who { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.editor { display: grid; grid-template-columns: 1fr; gap: 0 12px; align-items: end; }
@media (min-width: 701px) { .editor { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
</style>
