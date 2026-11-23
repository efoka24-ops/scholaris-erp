<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { searchPeople, type PersonLite } from '@/api/admin';
import { useI18n } from '@/i18n';

// Choix d'une personne de l'établissement (recherche /people) ; émet son identifiant.
const props = defineProps<{ id: string; label: string; modelValue: string | null; disabled?: boolean }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string | null): void }>();
const { t } = useI18n();

const q = ref('');
const results = ref<PersonLite[]>([]);
const searched = ref(false);
const chosen = ref<PersonLite | null>(null);
const error = ref<unknown>(null);
const busy = ref(false);

async function search(): Promise<void> {
  if (q.value.trim().length < 2) return;
  busy.value = true;
  error.value = null;
  try {
    results.value = await searchPeople(q.value);
  } catch (e) {
    error.value = e;
    results.value = [];
  } finally {
    busy.value = false;
    searched.value = true;
  }
}

function pick(p: PersonLite): void {
  chosen.value = p;
  results.value = [];
  emit('update:modelValue', p.id);
}

function reset(): void {
  chosen.value = null;
  searched.value = false;
  emit('update:modelValue', null);
}
</script>

<template>
  <div class="field picker">
    <label :for="props.id">{{ label }}</label>
    <div v-if="modelValue" class="row">
      <span>{{ t('hi.personChosen', { name: chosen ? `${chosen.first_name} ${chosen.last_name}` : modelValue }) }}</span>
      <button type="button" class="hi-linkbtn" :disabled="disabled" @click="reset">{{ t('hi.personChange') }}</button>
    </div>
    <template v-else>
      <div class="row picker__row">
        <input :id="props.id" v-model="q" type="search" :placeholder="t('hi.personSearch')" :disabled="disabled" @keydown.enter.prevent="search" />
        <button type="button" class="btn btn--quiet" :disabled="disabled || busy || q.trim().length < 2" @click="search">{{ t('hi.personSearchBtn') }}</button>
      </div>
      <ul v-if="results.length" class="hi-list">
        <li v-for="p in results" :key="p.id">
          <span class="hi-grow">{{ p.first_name }} {{ p.last_name }}</span>
          <button type="button" class="btn btn--quiet" @click="pick(p)">{{ t('hi.confirm') }}</button>
        </li>
      </ul>
      <p v-else-if="searched && !error" class="muted hi-small">{{ t('hi.personNone') }}</p>
      <ErrorNotice v-if="error" :error="error" />
    </template>
  </div>
</template>

<style scoped>
.picker__row {
  flex-wrap: nowrap;
}
.picker__row input {
  flex: 1;
  min-width: 0;
}
</style>
