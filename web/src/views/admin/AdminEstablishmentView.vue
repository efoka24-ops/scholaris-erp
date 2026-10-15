<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { refreshContexts } from './refresh';
import { getBranding, getEstablishment, getModules, renameEstablishment, saveBranding, saveModules, type Establishment } from '@/api/admin';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';
import { contrastWithBlack } from '@/lib/format';

// Fiche d'un établissement : nom, identité visuelle (accent, en-têtes de documents) et modules activés.
const props = defineProps<{ id: string }>();
const { t } = useI18n();
const ctx = useContextStore();

const est = ref<Establishment | null>(null);
const loadAction = useAction();
const renameAction = useAction();
const brandAction = useAction();
const moduleAction = useAction();
const done = ref<'' | 'rename' | 'branding' | 'modules'>('');

const name = ref('');
const brand = reactive({ accent: '#F26B1D', initial: '', headers: '', authorizations: '', signatories: [] as Array<{ name: string; title: string }> });
const available = ref<string[]>([]);
const enabled = ref<string[]>([]);

const canManage = computed(() => ctx.can('organisation.manage'));
const accentValid = computed(() => /^#[0-9a-fA-F]{6}$/.test(brand.accent));
const accentContrast = computed(() => (accentValid.value ? contrastWithBlack(brand.accent) : 0));
const accentOk = computed(() => accentValid.value && accentContrast.value >= 4.5);
const lines = (text: string): string[] => text.split('\n').map((l) => l.trim()).filter((l) => l !== '');
const headerLines = computed(() => lines(brand.headers));
const tooManyHeaders = computed(() => headerLines.value.length > 6 || headerLines.value.some((l) => l.length > 200));
const typeLabel = computed(() => (est.value ? t(`type.${est.value.type}` as MessageKey) : ''));
const moduleLabel = (m: string): string => t(`admin.module.${m}` as AdminKey);

async function load(): Promise<void> {
  const e = await loadAction.run(async () => {
    const [establishment, branding, modules] = await Promise.all([getEstablishment(props.id), getBranding(props.id), getModules(props.id)]);
    return { establishment, branding, modules };
  });
  if (!e) return;
  est.value = e.establishment;
  name.value = e.establishment.name;
  brand.accent = e.branding.accent ?? '#F26B1D';
  brand.initial = e.branding.initial ?? '';
  brand.headers = (e.branding.headers ?? []).join('\n');
  brand.authorizations = (e.branding.authorization_numbers ?? []).join('\n');
  brand.signatories = (e.branding.signatories ?? []).map((s) => ({ ...s }));
  available.value = e.modules.available;
  enabled.value = [...(e.modules.modules_enabled ?? [])];
}

async function rename(): Promise<void> {
  done.value = '';
  if (name.value.trim() === '') return;
  const res = await renameAction.run(() => renameEstablishment(props.id, name.value.trim()));
  if (!res) return;
  est.value = { ...(est.value as Establishment), name: res.name };
  done.value = 'rename';
  await refreshContexts().catch(() => undefined);
}

async function saveBrand(): Promise<void> {
  done.value = '';
  if (!accentOk.value || tooManyHeaders.value) return;
  const res = await brandAction.run(() =>
    saveBranding(props.id, {
      accent: brand.accent.toUpperCase(),
      ...(brand.initial.trim() ? { initial: brand.initial.trim() } : {}),
      headers: headerLines.value,
      authorization_numbers: lines(brand.authorizations),
      signatories: brand.signatories.filter((s) => s.name.trim() !== '' && s.title.trim() !== ''),
    }),
  );
  if (!res) return;
  done.value = 'branding';
  await refreshContexts().catch(() => undefined);
}

async function saveMods(): Promise<void> {
  done.value = '';
  const res = await moduleAction.run(() => saveModules(props.id, enabled.value));
  if (!res) return;
  enabled.value = [...res.modules_enabled];
  done.value = 'modules';
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow"><router-link :to="{ name: 'admin-establishments' }">← {{ t('admin.est.title') }}</router-link></p>
      <h1>{{ est?.name ?? t('admin.est.configure') }}</h1>
      <p v-if="est" class="lede"><span class="tag">{{ typeLabel }}</span> · {{ t('admin.est.typeFixed') }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="loadAction.error.value" />
    <p v-if="loadAction.busy.value" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-if="est">
      <p v-if="!canManage" class="notice notice--warning">{{ t('admin.readOnly') }}</p>

      <form class="card" novalidate aria-labelledby="rename-title" @submit.prevent="rename">
        <h2 id="rename-title">{{ t('admin.est.identity') }}</h2>
        <ErrorNotice :error="renameAction.error.value" />
        <div class="field">
          <label for="e-name">{{ t('admin.est.name') }}</label>
          <input id="e-name" v-model="name" type="text" maxlength="160" required :disabled="!canManage" />
        </div>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="!canManage || renameAction.busy.value || name.trim() === '' || name.trim() === est.name">{{ t('admin.save') }}</button>
          <span v-if="done === 'rename'" role="status" class="ok">{{ t('admin.saved') }}</span>
        </div>
      </form>

      <form class="card" novalidate aria-labelledby="brand-title" @submit.prevent="saveBrand">
        <h2 id="brand-title">{{ t('admin.est.branding') }}</h2>
        <ErrorNotice :error="brandAction.error.value" />
        <div class="grid grid--kpi">
          <div class="field">
            <label for="b-accent">{{ t('admin.est.accent') }}</label>
            <div class="row">
              <input id="b-color" v-model="brand.accent" type="color" class="swatch" :aria-label="t('admin.est.accentPicker')" :disabled="!canManage" />
              <input id="b-accent" v-model="brand.accent" type="text" maxlength="7" class="hex" :aria-invalid="!accentOk" aria-describedby="b-accent-hint" :disabled="!canManage" />
            </div>
            <span id="b-accent-hint" class="hint" :class="{ error: !accentOk }">{{ accentOk ? t('admin.est.accentOk', { ratio: accentContrast.toFixed(1) }) : t('admin.est.accentBad') }}</span>
          </div>
          <div class="field">
            <label for="b-initial">{{ t('admin.est.initial') }}</label>
            <input id="b-initial" v-model="brand.initial" type="text" maxlength="2" :disabled="!canManage" />
            <span class="hint">{{ t('admin.est.initialHint') }}</span>
          </div>
        </div>
        <div class="field">
          <label for="b-headers">{{ t('admin.est.headers') }}</label>
          <textarea id="b-headers" v-model="brand.headers" rows="5" aria-describedby="b-headers-hint" :aria-invalid="tooManyHeaders" :disabled="!canManage" />
          <span id="b-headers-hint" class="hint" :class="{ error: tooManyHeaders }">{{ tooManyHeaders ? t('admin.est.headersBad') : t('admin.est.headersHint') }}</span>
        </div>
        <div class="field">
          <label for="b-auth">{{ t('admin.est.authorizations') }}</label>
          <textarea id="b-auth" v-model="brand.authorizations" rows="3" :disabled="!canManage" />
          <span class="hint">{{ t('admin.est.authorizationsHint') }}</span>
        </div>
        <fieldset class="field sign" :disabled="!canManage">
          <legend class="label">{{ t('admin.est.signatories') }}</legend>
          <div v-for="(s, i) in brand.signatories" :key="i" class="row sign__row">
            <div class="field grow"><label :for="`s-n-${i}`">{{ t('admin.est.signName') }}</label><input :id="`s-n-${i}`" v-model="s.name" type="text" maxlength="120" /></div>
            <div class="field grow"><label :for="`s-t-${i}`">{{ t('admin.est.signTitle') }}</label><input :id="`s-t-${i}`" v-model="s.title" type="text" maxlength="120" /></div>
            <button type="button" class="btn btn--quiet" :aria-label="t('admin.est.signRemove', { n: i + 1 })" @click="brand.signatories.splice(i, 1)">{{ t('admin.remove') }}</button>
          </div>
          <button v-if="brand.signatories.length < 10" type="button" class="btn btn--quiet" @click="brand.signatories.push({ name: '', title: '' })">{{ t('admin.est.signAdd') }}</button>
        </fieldset>
        <p class="muted small">{{ t('admin.est.logoNote') }}</p>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="!canManage || brandAction.busy.value || !accentOk || tooManyHeaders">{{ t('admin.save') }}</button>
          <span v-if="done === 'branding'" role="status" class="ok">{{ t('admin.saved') }}</span>
        </div>
      </form>

      <form class="card" novalidate aria-labelledby="mod-title" @submit.prevent="saveMods">
        <h2 id="mod-title">{{ t('admin.est.modulesTitle') }}</h2>
        <p class="muted">{{ t('admin.est.modulesHint') }}</p>
        <ErrorNotice :error="moduleAction.error.value" />
        <fieldset class="mods" :disabled="!canManage">
          <legend class="sr-only">{{ t('admin.est.modulesTitle') }}</legend>
          <label v-for="m in available" :key="m" class="check"><input v-model="enabled" type="checkbox" :value="m" /> {{ moduleLabel(m) }}</label>
        </fieldset>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="!canManage || moduleAction.busy.value">{{ t('admin.save') }}</button>
          <span v-if="done === 'modules'" role="status" class="ok">{{ t('admin.saved') }}</span>
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.ok {
  color: var(--success);
  font-weight: 700;
}
.swatch {
  width: var(--tap);
  height: var(--tap);
  padding: 2px;
  border: 1px solid var(--input-border);
  background: var(--surface);
}
.hex {
  max-width: 9rem;
}
.sign {
  border: 0;
  padding: 0;
  margin: 0 0 18px;
}
.sign__row {
  align-items: flex-end;
}
.grow {
  flex: 1;
  min-width: 180px;
  margin-bottom: 0;
}
.mods {
  border: 0;
  padding: 0;
  margin: 0 0 12px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}
.small {
  font-size: 13px;
}
</style>
