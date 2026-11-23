<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { SETTINGS_CATALOG, SETTING_GROUPS, formatSettingValue, parseSettingValue, type SettingDef } from './catalog';
import { effectiveSettings, putSetting, type EffectiveSetting } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';

// Paramètres de l'établissement (barèmes, absences, relances, plage d'envoi) — design : Fondations.html (champs, statuts).
const { t } = useI18n();
const ctx = useContextStore();

const effective = ref<EffectiveSetting[]>([]);
const loaded = ref(false);
const loadError = ref<unknown>(null);
const drafts = reactive<Record<string, string>>({});
const rowError = reactive<Record<string, unknown>>({});
const rowInvalid = reactive<Record<string, string>>({});
const saving = ref<string | null>(null);
const savedKey = ref<string | null>(null);

const canWrite = computed(() => ctx.can('settings.manage'));
const byKey = computed(() => new Map(effective.value.map((e) => [e.key, e])));
const known = new Set(SETTINGS_CATALOG.map((d) => d.key));
const others = computed(() => effective.value.filter((e) => !known.has(e.key)));
const inGroup = (g: string): SettingDef[] => SETTINGS_CATALOG.filter((d) => d.group === g);
const label = (key: string): string => t(`admin.set.${key}` as AdminKey);
const hint = (key: string): string => t(`admin.set.${key}.hint` as AdminKey);

function fill(): void {
  for (const def of SETTINGS_CATALOG) drafts[def.key] = formatSettingValue(def, byKey.value.get(def.key)?.value);
}

async function load(): Promise<void> {
  loadError.value = null;
  try {
    effective.value = await effectiveSettings();
    fill();
  } catch (e) {
    loadError.value = e;
  }
  loaded.value = true;
}

function sourceLabel(def: SettingDef): string {
  const e = byKey.value.get(def.key);
  if (!e) return def.fallback !== undefined ? t('admin.set.source.default', { value: def.fallback === '' ? '—' : def.fallback }) : t('admin.set.source.none');
  return t(`admin.set.source.${e.source}` as AdminKey);
}

async function save(def: SettingDef): Promise<void> {
  savedKey.value = null;
  rowError[def.key] = null;
  delete rowInvalid[def.key];
  const parsed = parseSettingValue(def, String(drafts[def.key] ?? ''));
  if (!parsed.ok) {
    rowInvalid[def.key] = t(`admin.set.err.${parsed.reason}` as AdminKey, { min: def.min ?? 0, max: def.max ?? 0 });
    return;
  }
  saving.value = def.key;
  try {
    const res = await putSetting('establishment', def.key, parsed.value);
    const next: EffectiveSetting = res.effective ?? { key: def.key, value: res.data.value, source: 'establishment', overridden: [] };
    effective.value = [...effective.value.filter((e) => e.key !== def.key), next];
    drafts[def.key] = formatSettingValue(def, next.value);
    savedKey.value = def.key;
  } catch (e) {
    rowError[def.key] = e;
  } finally {
    saving.value = null;
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.set.title') }}</h1>
      <p class="lede">{{ t('admin.set.lede', { name: ctx.activeTile?.name ?? '' }) }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="loadError" />
    <p v-if="!canWrite" class="notice notice--warning">{{ t('admin.readOnly') }}</p>
    <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-if="loaded && !loadError">
      <section v-for="g in SETTING_GROUPS" :key="g" class="card group" :aria-labelledby="`grp-${g}`">
        <h2 :id="`grp-${g}`">{{ t(`admin.set.group.${g}` as AdminKey) }}</h2>
        <form v-for="def in inGroup(g)" :key="def.key" class="setting" novalidate @submit.prevent="save(def)">
          <div class="field">
            <label :for="`set-${def.key}`">{{ label(def.key) }}</label>
            <select v-if="def.kind === 'select'" :id="`set-${def.key}`" v-model="drafts[def.key]" :disabled="!canWrite" :aria-describedby="`hint-${def.key}`">
              <option value="" disabled>{{ t('admin.set.choose') }}</option>
              <option v-for="o in def.options" :key="o" :value="o">{{ t(`admin.set.opt.${o}` as AdminKey) }}</option>
            </select>
            <select v-else-if="def.kind === 'boolean'" :id="`set-${def.key}`" v-model="drafts[def.key]" :disabled="!canWrite" :aria-describedby="`hint-${def.key}`">
              <option value="" disabled>{{ t('admin.set.choose') }}</option>
              <option value="true">{{ t('admin.set.yes') }}</option>
              <option value="false">{{ t('admin.set.no') }}</option>
            </select>
            <textarea v-else-if="def.kind === 'json'" :id="`set-${def.key}`" v-model="drafts[def.key]" rows="4" spellcheck="false" :disabled="!canWrite" :aria-invalid="!!rowInvalid[def.key]" :aria-describedby="`hint-${def.key}`" />
            <input
              v-else
              :id="`set-${def.key}`"
              v-model="drafts[def.key]"
              :type="def.kind === 'number' ? 'number' : 'text'"
              :min="def.min"
              :max="def.max"
              :step="def.step"
              :disabled="!canWrite"
              :aria-invalid="!!rowInvalid[def.key]"
              :aria-describedby="`hint-${def.key}`"
            />
            <span :id="`hint-${def.key}`" class="hint">{{ hint(def.key) }} <span class="tag tag--neutral">{{ sourceLabel(def) }}</span></span>
            <span v-if="rowInvalid[def.key]" class="error" role="alert">{{ rowInvalid[def.key] }}</span>
          </div>
          <ErrorNotice :error="rowError[def.key]" />
          <div class="row">
            <button type="submit" class="btn" :disabled="!canWrite || saving === def.key" :aria-label="t('admin.set.saveNamed', { name: label(def.key) })">{{ saving === def.key ? t('common.loading') : t('admin.save') }}</button>
            <span v-if="savedKey === def.key" class="ok" role="status">{{ t('admin.saved') }}</span>
          </div>
        </form>
      </section>

      <section v-if="others.length > 0" class="card group" aria-labelledby="grp-other">
        <h2 id="grp-other">{{ t('admin.set.other') }}</h2>
        <dl class="others">
          <div v-for="e in others" :key="e.key">
            <dt>{{ e.key }} <span class="tag tag--neutral">{{ t(`admin.set.source.${e.source}` as AdminKey) }}</span></dt>
            <dd><code>{{ JSON.stringify(e.value) }}</code></dd>
          </div>
        </dl>
      </section>
    </template>
  </div>
</template>

<style scoped>
.group {
  margin-top: 16px;
}
.group h2 {
  margin-top: 0;
}
.setting {
  border-top: 1px solid var(--border);
  padding-top: 16px;
  margin-top: 16px;
}
.setting .field {
  max-width: 640px;
}
.ok {
  color: var(--success);
  font-weight: 700;
}
.others dt {
  font-weight: 700;
}
.others dd {
  margin: 4px 0 12px;
}
</style>
