<script setup lang="ts">
// Élèves : accès à l'inscription, recherche d'un élève pour ouvrir son dossier, et liste trimestrielle des élèves sans acte de
// naissance (DOC-07, RG-ELV-2) à transmettre à l'inspection.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type BirthActTransmission, type RosterPupil, type WithoutBirthActRow } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { P } from './access';
import PurposePicker from './PurposePicker.vue';
import { fullName, useAction, usePermissions } from './usePs';

const { t, tp } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const rows = ref<WithoutBirthActRow[]>([]);
const sent = ref<BirthActTransmission[]>([]);
const term = ref('');
const hits = ref<RosterPupil[]>([]);
const nextHits = ref<string | null>(null);
const searched = ref(false);
const forbidden = ref(false);
const canWrite = computed(() => can(...P.pupilsWrite));

async function load(): Promise<void> {
  await run(async () => {
    try {
      rows.value = await publicSchools.withoutBirthAct();
      sent.value = await publicSchools.birthActTransmissions();
    } catch {
      // L'enseignant n'a pas accès à la liste de l'école entière (404) : l'écran n'en montre que la recherche d'un élève.
      forbidden.value = true;
    }
  });
}

async function transmit(): Promise<void> {
  const done = await run(() => publicSchools.transmitBirthActList());
  if (done) sent.value = [done, ...sent.value];
}

/** Recherche par nom, prénom ou numéro d'apprenant ; « Voir plus » suit le curseur du serveur. */
async function search(more = false): Promise<void> {
  const query = term.value.trim();
  if (query === '') return;
  const res = await run(() => publicSchools.searchPupils(query, more ? nextHits.value : null));
  if (!res) return;
  hits.value = more ? [...hits.value, ...res.items] : res.items;
  nextHits.value = res.next;
  searched.value = true;
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.pupils.title') }}</h1>
      <p class="lede">{{ t('ps.pupils.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div class="row">
      <router-link v-if="canWrite" class="btn btn--primary" :to="{ name: 'ps-enrol' }">{{ t('ps.pupils.enrol') }}</router-link>
      <router-link v-if="canWrite" class="btn" :to="{ name: 'ps-transfers' }">{{ t('ps.transfers.title') }}</router-link>
      <router-link v-if="canWrite" class="btn" :to="{ name: 'ps-dropouts' }">{{ t('ps.dropouts.title') }}</router-link>
    </div>

    <section class="card" aria-labelledby="pu-open">
      <h2 id="pu-open">{{ t('ps.pupils.openTitle') }}</h2>
      <PurposePicker id="pu-purpose" />
      <form class="row" role="search" @submit.prevent="search()">
        <div class="field grow">
          <label for="pu-search">{{ t('ps.pupils.search') }}</label>
          <input id="pu-search" v-model="term" type="search" autocomplete="off" aria-describedby="pu-search-hint" />
          <span id="pu-search-hint" class="hint">{{ t('ps.pupils.searchHint') }}</span>
        </div>
        <button type="submit" class="btn" :disabled="busy || term.trim() === ''">{{ t('ps.pupils.searchGo') }}</button>
      </form>
      <p v-if="searched && hits.length === 0" class="muted" role="status">{{ t('ps.pupils.searchNone') }}</p>
      <ul v-if="hits.length > 0" class="hits" :aria-label="t('ps.pupils.searchResults')">
        <li v-for="h in hits" :key="h.person_id">
          <router-link class="hit" :to="{ name: 'ps-pupil', params: { id: h.person_id } }">
            <strong>{{ fullName(h) }}</strong>
            <span class="muted">{{ h.learner_number ?? '—' }}</span>
          </router-link>
        </li>
      </ul>
      <button v-if="nextHits" type="button" class="btn btn--quiet" :disabled="busy" @click="search(true)">{{ t('ps.pupils.searchMore') }}</button>
    </section>

    <section v-if="!forbidden" class="card" aria-labelledby="pu-act">
      <h2 id="pu-act">{{ t('ps.pupils.noActTitle') }}</h2>
      <p class="muted">{{ t('ps.pupils.noActIntro') }}</p>
      <p v-if="rows.length === 0">{{ t('ps.pupils.noActNone') }}</p>
      <p v-else role="status">{{ tp('ps.pupils.noActCount', rows.length) }}</p>
      <div v-if="rows.length > 0" class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('ps.pupils.noActTitle') }}</caption>
          <thead>
            <tr><th scope="col">{{ t('ps.pupils.name') }}</th><th scope="col">{{ t('ps.pupils.number') }}</th><th scope="col">{{ t('ps.pupils.registeredOn') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.person_id">
              <td><router-link :to="{ name: 'ps-pupil', params: { id: r.person_id } }">{{ r.last_name.toUpperCase() }} {{ r.first_name }}</router-link></td>
              <td>{{ r.learner_number ?? '—' }}</td>
              <td>{{ formatDate(r.registered_on) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <button v-if="canWrite" type="button" class="btn" :disabled="busy" @click="transmit">{{ t('ps.pupils.transmit') }}</button>
      <ul v-if="sent.length > 0" class="log">
        <li v-for="s in sent" :key="s.quarter + s.generated_on">
          {{ t('ps.pupils.transmitted', { quarter: s.quarter, count: s.pupil_count, date: formatDate(s.transmitted_on ?? s.generated_on) }) }}
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.grow { flex: 1; margin-bottom: 0; }
.row { align-items: flex-end; margin-bottom: 12px; }
.log { margin: 10px 0 0; padding-left: 1.2rem; }
.hits { list-style: none; margin: 0 0 8px; padding: 0; display: grid; gap: 4px; }
.hit { display: flex; justify-content: space-between; gap: 12px; min-height: 44px; align-items: center; padding: 6px 10px; border: 1px solid var(--border, #dcdcd8); color: inherit; text-decoration: none; }
.hit:hover, .hit:focus-visible { border-color: var(--ink); }
</style>
