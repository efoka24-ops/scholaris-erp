<script setup lang="ts">
// Contrôle de l'inspection sur la présence des enseignants (EX-PRE-03) : échantillon aléatoire de déclarations « présent » non
// encore contrôlées ; l'inspecteur confirme ou signale un écart après vérification sur place. La finalité (contrôle) est inscrite au
// journal des consultations nominatives ; une présence contrôlée n'est plus modifiable par l'école.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type CheckResult, type StaffMember, type TeacherPresence } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { useAction } from './usePs';

const { t } = useI18n();
const { error, busy, run } = useAction();

const date = ref(todayIso());
const sample = ref<TeacherPresence[]>([]);
const done = ref<Array<{ row: TeacherPresence; result: CheckResult }>>([]);
const staff = ref<StaffMember[]>([]);
const loaded = ref(false);

const personOf = (row: TeacherPresence): StaffMember | undefined => staff.value.find((s) => s.staff_id === row.staff_id);
const nameOf = (row: TeacherPresence): string => personOf(row)?.name ?? t('ps.control.unknownStaff');
const remaining = computed(() => sample.value.filter((r) => !done.value.some((d) => d.row.id === r.id)));

async function load(): Promise<void> {
  done.value = [];
  loaded.value = false;
  await run(async () => {
    sample.value = await publicSchools.controlSample(date.value);
    staff.value = await publicSchools.staff().catch(() => []);
    loaded.value = true;
  });
}

async function check(row: TeacherPresence, result: CheckResult): Promise<void> {
  const person = personOf(row);
  if (!person) return;
  const updated = await run(() => publicSchools.checkPresence(person.id, row.date, result));
  if (updated) done.value = [...done.value, { row: updated, result: updated.check_result === 'discrepancy' ? 'discrepancy' : result }];
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.control.title') }}</h1>
      <p class="lede">{{ t('ps.control.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div class="row filter">
      <div class="field">
        <label for="ct-date">{{ t('ps.presence.date') }}</label>
        <input id="ct-date" v-model="date" type="date" :max="todayIso()" @change="load" />
      </div>
      <button type="button" class="btn" :disabled="busy" @click="load">{{ t('ps.control.draw') }}</button>
    </div>

    <section class="card" aria-labelledby="ct-sample">
      <h2 id="ct-sample">{{ t('ps.control.sampleTitle', { date: formatDate(date) }) }}</h2>
      <p v-if="loaded && sample.length === 0" class="muted" role="status">{{ t('ps.control.none') }}</p>
      <p v-else-if="loaded && remaining.length === 0" class="notice notice--success" role="status">{{ t('ps.control.allDone') }}</p>
      <ul class="people">
        <li v-for="row in remaining" :key="row.id" class="person">
          <div class="who">
            <strong>{{ nameOf(row) }}</strong>
            <span class="tag tag--neutral">{{ t('ps.presence.present') }}</span>
            <span v-if="row.arrived_at" class="muted">{{ t('ps.control.arrivedAt', { time: row.arrived_at }) }}</span>
          </div>
          <div class="row">
            <button type="button" class="btn btn--primary" :disabled="busy || !personOf(row)" @click="check(row, 'confirmed')">{{ t('ps.control.confirm') }}</button>
            <button type="button" class="btn" :disabled="busy || !personOf(row)" @click="check(row, 'discrepancy')">{{ t('ps.control.discrepancy') }}</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="done.length > 0" class="card" aria-labelledby="ct-done">
      <h2 id="ct-done">{{ t('ps.control.doneTitle') }}</h2>
      <ul>
        <li v-for="d in done" :key="d.row.id">
          {{ nameOf(d.row) }} ·
          <span class="tag" :class="d.result === 'confirmed' ? 'tag--success' : 'tag--warning'">{{ d.result === 'confirmed' ? t('ps.control.confirmed') : t('ps.control.discrepant') }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.filter { align-items: flex-end; }
.people { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.person { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); display: grid; gap: 8px; }
.who { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
</style>
