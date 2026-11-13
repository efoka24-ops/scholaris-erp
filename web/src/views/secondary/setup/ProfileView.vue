<script setup lang="ts">
// Profil de l'établissement secondaire (T836) : ce que le type règle (cycles, enseignements, propriété, modules), expliqué en langage
// clair ; avant l'ouverture, un assistant pré-rempli avec les valeurs de départ du type (« à confirmer ») ; après, un changement
// motivé à la fois et l'historique complet (rien n'est supprimé : un module coupé garde ses données en lecture seule).
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  HEAD_TITLES, OWNERSHIPS, SECONDARY_CYCLES, SECONDARY_MODULES, SECTIONS, TEACHING_KINDS, secondaryProfile,
  type ChangeKind, type ProfileChange, type SecondaryModule, type SecondaryProfile,
} from '@/api/secondaryProfile';
import { useI18n, type MessageKey } from '@/i18n';
import { S } from '../access';
import { useAction, usePermissions } from '../useSec';
import { draftFrom, missing, moduleAllowed, openPayload, toggle, type ProfileDraft } from './profileModel';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const profile = ref<SecondaryProfile | null>(null);
const draft = ref<ProfileDraft | null>(null);
const history = ref<ProfileChange[]>([]);
const done = ref<MessageKey | null>(null);
const canManage = computed(() => can(...S.profileManage));
const gaps = computed(() => (draft.value ? missing(draft.value) : []));

type Editable = Exclude<ChangeKind, 'subtype' | 'official_code'>;
const changeKinds: Editable[] = ['modules', 'ownership', 'cycles', 'teaching_kinds', 'sections', 'head_title'];
const change = ref<{ kind: Editable; reason: string }>({ kind: 'modules', reason: '' });

const stateClass = (state: string): string => (state === 'active' ? 'tag--success' : state === 'read_only' ? 'tag--warning' : '');

async function load(): Promise<void> {
  const p = await run(() => secondaryProfile.get());
  if (!p) return;
  profile.value = p;
  draft.value = draftFrom(p);
  if (p.stored) history.value = (await run(() => secondaryProfile.changes({ sort: '-created_at' }))) ?? [];
}

async function open(): Promise<void> {
  if (!draft.value || gaps.value.length > 0) return;
  const draftValue = draft.value;
  const p = await run(() => secondaryProfile.open(openPayload(draftValue)));
  if (p) {
    done.value = 'sec.profile.opened';
    await load();
  }
}

function valueFor(kind: Editable, d: ProfileDraft): unknown {
  return kind === 'modules' ? SECONDARY_MODULES.filter((m) => d.modules.includes(m) && moduleAllowed(m, d.cycles)) : d[kind];
}

async function applyChange(): Promise<void> {
  if (!draft.value || change.value.reason.trim() === '') return;
  const { kind, reason } = change.value;
  const value = valueFor(kind, draft.value);
  const p = await run(() => secondaryProfile.change(kind, value, reason.trim()));
  if (p) {
    done.value = 'sec.profile.changed';
    change.value.reason = '';
    await load();
  }
}

function toggleModule(m: SecondaryModule): void {
  if (draft.value) draft.value.modules = toggle(draft.value.modules, m);
}
function toggleList(field: 'cycles' | 'teaching_kinds' | 'sections', value: string): void {
  if (draft.value) draft.value[field] = toggle(draft.value[field], value);
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sec.profile.title') }}</h1>
      <p class="lede">{{ t('sec.profile.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ t(done) }}</p>

    <template v-if="profile && draft">
      <section class="card" aria-labelledby="sp-type">
        <h2 id="sp-type">{{ t('sec.profile.typeTitle') }}</h2>
        <p><strong>{{ t(`sec.type.${profile.subtype}` as MessageKey) }}</strong></p>
        <p class="muted">{{ t(`sec.type.${profile.subtype}.text` as MessageKey) }}</p>
        <p v-if="profile.confirmation === 'to_confirm'" class="tag tag--warning">{{ t('sec.profile.toConfirm') }}</p>
        <p class="muted">{{ profile.stored ? t('sec.profile.storedText') : t('sec.profile.notStoredText') }}</p>
      </section>

      <form class="card" aria-labelledby="sp-settings" @submit.prevent="profile.stored ? applyChange() : open()">
        <h2 id="sp-settings">{{ profile.stored ? t('sec.profile.currentTitle') : t('sec.profile.wizardTitle') }}</h2>

        <fieldset class="field">
          <legend>{{ t('sec.profile.ownership') }}</legend>
          <p class="muted">{{ t('sec.profile.ownershipHelp') }}</p>
          <label v-for="o in OWNERSHIPS" :key="o" class="check">
            <input v-model="draft.ownership" type="radio" name="sp-ownership" :value="o" :disabled="!canManage || !profile.ownership_options.includes(o)" />
            {{ t(`sec.ownership.${o}` as MessageKey) }}
          </label>
        </fieldset>

        <fieldset class="field">
          <legend>{{ t('sec.profile.cycles') }}</legend>
          <p class="muted">{{ t('sec.profile.cyclesHelp') }}</p>
          <label v-for="c in SECONDARY_CYCLES" :key="c" class="check">
            <input type="checkbox" :checked="draft.cycles.includes(c)" :disabled="!canManage" @change="toggleList('cycles', c)" />
            {{ t(`sec.cycle.${c}` as MessageKey) }}
          </label>
        </fieldset>

        <fieldset class="field">
          <legend>{{ t('sec.profile.teaching') }}</legend>
          <label v-for="k in TEACHING_KINDS" :key="k" class="check">
            <input type="checkbox" :checked="draft.teaching_kinds.includes(k)" :disabled="!canManage" @change="toggleList('teaching_kinds', k)" />
            {{ t(`sec.teaching.${k}` as MessageKey) }}
          </label>
        </fieldset>

        <fieldset class="field">
          <legend>{{ t('sec.profile.sections') }}</legend>
          <label v-for="s in SECTIONS" :key="s" class="check">
            <input type="checkbox" :checked="draft.sections.includes(s)" :disabled="!canManage" @change="toggleList('sections', s)" />
            {{ t(`sec.section.${s}` as MessageKey) }}
          </label>
        </fieldset>

        <div class="field">
          <label for="sp-head">{{ t('sec.profile.headTitle') }}</label>
          <select id="sp-head" v-model="draft.head_title" :disabled="!canManage">
            <option value="" disabled>{{ t('sec.profile.choose') }}</option>
            <option v-for="h in HEAD_TITLES" :key="h" :value="h">{{ t(`sec.head.${h}` as MessageKey) }}</option>
          </select>
        </div>

        <fieldset class="field">
          <legend>{{ t('sec.profile.modules') }}</legend>
          <p class="muted">{{ t('sec.profile.modulesHelp') }}</p>
          <ul class="modules">
            <li v-for="m in profile.modules" :key="m.code">
              <label class="check">
                <input type="checkbox" :checked="draft.modules.includes(m.code)" :disabled="!canManage || !moduleAllowed(m.code, draft.cycles)" @change="toggleModule(m.code)" />
                <span>
                  <strong>{{ t(`sec.module.${m.code}` as MessageKey) }}</strong>
                  <span class="tag" :class="stateClass(m.state)">{{ t(`sec.state.${m.state}` as MessageKey) }}</span>
                  <span v-if="m.default_for_type" class="tag">{{ t('sec.profile.defaultForType') }}</span>
                  <br /><span class="muted">{{ t(`sec.module.${m.code}.text` as MessageKey) }}</span>
                </span>
              </label>
            </li>
          </ul>
        </fieldset>

        <template v-if="canManage">
          <template v-if="!profile.stored">
            <div class="field">
              <label for="sp-code">{{ t('sec.profile.officialCode') }}</label>
              <input id="sp-code" v-model="draft.official_code" type="text" maxlength="40" />
            </div>
            <p v-for="g in gaps" :key="g" class="muted">{{ t(`sec.profile.missing.${g}` as MessageKey) }}</p>
            <button type="submit" class="btn btn--primary" :disabled="busy || gaps.length > 0">{{ t('sec.profile.open') }}</button>
          </template>
          <template v-else>
            <div class="field">
              <label for="sp-kind">{{ t('sec.profile.changeKind') }}</label>
              <select id="sp-kind" v-model="change.kind">
                <option v-for="k in changeKinds" :key="k" :value="k">{{ t(`sec.change.${k}` as MessageKey) }}</option>
              </select>
            </div>
            <div class="field">
              <label for="sp-reason">{{ t('sec.profile.reason') }}</label>
              <textarea id="sp-reason" v-model="change.reason" maxlength="500" rows="2" required></textarea>
            </div>
            <p class="muted">{{ t('sec.profile.changeHelp') }}</p>
            <button type="submit" class="btn btn--primary" :disabled="busy || change.reason.trim() === ''">{{ t('sec.profile.applyChange') }}</button>
          </template>
        </template>
        <p v-else class="muted">{{ t('sec.profile.askAdmin') }}</p>
      </form>

      <section v-if="profile.stored" class="card" aria-labelledby="sp-history">
        <h2 id="sp-history">{{ t('sec.profile.history') }}</h2>
        <p v-if="history.length === 0" class="muted">{{ t('sec.profile.noHistory') }}</p>
        <ul class="rows">
          <li v-for="h in history" :key="h.id">
            <strong>{{ t(`sec.changeKind.${h.change_kind}` as MessageKey) }}</strong> · <span class="muted">{{ h.created_at.slice(0, 16).replace('T', ' ') }}</span>
            <span v-if="h.reason"> · {{ h.reason }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.modules { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
fieldset { border: 0; padding: 0; margin: 0 0 12px; }
legend { font-weight: 600; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
</style>
