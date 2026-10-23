<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { tutelage, type Consultation, type Convention, type PortalRecord } from '@/api/higher-nationalexams';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import YearField from '../YearField.vue';
import { useNationalI18n } from '../national-exams/i18n';
import { T } from '../national-exams/perms';
import TutelageFrame from './TutelageFrame.vue';

// Accès de l'université de tutelle (R22, EX-TUT-04) : inscrits de ses filières en lecture seule ; journal des consultations pour la direction.
const { tx } = useNationalI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const view = useHigherAction();
const journal = useHigherAction();

const programId = ref('');
const yearId = ref('');
const records = ref<PortalRecord[]>([]);
const convention = ref<Convention | null>(null);
const consultations = ref<Consultation[]>([]);
const loaded = ref(false);
const names = useHigherNames(() => consultations.value.map((c) => c.user_id));
const canRead = computed(() => ctx.can(...T.external));
const canJournal = computed(() => ctx.can(...T.internal));
const programs = computed(() => higher.programs.filter((p) => p.requires_tutelage));
const closed = computed(() => view.error.value instanceof ApiError && view.error.value.status === 404);

async function show(): Promise<void> {
  loaded.value = false;
  const res = await view.run(() => tutelage.portalEnrolments(programId.value, yearId.value));
  if (res) {
    records.value = res.data;
    convention.value = res.convention;
    loaded.value = true;
    await loadJournal();
  }
}

async function loadJournal(): Promise<void> {
  if (!canJournal.value) return;
  const res = await journal.run(() => tutelage.consultations({ ...(higher.scope() as Record<string, string>), per_page: 100 }));
  if (res) consultations.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  await loadJournal();
});
</script>

<template>
  <TutelageFrame :title="tx('htu.nav.portal')" :lede="tx('htu.portalLede')" @school-change="loadJournal">
    <form v-if="canRead" class="hi-form" role="search" data-test="portal-form" @submit.prevent="show">
      <div class="field">
        <label for="tp-program">{{ tx('htu.program') }}</label>
        <select id="tp-program" v-model="programId" required><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="tp-year" v-model="yearId" :label="tx('htu.year')" />
      <button type="submit" class="btn btn--primary" :disabled="view.busy.value || !programId || !yearId">{{ tx('htu.portal.show') }}</button>
    </form>
    <p v-if="closed" class="notice" role="status" data-test="portal-closed">{{ tx('htu.portalClosed') }}</p>
    <ErrorNotice v-else :error="view.error.value" />

    <div v-if="loaded" class="table-wrap" data-test="portal-table">
      <p v-if="convention" class="muted">{{ convention.university_name }} · {{ convention.convention_reference ?? '—' }} · {{ convention.convention_ends_on ?? tx('htu.noEnd') }}</p>
      <table>
        <thead>
          <tr><th scope="col">{{ tx('htu.matricule') }}</th><th scope="col">{{ tx('htu.lastName') }}</th><th scope="col">{{ tx('htu.firstName') }}</th><th scope="col">{{ tx('htu.levelCode') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in records" :key="`${r.matricule}-${i}`" data-test="portal-row"><td>{{ r.matricule ?? '—' }}</td><th scope="row">{{ r.last_name }}</th><td>{{ r.first_names }}</td><td>{{ r.level_code ?? '—' }}</td></tr>
          <tr v-if="records.length === 0"><td colspan="4" class="muted">{{ tx('hx.noCandidate') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="canJournal" class="card hi-section" data-test="journal">
      <h2>{{ tx('htu.journal') }}</h2>
      <ErrorNotice :error="journal.error.value" />
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ tx('htu.date') }}</th><th scope="col">{{ tx('htu.user') }}</th><th scope="col">{{ tx('htu.program') }}</th><th scope="col">{{ tx('htu.resource') }}</th><th scope="col" class="num">{{ tx('htu.rows') }}</th></tr></thead>
          <tbody>
            <tr v-for="c in consultations" :key="c.id" data-test="journal-row">
              <td>{{ c.created_at }}</td><td>{{ names.name(c.user_id) }}</td><td>{{ higher.programName(c.program_id) }} · {{ higher.yearName(c.academic_year_id) }}</td><td>{{ c.resource_kind }}</td><td class="num">{{ c.rows_count }}</td>
            </tr>
            <tr v-if="consultations.length === 0"><td colspan="5" class="muted">{{ tx('htu.noJournal') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </TutelageFrame>
</template>
