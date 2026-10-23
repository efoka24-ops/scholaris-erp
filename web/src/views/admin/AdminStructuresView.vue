<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { structureKindsFor } from './catalog';
import { createOrgUnit, createStructure, listOrgUnits, listStructures, updateStructure, type OrgUnit, type Structure } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';
import { asEstablishmentType } from '@/lib/landing';

// Structures pédagogiques (niveaux, classes, groupes, filières) et unités (départements, cycles) selon le type d'établissement.
const { t } = useI18n();
const ctx = useContextStore();

const structures = ref<Structure[]>([]);
const units = ref<OrgUnit[]>([]);
const loaded = ref(false);
const list = useAction();
const structAction = useAction();
const unitAction = useAction();
const editAction = useAction();

const type = computed(() => asEstablishmentType(ctx.establishmentType));
const higher = computed(() => type.value === 'higher');
const kinds = computed(() => structureKindsFor(type.value));
const canManage = computed(() => ctx.can('organisation.manage'));
const kindLabel = (k: string): string => t(`admin.struct.kind.${k}.${type.value}` as AdminKey);
const unitName = (id: string | null): string => units.value.find((u) => u.id === id)?.name ?? '—';

const form = reactive({ kind: '', name: '', org_unit_id: '', capacity: '' });
const touched = ref(false);
const unitForm = reactive({ type: '', name: '', parent_id: '' });
const unitTouched = ref(false);
const editing = ref<string | null>(null);
const edit = reactive({ name: '', capacity: '' });

const capacityBad = computed(() => form.capacity !== '' && !(Number.isInteger(Number(form.capacity)) && Number(form.capacity) >= 1 && Number(form.capacity) <= 10000));
const grouped = computed(() => kinds.value.map((k) => ({ kind: k, rows: structures.value.filter((s) => s.kind === k) })).filter((g) => g.rows.length > 0));

async function load(): Promise<void> {
  const res = await list.run(async () => ({ structures: await listStructures(), units: await listOrgUnits() }));
  if (res) {
    structures.value = res.structures;
    units.value = res.units;
  }
  loaded.value = true;
  form.kind ||= kinds.value[0] ?? '';
  unitForm.type ||= higher.value ? 'department' : '';
}

async function submit(): Promise<void> {
  touched.value = true;
  if (form.name.trim() === '' || capacityBad.value) return;
  const res = await structAction.run(() =>
    createStructure({ kind: form.kind, name: form.name.trim(), org_unit_id: form.org_unit_id || null, capacity: form.capacity === '' ? null : Number(form.capacity) }),
  );
  if (!res) return;
  structures.value = [...structures.value, res];
  Object.assign(form, { name: '', capacity: '' });
  touched.value = false;
}

async function submitUnit(): Promise<void> {
  unitTouched.value = true;
  if (unitForm.name.trim() === '' || unitForm.type.trim() === '') return;
  const res = await unitAction.run(() => createOrgUnit({ type: unitForm.type.trim(), name: unitForm.name.trim(), parent_id: unitForm.parent_id || null }));
  if (!res) return;
  units.value = [...units.value, res];
  Object.assign(unitForm, { name: '', parent_id: '' });
  unitTouched.value = false;
}

function startEdit(s: Structure): void {
  editing.value = s.id;
  edit.name = s.name;
  edit.capacity = s.capacity === null ? '' : String(s.capacity);
}

async function saveEdit(s: Structure): Promise<void> {
  if (edit.name.trim() === '') return;
  const res = await editAction.run(() => updateStructure(s.id, { name: edit.name.trim(), capacity: edit.capacity === '' ? null : Number(edit.capacity) }));
  if (!res) return;
  structures.value = structures.value.map((x) => (x.id === s.id ? res : x));
  editing.value = null;
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.struct.title') }}</h1>
      <p class="lede">{{ t(`admin.struct.lede.${type}` as AdminKey, { name: ctx.activeTile?.name ?? '' }) }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="editAction.error.value" />

    <form v-if="canManage" class="card" novalidate aria-labelledby="s-new" @submit.prevent="submit">
      <h2 id="s-new">{{ t('admin.struct.new') }}</h2>
      <ErrorNotice :error="structAction.error.value" />
      <div class="grid grid--kpi">
        <div class="field">
          <label for="s-kind">{{ t('admin.struct.kindLabel') }}</label>
          <select id="s-kind" v-model="form.kind">
            <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="s-name">{{ t('admin.struct.name') }}</label>
          <input id="s-name" v-model="form.name" type="text" maxlength="160" autocomplete="off" :aria-invalid="touched && form.name.trim() === ''" aria-describedby="s-name-err" />
          <span v-if="touched && form.name.trim() === ''" id="s-name-err" class="error">{{ t('admin.struct.nameRequired') }}</span>
        </div>
        <div class="field">
          <label for="s-unit">{{ higher ? t('admin.struct.unitHigher') : t('admin.struct.unit') }}</label>
          <select id="s-unit" v-model="form.org_unit_id">
            <option value="">{{ t('admin.struct.noUnit') }}</option>
            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="s-cap">{{ t('admin.struct.capacity') }}</label>
          <input id="s-cap" v-model="form.capacity" type="number" min="1" max="10000" step="1" inputmode="numeric" :aria-invalid="capacityBad" aria-describedby="s-cap-hint" />
          <span id="s-cap-hint" class="hint" :class="{ error: capacityBad }">{{ capacityBad ? t('admin.struct.capacityBad') : t('admin.struct.capacityHint') }}</span>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="structAction.busy.value">{{ t('admin.struct.create') }}</button>
    </form>

    <p v-if="loaded && structures.length === 0 && !list.error.value" class="card muted">{{ t('admin.struct.none') }}</p>
    <section v-for="g in grouped" :key="g.kind" class="block" :aria-labelledby="`g-${g.kind}`">
      <h2 :id="`g-${g.kind}`" class="h2">{{ kindLabel(g.kind) }} <span class="tag">{{ g.rows.length }}</span></h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th scope="col">{{ t('admin.struct.name') }}</th>
              <th scope="col">{{ higher ? t('admin.struct.unitHigher') : t('admin.struct.unit') }}</th>
              <th scope="col" class="num">{{ t('admin.struct.capacity') }}</th>
              <th v-if="canManage" scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in g.rows" :key="s.id">
              <template v-if="editing === s.id">
                <td><label class="sr-only" :for="`e-n-${s.id}`">{{ t('admin.struct.name') }}</label><input :id="`e-n-${s.id}`" v-model="edit.name" type="text" maxlength="160" /></td>
                <td>{{ unitName(s.org_unit_id) }}</td>
                <td class="num"><label class="sr-only" :for="`e-c-${s.id}`">{{ t('admin.struct.capacity') }}</label><input :id="`e-c-${s.id}`" v-model="edit.capacity" type="number" min="1" max="10000" /></td>
                <td>
                  <button type="button" class="btn btn--primary" :disabled="editAction.busy.value" @click="saveEdit(s)">{{ t('admin.save') }}</button>
                  <button type="button" class="btn btn--quiet" @click="editing = null">{{ t('admin.cancel') }}</button>
                </td>
              </template>
              <template v-else>
                <th scope="row">{{ s.name }}</th>
                <td>{{ unitName(s.org_unit_id) }}</td>
                <td class="num">{{ s.capacity ?? '—' }}</td>
                <td v-if="canManage"><button type="button" class="btn btn--quiet" :aria-label="t('admin.struct.editNamed', { name: s.name })" @click="startEdit(s)">{{ t('admin.edit') }}</button></td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card units" aria-labelledby="u-title">
      <h2 id="u-title">{{ higher ? t('admin.units.titleHigher') : t('admin.units.title') }}</h2>
      <p class="muted">{{ higher ? t('admin.units.hintHigher') : t('admin.units.hint') }}</p>
      <ul v-if="units.length > 0" class="unit-list">
        <li v-for="u in units" :key="u.id"><strong>{{ u.name }}</strong> <span class="tag tag--neutral">{{ u.type }}</span><template v-if="u.parent_id"> · {{ t('admin.units.under', { name: unitName(u.parent_id) }) }}</template></li>
      </ul>
      <p v-else class="muted">{{ t('admin.units.none') }}</p>
      <form v-if="canManage" class="subform" novalidate aria-labelledby="u-new" @submit.prevent="submitUnit">
        <h3 id="u-new">{{ t('admin.units.new') }}</h3>
        <ErrorNotice :error="unitAction.error.value" />
        <div class="grid grid--kpi">
          <div class="field">
            <label for="u-type">{{ t('admin.units.type') }}</label>
            <input id="u-type" v-model="unitForm.type" type="text" maxlength="40" :placeholder="higher ? 'department' : 'cycle'" :aria-invalid="unitTouched && unitForm.type.trim() === ''" />
            <span class="hint">{{ t('admin.units.typeHint') }}</span>
          </div>
          <div class="field">
            <label for="u-name">{{ t('admin.struct.name') }}</label>
            <input id="u-name" v-model="unitForm.name" type="text" maxlength="160" autocomplete="off" :aria-invalid="unitTouched && unitForm.name.trim() === ''" />
          </div>
          <div class="field">
            <label for="u-parent">{{ t('admin.units.parent') }}</label>
            <select id="u-parent" v-model="unitForm.parent_id">
              <option value="">{{ t('admin.units.noParent') }}</option>
              <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn" :disabled="unitAction.busy.value">{{ t('admin.units.create') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.block,
.units {
  margin-top: 24px;
}
.h2 {
  font-size: 20px;
  margin: 0 0 12px;
}
.unit-list {
  list-style: none;
  margin: 0 0 16px;
  padding: 0;
}
.unit-list li {
  padding: 8px 0;
  border-top: 1px solid var(--border);
}
.subform {
  margin-top: 16px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
}
.subform h3 {
  margin: 0 0 12px;
}
td input {
  min-width: 8rem;
}
</style>
