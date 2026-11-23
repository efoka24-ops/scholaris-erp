<script setup lang="ts">
// Sélecteur d'école d'accueil avec recherche (nom ou code officiel) : écoles ouvertes du territoire, par pages. Remplace la saisie
// d'un identifiant. Rend l'identifiant de l'école choisie ; le serveur reste juge du territoire.
import { ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type ReceivingSchool } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { useAction } from './usePs';

const props = defineProps<{ modelValue: string; id: string; territoryId?: string | null }>();
const emit = defineEmits<{ 'update:modelValue': [value: string]; picked: [school: ReceivingSchool | null] }>();

const { t, tp } = useI18n();
const { error, busy, run } = useAction();
const term = ref('');
const schools = ref<ReceivingSchool[]>([]);
const next = ref<string | null>(null);
const searched = ref(false);

async function search(more = false): Promise<void> {
  const res = await run(() => publicSchools.receivingSchools(term.value.trim(), props.territoryId, more ? next.value : null));
  if (!res) return;
  schools.value = more ? [...schools.value, ...res.items] : res.items;
  next.value = res.next;
  searched.value = true;
  if (!more && !schools.value.some((s) => s.establishment_id === props.modelValue)) choose(null);
}

function choose(school: ReceivingSchool | null): void {
  emit('update:modelValue', school?.establishment_id ?? '');
  emit('picked', school);
}
</script>

<template>
  <div class="picker" role="group" :aria-labelledby="`${id}-legend`">
    <span :id="`${id}-legend`" class="label">{{ t('ps.picker.legend') }}</span>
    <ErrorNotice :error="error" />
    <div class="row picker__search">
      <div class="field grow">
        <label :for="`${id}-term`">{{ t('ps.picker.search') }}</label>
        <input :id="`${id}-term`" v-model="term" type="search" autocomplete="off" :placeholder="t('ps.picker.placeholder')" @keydown.enter.prevent="search()" />
      </div>
      <button type="button" class="btn" :disabled="busy" @click="search()">{{ t('ps.picker.go') }}</button>
    </div>
    <p v-if="searched && schools.length === 0" class="muted" role="status">{{ t('ps.picker.none') }}</p>
    <p v-else-if="schools.length > 0" class="muted" role="status">{{ tp('ps.picker.count', schools.length) }}</p>
    <ul v-if="schools.length > 0" class="picker__list">
      <li v-for="s in schools" :key="s.establishment_id">
        <label class="check picker__item">
          <input
            type="radio"
            :name="`${id}-choice`"
            :value="s.establishment_id"
            :checked="modelValue === s.establishment_id"
            @change="choose(s)"
          />
          <span>
            <strong>{{ s.name }}</strong>
            <span class="muted"> · {{ s.official_code }}<template v-if="s.district"> · {{ s.district }}</template></span>
          </span>
        </label>
      </li>
    </ul>
    <button v-if="next" type="button" class="btn btn--quiet" :disabled="busy" @click="search(true)">{{ t('ps.picker.more') }}</button>
  </div>
</template>

<style scoped>
.picker { display: grid; gap: 8px; }
.picker__search { align-items: flex-end; }
.grow { flex: 1; margin-bottom: 0; }
.picker__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; max-height: 18rem; overflow: auto; }
.picker__item { display: flex; gap: 10px; align-items: flex-start; min-height: 44px; padding: 6px 8px; border: 1px solid var(--border, #dcdcd8); }
</style>
