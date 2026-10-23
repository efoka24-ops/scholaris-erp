<script setup lang="ts">
// Classes sans enseignant (RG-PRE-2) et alertes d'assiduité (NOT-03, NOT-12, NOT-13). Chaque liste se charge selon sa propre
// permission : l'inspection voit les classes sans enseignant et les alertes qui lui sont adressées, jamais le nom d'un élève ;
// l'alerte d'absences répétées reste dans l'école.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type AttendanceAlert, type ClassRow, type UncoveredClass } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePs';

const { t, tp } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const date = ref(todayIso());
const kind = ref('');
const uncovered = ref<UncoveredClass[]>([]);
const alerts = ref<AttendanceAlert[]>([]);
const classes = ref<ClassRow[]>([]);
const loaded = ref(false);
const canUncovered = computed(() => can(...P.presenceRead));
const canAlerts = computed(() => can(...P.attendanceRead));
const KINDS = ['no_call', 'uncovered_class', 'repeated_absence'] as const;

const className = (id: string): string => classes.value.find((c) => c.structure_id === id)?.label ?? id;
const kindLabel = (k: string): string =>
  KINDS.includes(k as (typeof KINDS)[number]) ? t(`ps.alerts.kind.${k}` as 'ps.alerts.kind.no_call') : k;
const isPupilAlert = (a: AttendanceAlert): boolean => a.kind === 'repeated_absence';

async function load(): Promise<void> {
  loaded.value = false;
  await run(async () => {
    // Chaque liste est lue indépendamment : un rôle qui n'a pas l'une des deux permissions n'en perd pas l'autre.
    uncovered.value = canUncovered.value ? await publicSchools.uncoveredClasses(date.value) : [];
    alerts.value = canAlerts.value ? await publicSchools.alerts(kind.value || undefined) : [];
    if (classes.value.length === 0 && can(...P.pupilsRead)) {
      const year = await currentYear().catch(() => null);
      if (year) classes.value = await publicSchools.classes(year.id).catch(() => []);
    }
    loaded.value = true;
  });
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.alerts.title') }}</h1>
      <p class="lede">{{ t('ps.alerts.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="canUncovered" class="card" aria-labelledby="al-unc">
      <h2 id="al-unc">{{ t('ps.presence.uncoveredTitle') }}</h2>
      <div class="field filter">
        <label for="al-date">{{ t('ps.presence.date') }}</label>
        <input id="al-date" v-model="date" type="date" :max="todayIso()" @change="load" />
      </div>
      <p v-if="loaded && uncovered.length === 0" class="muted" role="status">{{ t('ps.presence.uncoveredNone') }}</p>
      <ul v-else>
        <li v-for="u in uncovered" :key="u.id">{{ className(u.structure_id) }} · {{ formatDate(u.date) }}</li>
      </ul>
    </section>

    <section v-if="canAlerts" class="card" aria-labelledby="al-list">
      <h2 id="al-list">{{ t('ps.presence.alertsTitle') }}</h2>
      <div class="field filter">
        <label for="al-kind">{{ t('ps.alerts.kind') }}</label>
        <select id="al-kind" v-model="kind" :disabled="busy" @change="load">
          <option value="">{{ t('ps.alerts.allKinds') }}</option>
          <option v-for="k in KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
        </select>
      </div>
      <p v-if="loaded && alerts.length === 0" class="muted" role="status">{{ t('ps.presence.alertsNone') }}</p>
      <ul v-else class="list">
        <li v-for="a in alerts" :key="a.id">
          <strong>{{ kindLabel(a.kind) }}</strong> · {{ formatDate(a.raised_on) }} · {{ tp('ps.presence.recipients', a.recipients) }}
          <div class="muted">
            <router-link v-if="isPupilAlert(a) && can(...P.pupilsRead)" :to="{ name: 'ps-pupil', params: { id: a.subject_id } }">{{ t('ps.alerts.openPupil') }}</router-link>
            <template v-else-if="!isPupilAlert(a)">{{ className(a.subject_id) }}</template>
          </div>
        </li>
      </ul>
    </section>

    <p v-if="!canUncovered && !canAlerts" class="muted">{{ t('ps.alerts.noAccess') }}</p>
  </div>
</template>

<style scoped>
.filter { max-width: 18rem; }
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
</style>
