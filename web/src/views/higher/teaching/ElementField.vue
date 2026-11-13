<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { curricula, type Curriculum, type CurriculumElement } from '@/api/higher';
import { useHt } from './i18n';

// Choix d'un EC : maquette (publiée de préférence) puis élément constitutif de cette maquette.
const props = defineProps<{ id: string; curriculumId: string; elementId: string; levelId?: string; invalid?: boolean }>();
const emit = defineEmits<{
  (e: 'update:curriculumId', v: string): void;
  (e: 'update:elementId', v: string): void;
  (e: 'picked', el: CurriculumElement | null): void;
}>();
const { t } = useHt();

const list = ref<Curriculum[]>([]);
const elements = ref<CurriculumElement[]>([]);
const error = ref<unknown>(null);

const shown = computed(() => list.value.filter((c) => !props.levelId || c.level_id === props.levelId));

async function loadElements(id: string): Promise<void> {
  elements.value = [];
  if (!id) return;
  try {
    const tree = await curricula.show(id);
    elements.value = tree.semesters.flatMap((s) => s.units.flatMap((u) => u.elements));
  } catch (e) {
    error.value = e;
  }
}

function onCurriculum(event: Event): void {
  const id = (event.target as HTMLSelectElement).value;
  emit('update:curriculumId', id);
  emit('update:elementId', '');
  emit('picked', null);
  void loadElements(id);
}

function onElement(event: Event): void {
  const id = (event.target as HTMLSelectElement).value;
  emit('update:elementId', id);
  emit('picked', elements.value.find((e) => e.id === id) ?? null);
}

watch(() => props.curriculumId, (id) => { if (id && elements.value.length === 0) void loadElements(id); });

onMounted(async () => {
  try {
    const all = await curricula.list();
    list.value = [...all.filter((c) => c.status === 'published'), ...all.filter((c) => c.status !== 'published')];
  } catch (e) {
    error.value = e;
  }
  if (props.curriculumId) await loadElements(props.curriculumId);
});
</script>

<template>
  <div class="field">
    <label :for="`${id}-cur`">{{ t('ht.curriculum') }}</label>
    <select :id="`${id}-cur`" :value="curriculumId" @change="onCurriculum">
      <option value="" disabled>{{ t('ht.curriculumPick') }}</option>
      <option v-for="c in shown" :key="c.id" :value="c.id">{{ c.title || c.id }} · v{{ c.version }}</option>
    </select>
  </div>
  <div class="field">
    <label :for="`${id}-el`">{{ t('ht.element') }}</label>
    <select :id="`${id}-el`" :value="elementId" :aria-invalid="invalid && !elementId" @change="onElement">
      <option value="" disabled>{{ t('ht.elementPick') }}</option>
      <option v-for="e in elements" :key="e.id" :value="e.id">{{ e.code }} · {{ e.title }}</option>
    </select>
    <span v-if="invalid && !elementId" class="error">{{ t('hi.required') }}</span>
  </div>
  <ErrorNotice :error="error" />
</template>
