<script setup lang="ts">
// Niveaux de l'école primaire : profil de chaque niveau (cycle, ordre, niveau final, niveau d'examen, langue) et contrôle de la
// table de passage de classe (chaque classe d'un niveau non final a une classe suivante). Aucune liste de niveaux n'est imposée.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { Structure } from '@/api/admin';
import { primary, type LevelProfile, type PromotionCheck } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import { structureIndex, useAction, usePermissions } from './usePri';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const profiles = ref<LevelProfile[]>([]);
const levels = ref<Structure[]>([]);
const names = ref<Record<string, Structure>>({});
const check = ref<PromotionCheck | null>(null);
const canWrite = computed(() => can(...P.referentialWrite));
const form = reactive({ levelId: '', name: '', cycle: '', order: '1', final: false, exam: false, language: 'fr' as 'fr' | 'en' });

const unprofiled = computed(() => levels.value.filter((l) => l.kind === 'level' && !profiles.value.some((p) => p.level_structure_id === l.id)));
const nameOf = (id: string): string => names.value[id]?.name ?? id;

async function load(): Promise<void> {
  await run(async () => {
    const index = await structureIndex();
    levels.value = index.list;
    names.value = index.byId;
    profiles.value = await primary.profiles();
    check.value = await primary.promotionCheck().catch(() => null);
  });
}

async function create(): Promise<void> {
  const body = {
    ...(form.levelId !== '' ? { level_structure_id: form.levelId } : { name: form.name.trim() }),
    cycle_code: form.cycle.trim() === '' ? null : form.cycle.trim(),
    sort_order: Number.parseInt(form.order, 10) || 0,
    is_final_level: form.final,
    is_exam_level: form.exam,
    language: form.language,
  };
  const done = await run(() => primary.createProfile(body));
  if (done) {
    Object.assign(form, { levelId: '', name: '', cycle: '', final: false, exam: false });
    await load();
  }
}

async function toggle(p: LevelProfile, field: 'is_final_level' | 'is_exam_level'): Promise<void> {
  const done = await run(() => primary.updateProfile(p.id, { [field]: !p[field] }));
  if (done) {
    Object.assign(p, done);
    check.value = await primary.promotionCheck().catch(() => check.value);
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.levels.title') }}</h1>
      <p class="lede">{{ t('pri.levels.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card" aria-labelledby="lv-list">
      <h2 id="lv-list">{{ t('pri.levels.listTitle') }}</h2>
      <p v-if="profiles.length === 0" class="muted">{{ t('pri.levels.none') }}</p>
      <ul class="list">
        <li v-for="p in profiles" :key="p.id">
          <div class="row row--between">
            <div class="grow">
              <strong>{{ nameOf(p.level_structure_id) }}</strong>
              <div class="muted">
                {{ t('pri.levels.order', { order: p.sort_order }) }}<span v-if="p.cycle_code"> · {{ t('pri.levels.cycle', { cycle: p.cycle_code }) }}</span> · {{ p.language.toUpperCase() }}
              </div>
            </div>
            <label class="check">
              <input type="checkbox" :checked="p.is_final_level" :disabled="!canWrite || busy" @change="toggle(p, 'is_final_level')" />
              {{ t('pri.levels.final') }}
            </label>
            <label class="check">
              <input type="checkbox" :checked="p.is_exam_level" :disabled="!canWrite || busy" @change="toggle(p, 'is_exam_level')" />
              {{ t('pri.levels.exam') }}
            </label>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="canWrite" class="card" aria-labelledby="lv-new">
      <h2 id="lv-new">{{ t('pri.levels.newTitle') }}</h2>
      <form class="grid2" @submit.prevent="create">
        <div class="field">
          <label for="lv-existing">{{ t('pri.levels.existing') }}</label>
          <select id="lv-existing" v-model="form.levelId">
            <option value="">{{ t('pri.levels.createByName') }}</option>
            <option v-for="l in unprofiled" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </div>
        <div v-if="form.levelId === ''" class="field">
          <label for="lv-name">{{ t('pri.levels.name') }}</label>
          <input id="lv-name" v-model="form.name" type="text" maxlength="160" required />
        </div>
        <div class="field">
          <label for="lv-cycle">{{ t('pri.levels.cycleLabel') }}</label>
          <input id="lv-cycle" v-model="form.cycle" type="text" maxlength="20" />
        </div>
        <div class="field">
          <label for="lv-order">{{ t('pri.levels.orderLabel') }}</label>
          <input id="lv-order" v-model="form.order" type="number" min="0" max="999" inputmode="numeric" />
        </div>
        <div class="field">
          <label for="lv-lang">{{ t('pri.levels.language') }}</label>
          <select id="lv-lang" v-model="form.language">
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </div>
        <label class="check"><input v-model="form.final" type="checkbox" /> {{ t('pri.levels.final') }}</label>
        <label class="check"><input v-model="form.exam" type="checkbox" /> {{ t('pri.levels.exam') }}</label>
        <div><button type="submit" class="btn btn--primary" :disabled="busy || (form.levelId === '' && form.name.trim() === '')">{{ t('pri.levels.create') }}</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="lv-map">
      <h2 id="lv-map">{{ t('pri.levels.mapTitle') }}</h2>
      <p class="muted">{{ t('pri.levels.mapIntro') }}</p>
      <template v-if="check">
        <p v-if="check.ok" class="tag tag--success">{{ t('pri.levels.mapOk', { count: check.classes }) }}</p>
        <ul v-else class="list">
          <li v-for="e in check.errors" :key="e.class_structure_id + e.code">
            <strong>{{ nameOf(e.class_structure_id) }}</strong> : {{ t(`pri.levels.err.${e.code}` as MessageKey) }}
            <span v-if="e.target_structure_id" class="muted">({{ nameOf(e.target_structure_id) }})</span>
          </li>
        </ul>
      </template>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.grow { flex: 1; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
