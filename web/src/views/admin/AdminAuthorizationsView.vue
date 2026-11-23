<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { createAuthorization, listAuthorizations, listStructures, type Structure, type StructureAuthorization } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { todayIso } from '@/lib/format';

// Autorisations d'ouverture des classes, sections et filières (routes /authorizations du socle Organisation, C-115) : l'inscription
// reste refusée tant qu'aucune autorisation n'est valide ce jour-là pour la structure (décision du propriétaire, public compris).
const { t } = useI18n();
const ctx = useContextStore();

const structures = ref<Structure[]>([]);
const authorizations = ref<StructureAuthorization[]>([]);
const loaded = ref(false);
const done = ref(false);
const list = useAction();
const save = useAction();
const today = todayIso();

const form = reactive({ structure_id: '', authority: '', reference: '', valid_from: '', valid_to: '' });
const touched = ref(false);

// Les niveaux ne s'inscrivent pas : seules les structures qui accueillent des élèves sont proposées en premier.
const candidates = computed(() => [...structures.value].sort((a, b) => Number(a.kind === 'level') - Number(b.kind === 'level') || a.name.localeCompare(b.name)));
const structureName = (id: string): string => structures.value.find((s) => s.id === id)?.name ?? id;
const status = (a: StructureAuthorization): 'valid' | 'expired' | 'upcoming' => (a.valid_to < today ? 'expired' : a.valid_from > today ? 'upcoming' : 'valid');
const missing = computed(() =>
  structures.value.filter((s) => s.kind !== 'level' && !authorizations.value.some((a) => a.structure_id === s.id && status(a) === 'valid')),
);
const errors = computed(() => ({
  structure: form.structure_id === '',
  reference: form.reference.trim() === '',
  dates: form.valid_from === '' || form.valid_to === '',
  order: form.valid_from !== '' && form.valid_to !== '' && form.valid_to < form.valid_from,
}));

async function load(): Promise<void> {
  const res = await list.run(async () => ({ structures: await listStructures(), authorizations: await listAuthorizations() }));
  if (res) {
    structures.value = res.structures;
    authorizations.value = res.authorizations;
  }
  loaded.value = true;
}

async function submit(): Promise<void> {
  touched.value = true;
  done.value = false;
  if (Object.values(errors.value).some(Boolean)) return;
  const res = await save.run(() =>
    createAuthorization({
      structure_id: form.structure_id,
      reference: form.reference.trim(),
      authority: form.authority.trim() === '' ? null : form.authority.trim(),
      valid_from: form.valid_from,
      valid_to: form.valid_to,
    }),
  );
  if (!res) return;
  authorizations.value = [...authorizations.value, res];
  Object.assign(form, { structure_id: '', reference: '' });
  touched.value = false;
  done.value = true;
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.auth.title') }}</h1>
      <p class="lede">{{ t('admin.auth.lede') }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="list.error.value" />

    <p v-if="loaded && structures.length === 0 && !list.error.value" class="card muted">{{ t('admin.auth.noStructure') }}</p>

    <section v-if="missing.length > 0" class="notice notice--warning" :aria-label="t('admin.auth.missing')">
      <p><strong>{{ t('admin.auth.missingHint') }}</strong> {{ missing.map((s) => s.name).join(', ') }}</p>
    </section>

    <form v-if="ctx.can('organisation.manage') && structures.length > 0" class="card" novalidate aria-labelledby="a-new" @submit.prevent="submit">
      <h2 id="a-new">{{ t('admin.auth.new') }}</h2>
      <ErrorNotice :error="save.error.value" />
      <p v-if="done" class="notice notice--success" role="status">{{ t('admin.auth.created') }}</p>
      <div class="grid grid--kpi">
        <div class="field">
          <label for="a-structure">{{ t('admin.auth.structure') }}</label>
          <select id="a-structure" v-model="form.structure_id" :aria-invalid="touched && errors.structure" aria-describedby="a-structure-err">
            <option value="" disabled>—</option>
            <option v-for="s in candidates" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <span v-if="touched && errors.structure" id="a-structure-err" class="error">{{ t('admin.auth.structureRequired') }}</span>
        </div>
        <div class="field">
          <label for="a-authority">{{ t('admin.auth.authority') }}</label>
          <input id="a-authority" v-model="form.authority" type="text" maxlength="160" autocomplete="off" aria-describedby="a-authority-hint" />
          <span id="a-authority-hint" class="hint">{{ t('admin.auth.authorityHint') }}</span>
        </div>
        <div class="field">
          <label for="a-reference">{{ t('admin.auth.reference') }}</label>
          <input id="a-reference" v-model="form.reference" type="text" maxlength="80" autocomplete="off" :aria-invalid="touched && errors.reference" aria-describedby="a-reference-err" />
          <span v-if="touched && errors.reference" id="a-reference-err" class="error">{{ t('admin.auth.referenceRequired') }}</span>
        </div>
        <div class="field">
          <label for="a-from">{{ t('admin.auth.validFrom') }}</label>
          <input id="a-from" v-model="form.valid_from" type="date" :aria-invalid="touched && errors.dates" />
        </div>
        <div class="field">
          <label for="a-to">{{ t('admin.auth.validTo') }}</label>
          <input id="a-to" v-model="form.valid_to" type="date" :aria-invalid="touched && (errors.dates || errors.order)" aria-describedby="a-to-err" />
          <span v-if="touched && (errors.dates || errors.order)" id="a-to-err" class="error">{{ errors.dates ? t('admin.auth.datesRequired') : t('admin.auth.datesOrder') }}</span>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('admin.auth.create') }}</button>
    </form>

    <p v-if="loaded && structures.length > 0 && authorizations.length === 0 && !list.error.value" class="card muted">{{ t('admin.auth.none') }}</p>
    <div v-if="authorizations.length > 0" class="table-wrap block">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ t('admin.auth.structure') }}</th>
            <th scope="col">{{ t('admin.auth.authority') }}</th>
            <th scope="col">{{ t('admin.auth.reference') }}</th>
            <th scope="col">{{ t('admin.auth.validFrom') }}</th>
            <th scope="col">{{ t('admin.auth.validTo') }}</th>
            <th scope="col">{{ t('admin.auth.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in authorizations" :key="a.id">
            <th scope="row">{{ structureName(a.structure_id) }}</th>
            <td>{{ a.authority ?? '—' }}</td>
            <td>{{ a.reference }}</td>
            <td>{{ a.valid_from }}</td>
            <td>{{ a.valid_to }}</td>
            <td>
              <span class="tag" :class="{ 'tag--neutral': status(a) !== 'valid' }">{{ t(`admin.auth.${status(a)}`) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.block {
  margin-top: 24px;
}
</style>
