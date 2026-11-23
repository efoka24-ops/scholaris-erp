<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { refreshContexts } from './refresh';
import { createEstablishment, listEstablishments, type Establishment } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { ESTABLISHMENT_TYPES } from '@/lib/landing';

// Liste et création des établissements — design : Fondations.html (champs, boutons, statuts), Main.html (tuiles).
const { t } = useI18n();
const ctx = useContextStore();
const route = useRoute();

const items = ref<Establishment[]>([]);
const loaded = ref(false);
const showForm = ref(route.query.nouveau === '1');
const created = ref<Establishment | null>(null);
const form = reactive({ name: '', type: 'secondary' as string });
const touched = ref(false);
const list = useAction();
const save = useAction();

const canCreate = computed(() => ctx.can('organisation.manage') && (ctx.tenantAdmin || ['super_admin', 'direction_groupe'].includes(ctx.activeRole ?? '')));
const nameInvalid = computed(() => touched.value && form.name.trim() === '');
const typeLabel = (type: string): string => t(`type.${type}` as MessageKey);

async function load(): Promise<void> {
  const res = await list.run(listEstablishments);
  if (res) items.value = res;
  loaded.value = true;
}

async function submit(): Promise<void> {
  touched.value = true;
  if (form.name.trim() === '') return;
  const res = await save.run(() => createEstablishment({ name: form.name.trim(), type: form.type }));
  if (!res) return;
  created.value = res;
  form.name = '';
  touched.value = false;
  showForm.value = false;
  await load();
  try {
    await refreshContexts();
  } catch {
    /* le lanceur se mettra à jour à la prochaine ouverture */
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
        <h1>{{ t('admin.est.title') }}</h1>
        <p class="lede">{{ t('admin.est.lede') }}</p>
      </div>
      <button v-if="canCreate" type="button" class="btn btn--primary" :aria-expanded="showForm" aria-controls="est-form" @click="showForm = !showForm">{{ t('admin.est.new') }}</button>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="list.error.value" />

    <div v-if="created" class="notice notice--success" role="status">
      <p><strong>{{ t('admin.est.created', { name: created.name }) }}</strong></p>
      <router-link class="btn" :to="{ name: 'admin-establishment', params: { id: created.id } }">{{ t('admin.est.configureNext') }} →</router-link>
    </div>

    <form v-if="showForm && canCreate" id="est-form" class="card" novalidate @submit.prevent="submit">
      <h2>{{ !loaded || items.length > 0 ? t('admin.est.new') : t('admin.home.firstCta') }}</h2>
      <ErrorNotice :error="save.error.value" />
      <div class="grid grid--kpi">
        <div class="field">
          <label for="est-name">{{ t('admin.est.name') }}</label>
          <input id="est-name" v-model="form.name" type="text" required maxlength="160" autocomplete="off" :aria-invalid="nameInvalid" :aria-describedby="nameInvalid ? 'est-name-err' : undefined" />
          <span v-if="nameInvalid" id="est-name-err" class="error">{{ t('admin.est.nameRequired') }}</span>
        </div>
        <div class="field">
          <label for="est-type">{{ t('admin.est.type') }}</label>
          <select id="est-type" v-model="form.type" aria-describedby="est-type-hint">
            <option v-for="ty in ESTABLISHMENT_TYPES" :key="ty" :value="ty">{{ typeLabel(ty) }}</option>
          </select>
          <span id="est-type-hint" class="hint">{{ t('admin.est.typeHint') }}</span>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ save.busy.value ? t('common.loading') : t('admin.est.create') }}</button>
    </form>

    <p v-if="!list.busy.value && loaded && items.length === 0 && !list.error.value" class="card muted">{{ t('admin.est.none') }}</p>
    <div v-else-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('admin.est.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('admin.est.name') }}</th>
            <th scope="col">{{ t('admin.est.type') }}</th>
            <th scope="col">{{ t('admin.est.modules') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in items" :key="e.id">
            <th scope="row">{{ e.name }}</th>
            <td><span class="tag">{{ typeLabel(e.type) }}</span></td>
            <td>{{ (e.modules_enabled ?? []).length }}</td>
            <td><router-link class="btn" :to="{ name: 'admin-establishment', params: { id: e.id } }" :aria-label="t('admin.est.configureNamed', { name: e.name })">{{ t('admin.est.configure') }}</router-link></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
