<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError } from '@/api/http';
import {
  publicPrimaryRegistry,
  type InfrastructureSnapshot,
  type PublicSchoolProfile,
  type TerritoryNode,
} from '@/api/public-primary-registry';
import { useI18n } from '@/i18n';

const { locale } = useI18n();
const schoolYear = `${new Date().getFullYear()}-${new Date().getFullYear() + 1}`;
const profile = ref<PublicSchoolProfile | null>(null);
const territory = ref<TerritoryNode[]>([]);
const infrastructure = ref<InfrastructureSnapshot | null>(null);
const loading = ref(true);
const error = ref('');
const isEnglish = computed(() => locale.value.startsWith('en'));

const typeLabel = (type: string): string => ({ region: isEnglish.value ? 'Region' : 'Région', departement: isEnglish.value ? 'Department' : 'Département', arrondissement: isEnglish.value ? 'District' : 'Arrondissement' }[type] ?? type);
const yesNo = (value: boolean): string => (value ? (isEnglish.value ? 'Yes' : 'Oui') : (isEnglish.value ? 'No' : 'Non'));
const connectivityLabel = (value: InfrastructureSnapshot['connectivity']): string => {
  const labels = isEnglish.value
    ? { unknown: 'Not recorded', none: 'Unavailable', limited: 'Limited', available: 'Available' }
    : { unknown: 'Non renseignée', none: 'Indisponible', limited: 'Limitée', available: 'Disponible' };
  return labels[value];
};

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    profile.value = await publicPrimaryRegistry.profile();
    const [lineage, snapshot] = await Promise.all([
      publicPrimaryRegistry.territoryLineage(profile.value.territory_id),
      publicPrimaryRegistry.infrastructure(schoolYear),
    ]);
    territory.value = lineage.slice().reverse();
    infrastructure.value = snapshot;
  } catch (cause) {
    error.value = cause instanceof ApiError
      ? cause.message
      : (isEnglish.value ? 'The registry could not be loaded.' : 'Le registre n’a pas pu être chargé.');
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="registry-page">
    <header class="registry-hero">
      <div>
        <p class="registry-eyebrow">{{ isEnglish ? 'PUBLIC PRIMARY · SCHOOL DIRECTORY' : 'PRIMAIRE PUBLIC · RÉPERTOIRE DES ÉCOLES' }}</p>
        <h1>{{ isEnglish ? 'School registry' : 'Registre des écoles' }}</h1>
        <p class="registry-lede">
          {{ isEnglish ? 'Consult the synthetic school profile and its annual infrastructure record.' : 'Consultez le profil synthétique de l’école et son état annuel des infrastructures.' }}
        </p>
      </div>
      <span class="registry-mark" aria-hidden="true">PP</span>
    </header>

    <p v-if="loading" class="registry-message" role="status">{{ isEnglish ? 'Loading registry…' : 'Chargement du registre…' }}</p>
    <p v-else-if="error" class="registry-message registry-message--error" role="alert">{{ error }}</p>

    <template v-else-if="profile">
      <section class="registry-grid" aria-label="School registry details">
        <article class="registry-card school-card">
          <div class="card-heading">
            <div class="school-icon" aria-hidden="true">⌂</div>
            <div>
              <p class="registry-eyebrow">{{ isEnglish ? 'SCHOOL IDENTITY' : 'IDENTITÉ DE L’ÉCOLE' }}</p>
              <h2>{{ profile.name }}</h2>
            </div>
          </div>
          <dl class="school-facts">
            <div><dt>{{ isEnglish ? 'Synthetic code' : 'Code synthétique' }}</dt><dd>{{ profile.school_code }}</dd></div>
            <div><dt>{{ isEnglish ? 'Establishment' : 'Établissement' }}</dt><dd>{{ profile.establishment_id }}</dd></div>
          </dl>
          <p class="synthetic-note">{{ isEnglish ? 'Synthetic local fixture · no official reference' : 'Fixture locale synthétique · aucune référence officielle' }}</p>
        </article>

        <article class="registry-card territory-card">
          <div class="section-heading">
            <div>
              <p class="registry-eyebrow">{{ isEnglish ? 'TERRITORIAL ATTACHMENT' : 'RATTACHEMENT TERRITORIAL' }}</p>
              <h2>{{ isEnglish ? 'Administrative path' : 'Parcours territorial' }}</h2>
            </div>
            <span class="map-pin" aria-hidden="true">⌖</span>
          </div>
          <ol v-if="territory.length" class="territory-path">
            <li v-for="node in territory" :key="node.id">
              <span class="territory-dot" aria-hidden="true"></span>
              <div><small>{{ typeLabel(node.type) }}</small><strong>{{ isEnglish ? node.label_en : node.label_fr }}</strong><code>{{ node.code }}</code></div>
            </li>
          </ol>
          <p v-else class="muted">{{ isEnglish ? 'No territory is assigned.' : 'Aucun territoire affecté.' }}</p>
          <p class="synthetic-note">{{ isEnglish ? 'Hierarchy view only; no geographic boundaries are available.' : 'Vue hiérarchique uniquement ; aucun contour géographique n’est disponible.' }}</p>
        </article>

        <article class="registry-card infrastructure-card">
          <div class="section-heading">
            <div>
              <p class="registry-eyebrow">{{ isEnglish ? 'ANNUAL SNAPSHOT' : 'SNAPSHOT ANNUEL' }}</p>
              <h2>{{ isEnglish ? 'Infrastructure' : 'Infrastructures' }}</h2>
            </div>
            <span v-if="infrastructure" class="status-pill" :class="`status-pill--${infrastructure.status}`">
              {{ infrastructure.status === 'closed' ? (isEnglish ? 'Closed' : 'Clôturé') : (isEnglish ? 'Draft' : 'Brouillon') }}
            </span>
          </div>
          <template v-if="infrastructure">
            <p class="snapshot-year">{{ infrastructure.school_year }} <span>· v{{ infrastructure.version }}</span></p>
            <div class="metric-grid">
              <div class="metric"><strong>{{ infrastructure.classrooms_count }}</strong><span>{{ isEnglish ? 'Classrooms' : 'Salles de classe' }}</span></div>
              <div class="metric"><strong>{{ infrastructure.latrines_count }}</strong><span>{{ isEnglish ? 'Latrines' : 'Latrines' }}</span></div>
              <div class="metric"><strong>{{ yesNo(infrastructure.water_access) }}</strong><span>{{ isEnglish ? 'Water access' : 'Accès à l’eau' }}</span></div>
              <div class="metric"><strong>{{ yesNo(infrastructure.electricity) }}</strong><span>{{ isEnglish ? 'Electricity' : 'Électricité' }}</span></div>
            </div>
            <div class="snapshot-copy">
              <p><span>{{ isEnglish ? 'Condition' : 'État' }}</span>{{ isEnglish ? infrastructure.classroom_condition_en : infrastructure.classroom_condition_fr }}</p>
              <p><span>{{ isEnglish ? 'Connectivity' : 'Connectivité' }}</span>{{ connectivityLabel(infrastructure.connectivity) }}</p>
              <p><span>{{ isEnglish ? 'Equipment' : 'Équipements' }}</span>{{ isEnglish ? infrastructure.equipment_en : infrastructure.equipment_fr }}</p>
            </div>
          </template>
          <p v-else class="empty-state">{{ isEnglish ? `No snapshot is available for ${schoolYear}.` : `Aucun snapshot disponible pour ${schoolYear}.` }}</p>
        </article>
      </section>
    </template>
  </main>
</template>

<style scoped>
.registry-page { --ink: #172c36; --muted: #67808b; --line: #deeaec; --teal: #126e67; --mint: #e7f5f0; max-width: 1180px; margin: 0 auto; padding: clamp(1.25rem, 3vw, 2.75rem); color: var(--ink); }
.registry-hero { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.7rem; padding: clamp(1.4rem, 3vw, 2.4rem); border-radius: 24px; background: radial-gradient(circle at 88% 16%, #bfe7d7 0, transparent 28%), linear-gradient(125deg, #f0f8f5, #e6f2f3); }
.registry-eyebrow { margin: 0 0 .45rem; color: var(--teal); font-size: .72rem; font-weight: 800; letter-spacing: .13em; }
h1, h2, p { margin-top: 0; }
h1 { margin-bottom: .5rem; font-size: clamp(1.8rem, 4vw, 2.55rem); letter-spacing: -.045em; }
.registry-lede { max-width: 680px; margin-bottom: 0; color: var(--muted); }
.registry-mark { display: grid; width: 76px; height: 76px; flex: 0 0 auto; place-items: center; border: 1px solid #c4e2d7; border-radius: 23px; background: #fff9; color: var(--teal); font-size: 1.35rem; font-weight: 900; transform: rotate(3deg); }
.registry-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.registry-card { min-width: 0; padding: 1.4rem; border: 1px solid var(--line); border-radius: 20px; background: #fff; box-shadow: 0 12px 35px #183b4810; }
.school-card { background: linear-gradient(160deg, #fff, #f8fbfb); }
.infrastructure-card { grid-column: 1 / -1; }
.card-heading, .section-heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.card-heading { justify-content: flex-start; }
.school-icon, .map-pin { display: grid; width: 48px; height: 48px; flex: 0 0 auto; place-items: center; border-radius: 15px; background: var(--mint); color: var(--teal); font-size: 1.55rem; }
.map-pin { border-radius: 50% 50% 50% 12px; transform: rotate(-45deg); }
.map-pin::first-letter { transform: rotate(45deg); }
h2 { margin-bottom: 0; font-size: 1.18rem; letter-spacing: -.02em; }
.school-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; margin: 1.5rem 0 1rem; }
.school-facts div, .metric { padding: .9rem; border-radius: 13px; background: #f5f9f9; }
dt, .metric span, .snapshot-copy span { display: block; margin-bottom: .3rem; color: var(--muted); font-size: .75rem; }
dd { margin: 0; overflow-wrap: anywhere; font-weight: 700; }
.synthetic-note { margin: 1rem 0 0; color: var(--muted); font-size: .76rem; }
.territory-path { position: relative; display: grid; gap: .2rem; margin: 1.2rem 0 0; padding: 0; list-style: none; }
.territory-path::before { position: absolute; top: 1.1rem; bottom: 1.5rem; left: 7px; width: 2px; background: #c8e5db; content: ''; }
.territory-path li { position: relative; display: flex; gap: .8rem; padding: .55rem 0; }
.territory-dot { z-index: 1; width: 16px; height: 16px; flex: 0 0 auto; margin-top: .15rem; border: 4px solid #d9f0e7; border-radius: 50%; background: var(--teal); }
.territory-path li div { display: grid; gap: .1rem; }
.territory-path small { color: var(--muted); }
.territory-path code { color: var(--teal); font-size: .74rem; }
.snapshot-year { margin: 1.1rem 0; font-weight: 800; }
.snapshot-year span { color: var(--muted); font-weight: 500; }
.status-pill { padding: .36rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 800; }
.status-pill--closed { background: #e7f5f0; color: #176d50; }
.status-pill--draft { background: #fff2d9; color: #8a5b00; }
.metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .7rem; }
.metric strong { display: block; margin-bottom: .25rem; color: var(--teal); font-size: 1.2rem; }
.snapshot-copy { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-top: 1rem; }
.snapshot-copy p { margin: 0; overflow-wrap: anywhere; }
.snapshot-copy span { font-weight: 700; }
.empty-state, .muted, .registry-message { color: var(--muted); }
.empty-state { margin: 1.5rem 0 .25rem; }
.registry-message { padding: 1.25rem; border: 1px solid var(--line); border-radius: 14px; background: white; }
.registry-message--error { border-color: #e7c1bb; background: #fff5f2; color: #883d30; }
@media (max-width: 760px) { .registry-grid { grid-template-columns: 1fr; } .infrastructure-card { grid-column: auto; } .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .snapshot-copy { grid-template-columns: 1fr; } }
@media (max-width: 520px) { .registry-hero { align-items: flex-start; } .registry-mark { width: 54px; height: 54px; border-radius: 17px; } .registry-card { padding: 1.1rem; } .school-facts { grid-template-columns: 1fr; } }
</style>