<script setup lang="ts">
// Personnel enseignant de l'école et besoins (élèves par enseignant, classes sans titulaire). La fiche de carrière n'est détaillée
// que pour le directeur ; les autres rôles ne voient que le statut.
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type StaffMember, type StaffNeeds } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useAction } from './usePs';

const { t } = useI18n();
const { error, run } = useAction();

const staff = ref<StaffMember[]>([]);
const needs = ref<StaffNeeds | null>(null);

onMounted(async () => {
  await run(async () => {
    staff.value = await publicSchools.staff();
    needs.value = await publicSchools.staffNeeds();
  });
});

const dash = (v: string | null | undefined): string => v ?? '—';
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.staff.title') }}</h1>
      <p class="lede">{{ t('ps.staff.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="needs" class="card" aria-labelledby="st-needs">
      <h2 id="st-needs">{{ t('ps.staff.needsTitle') }}</h2>
      <ul class="kpis">
        <li><strong>{{ needs.teachers }}</strong> {{ t('ps.staff.teachers') }}</li>
        <li><strong>{{ needs.pupils }}</strong> {{ t('ps.staff.pupils') }}</li>
        <li><strong>{{ needs.pupils_per_teacher ?? '—' }}</strong> {{ t('ps.staff.ratio') }}</li>
        <li v-if="needs.teachers_missing !== null"><strong>{{ needs.teachers_missing }}</strong> {{ t('ps.staff.missing') }}</li>
      </ul>
      <div v-if="needs.classes_without_homeroom.length > 0">
        <h3>{{ t('ps.staff.noHomeroom') }}</h3>
        <ul>
          <li v-for="c in needs.classes_without_homeroom" :key="c.structure_id">{{ c.label }}</li>
        </ul>
      </div>
    </section>

    <section class="card" aria-labelledby="st-list">
      <h2 id="st-list">{{ t('ps.staff.listTitle') }}</h2>
      <p v-if="staff.length === 0" class="muted">{{ t('ps.staff.none') }}</p>
      <div v-else class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('ps.staff.listTitle') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('ps.pupils.name') }}</th>
              <th scope="col">{{ t('ps.staff.grade') }}</th>
              <th scope="col">{{ t('ps.staff.status') }}</th>
              <th v-if="staff[0]?.civil_service_number !== undefined" scope="col">{{ t('ps.staff.matricule') }}</th>
              <th v-if="staff[0]?.service_start_on !== undefined" scope="col">{{ t('ps.staff.since') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in staff" :key="s.id">
              <th scope="row"><router-link :to="{ name: 'ps-staff-profile', params: { id: s.id } }">{{ s.name }}</router-link></th>
              <td>{{ dash(s.grade) }}</td>
              <td>{{ dash(s.public_status) }}</td>
              <td v-if="staff[0]?.civil_service_number !== undefined">{{ dash(s.civil_service_number ?? s.contract_number) }}</td>
              <td v-if="staff[0]?.service_start_on !== undefined">{{ s.service_start_on ? formatDate(s.service_start_on) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.kpis { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-wrap: wrap; gap: 8px 24px; }
.kpis strong { font-size: 1.4rem; }
</style>
