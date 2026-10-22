<script setup lang="ts">
// Salles et équipements numériques d'une version d'infrastructure : une version ouverte se modifie ; une version close est
// immuable, toute correction crée la version suivante avec son motif (EX-ECO-02, EX-ECO-03).
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type Classroom, type DigitalEquipment, type InfrastructureVersion } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { P } from './access';
import { useAction, usePermissions } from './usePs';

const props = defineProps<{ id: string }>();
const { t } = useI18n();
const router = useRouter();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const version = ref<InfrastructureVersion | null>(null);
const rooms = ref<Classroom[]>([]);
const equipment = ref<DigitalEquipment[]>([]);
const reason = ref('');
const saved = ref(false);
const draft = reactive<Classroom>({ label: '', material: '', room_state: '', in_use: true });
const canWrite = computed(() => can(...P.schoolWrite));
const isDraft = computed(() => version.value?.state === 'draft');

// Les codes sont libres (minuscules, chiffres, tiret bas) ; ces suggestions ne sont que des aides de saisie.
const CODE = /^[a-z][a-z0-9_]{0,19}$/;
const MATERIALS = ['permanent', 'semi_permanent', 'temporary'];
const STATES = ['good', 'fair', 'poor', 'unusable'];
const roomError = computed(() => {
  if (draft.label.trim() === '' && draft.material === '' && draft.room_state === '') return '';
  if (draft.label.trim() === '' || draft.label.trim().length > 60) return t('ps.rooms.errLabel');
  if (!CODE.test(draft.material) || !CODE.test(draft.room_state)) return t('ps.rooms.errCode');
  return '';
});
const canAdd = computed(() => draft.label.trim() !== '' && roomError.value === '');
const equipmentValid = computed(() => equipment.value.every((e) => e.label.trim() !== '' && e.label.trim().length <= 80 && Number.isInteger(e.quantity) && e.quantity >= 0));
const canSave = computed(() => equipmentValid.value && (isDraft.value || reason.value.trim() !== ''));

function fill(v: InfrastructureVersion): void {
  version.value = v;
  rooms.value = (v.classrooms ?? []).map((r) => ({ ...r }));
  equipment.value = (v.digital_equipment ?? []).map((e) => ({ ...e }));
}

async function load(): Promise<void> {
  const v = await run(() => publicSchools.infrastructureVersion(props.id));
  if (v) fill(v);
}

function addRoom(): void {
  if (!canAdd.value) return;
  rooms.value.push({ label: draft.label.trim(), material: draft.material, room_state: draft.room_state, in_use: draft.in_use });
  Object.assign(draft, { label: '', material: '', room_state: '', in_use: true });
}
const removeRoom = (index: number): void => void rooms.value.splice(index, 1);
const addEquipment = (): void => void equipment.value.push({ label: '', quantity: 0 });
const removeEquipment = (index: number): void => void equipment.value.splice(index, 1);

async function save(): Promise<void> {
  const v = version.value;
  if (!v || !canSave.value) return;
  saved.value = false;
  const data = { classrooms: rooms.value, digital_equipment: equipment.value.map((e) => ({ label: e.label.trim(), quantity: e.quantity })) };
  if (v.state === 'draft') {
    const updated = await run(() => publicSchools.updateInfrastructure(v.id, data));
    if (updated) {
      fill(updated);
      saved.value = true;
    }
    return;
  }
  const next = await run(() => publicSchools.correctInfrastructure(v.id, reason.value.trim(), { year_label: v.year_label, ...data }));
  if (next) {
    reason.value = '';
    await router.replace({ name: 'ps-rooms', params: { id: next.id } });
    fill(next);
    saved.value = true;
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.rooms.title') }}</h1>
      <p v-if="version" class="lede">
        {{ version.year_label }} · {{ t('ps.school.version', { n: version.version }) }}
        <span class="tag" :class="isDraft ? 'tag--success' : 'tag--neutral'">{{ isDraft ? t('ps.school.draft') : t('ps.school.closed') }}</span>
      </p>
      <p><router-link :to="{ name: 'ps-school' }">{{ t('ps.rooms.back') }}</router-link></p>
    </header>
    <ErrorNotice :error="error" />

    <template v-if="version">
      <p v-if="!isDraft && canWrite" class="notice notice--warning" role="status">{{ t('ps.rooms.closedNote') }}</p>
      <p v-if="saved" class="notice notice--success" role="status">{{ t('ps.rooms.saved') }}</p>

      <section class="card stack" aria-labelledby="rm-rooms">
        <h2 id="rm-rooms">{{ t('ps.rooms.roomsTitle') }}</h2>
        <p v-if="rooms.length === 0" class="muted">{{ t('ps.rooms.none') }}</p>
        <div v-else class="table-wrap">
          <table>
            <caption class="sr-only">{{ t('ps.rooms.roomsTitle') }}</caption>
            <thead>
              <tr>
                <th scope="col">{{ t('ps.rooms.label') }}</th>
                <th scope="col">{{ t('ps.rooms.material') }}</th>
                <th scope="col">{{ t('ps.rooms.state') }}</th>
                <th scope="col">{{ t('ps.rooms.inUse') }}</th>
                <th v-if="canWrite" scope="col"><span class="sr-only">{{ t('ps.rooms.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rooms" :key="`${i}-${r.label}`">
                <td>{{ r.label }}</td>
                <td>{{ r.material }}</td>
                <td>{{ r.room_state }}</td>
                <td>{{ r.in_use ? t('ps.rooms.yes') : t('ps.rooms.no') }}</td>
                <td v-if="canWrite"><button type="button" class="btn btn--quiet" @click="removeRoom(i)">{{ t('ps.rooms.remove', { label: r.label }) }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="canWrite" class="add" @submit.prevent="addRoom">
          <h3>{{ t('ps.rooms.addTitle') }}</h3>
          <div class="field">
            <label for="rm-label">{{ t('ps.rooms.label') }}</label>
            <input id="rm-label" v-model="draft.label" type="text" maxlength="60" />
          </div>
          <div class="field">
            <label for="rm-material">{{ t('ps.rooms.material') }}</label>
            <input id="rm-material" v-model="draft.material" type="text" list="rm-materials" maxlength="20" autocomplete="off" aria-describedby="rm-code-hint" />
            <datalist id="rm-materials"><option v-for="m in MATERIALS" :key="m" :value="m" /></datalist>
          </div>
          <div class="field">
            <label for="rm-state">{{ t('ps.rooms.state') }}</label>
            <input id="rm-state" v-model="draft.room_state" type="text" list="rm-states" maxlength="16" autocomplete="off" aria-describedby="rm-code-hint" />
            <datalist id="rm-states"><option v-for="s in STATES" :key="s" :value="s" /></datalist>
            <span id="rm-code-hint" class="hint">{{ t('ps.rooms.codeHint') }}</span>
          </div>
          <label class="check"><input v-model="draft.in_use" type="checkbox" />{{ t('ps.rooms.inUse') }}</label>
          <p v-if="roomError" class="notice notice--error" role="alert">{{ roomError }}</p>
          <button type="submit" class="btn" :disabled="!canAdd">{{ t('ps.rooms.add') }}</button>
        </form>
      </section>

      <section class="card stack" aria-labelledby="rm-eq">
        <h2 id="rm-eq">{{ t('ps.rooms.equipmentTitle') }}</h2>
        <p v-if="equipment.length === 0" class="muted">{{ t('ps.rooms.equipmentNone') }}</p>
        <ul class="equipment">
          <li v-for="(e, i) in equipment" :key="i" class="row">
            <template v-if="canWrite">
              <div class="field grow">
                <label :for="`rm-eq-l-${i}`">{{ t('ps.rooms.equipmentLabel') }}</label>
                <input :id="`rm-eq-l-${i}`" v-model="e.label" type="text" maxlength="80" />
              </div>
              <div class="field qty">
                <label :for="`rm-eq-q-${i}`">{{ t('ps.rooms.quantity') }}</label>
                <input :id="`rm-eq-q-${i}`" v-model.number="e.quantity" type="number" min="0" step="1" inputmode="numeric" />
              </div>
              <button type="button" class="btn btn--quiet" @click="removeEquipment(i)">{{ t('ps.rooms.remove', { label: e.label || String(i + 1) }) }}</button>
            </template>
            <template v-else>{{ e.label }} · {{ e.quantity }}</template>
          </li>
        </ul>
        <button v-if="canWrite" type="button" class="btn" @click="addEquipment">{{ t('ps.rooms.addEquipment') }}</button>
      </section>

      <form v-if="canWrite" class="card stack save" @submit.prevent="save">
        <div v-if="!isDraft" class="field">
          <label for="rm-reason">{{ t('ps.school.reason') }}</label>
          <input id="rm-reason" v-model="reason" type="text" maxlength="500" required />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy || !canSave">{{ isDraft ? t('ps.rooms.save') : t('ps.rooms.saveCorrection') }}</button>
      </form>
    </template>
  </div>
</template>

<style scoped>
.add { display: grid; gap: 8px; max-width: 28rem; padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.equipment { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.equipment .row { align-items: flex-end; flex-wrap: wrap; }
.grow { flex: 1 1 14rem; margin-bottom: 0; }
.qty { width: 8rem; margin-bottom: 0; }
.save { max-width: 28rem; }
</style>
