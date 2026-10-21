<script setup lang="ts">
// Élèves : accès à l'inscription, ouverture d'un dossier par son identifiant, et liste trimestrielle des élèves sans acte de
// naissance (DOC-07, RG-ELV-2) à transmettre à l'inspection.
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type BirthActTransmission, type WithoutBirthActRow } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { P } from './access';
import { useAction, usePermissions } from './usePs';

const { t, tp } = useI18n();
const router = useRouter();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const rows = ref<WithoutBirthActRow[]>([]);
const sent = ref<BirthActTransmission[]>([]);
const openId = ref('');
const forbidden = ref(false);
const canWrite = computed(() => can(...P.pupilsWrite));

async function load(): Promise<void> {
  await run(async () => {
    try {
      rows.value = await publicSchools.withoutBirthAct();
      sent.value = await publicSchools.birthActTransmissions();
    } catch {
      // L'enseignant n'a pas accès à la liste de l'école entière (404) : l'écran n'en montre que l'ouverture d'un dossier.
      forbidden.value = true;
    }
  });
}

async function transmit(): Promise<void> {
  const done = await run(() => publicSchools.transmitBirthActList());
  if (done) sent.value = [done, ...sent.value];
}

function open(): void {
  const id = openId.value.trim();
  if (id) void router.push({ name: 'ps-pupil', params: { id } });
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
      <form class="row" @submit.prevent="open">
        <div class="field grow">
          <label for="pu-id">{{ t('ps.pupils.openId') }}</label>
          <input id="pu-id" v-model="openId" type="text" autocomplete="off" />
        </div>
        <button type="submit" class="btn" :disabled="openId.trim() === ''">{{ t('ps.pupils.open') }}</button>
      </form>
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
</style>
