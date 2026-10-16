<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { documents, type SchoolProfile } from '@/api/higher';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import { P } from '../access';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useXt } from '../xi18n';

// Profil documentaire versionné de chaque école (RG-DOC-6) : code du numéro, nom affiché, en-tête, logo, couleur, langue.
// Chaque enregistrement crée une version ; les documents déjà émis gardent celle de leur émission.
const CODE = /^[A-Z0-9]{1,10}$/;
const COLOR = /^#[0-9A-Fa-f]{6}$/;
const { t, locale } = useXt();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const save = useHigherAction();

const schoolId = ref('');
const profile = ref<SchoolProfile | null>(null);
const missing = ref(false);
const saved = ref<number | null>(null);
const form = reactive({ code: '', name: '', header: '', logo: '', color: '#0B3D2E', lang: 'fr' as 'fr' | 'en', tried: false });

const canEdit = computed(() => ctx.can(...P.documentTemplates) && !higher.readOnly);
const schools = computed(() => (higher.schools.length ? higher.schools : higher.selectableSchools));
const bad = computed(() => ({
  code: !CODE.test(form.code.trim()),
  name: !form.name.trim(),
  logo: form.logo.trim() !== '' && !/^https:\/\//.test(form.logo.trim()),
  color: !COLOR.test(form.color.trim()),
}));
const invalid = computed(() => Object.values(bad.value).some(Boolean));

function fill(p: SchoolProfile | null): void {
  profile.value = p;
  Object.assign(form, {
    code: p?.school_code ?? '', name: p?.display_name ?? '', header: p?.header_text ?? '', logo: p?.logo_url ?? '',
    color: p?.primary_color ?? '#0B3D2E', lang: p?.default_locale ?? 'fr', tried: false,
  });
}

async function fetchProfile(): Promise<void> {
  saved.value = null;
  missing.value = false;
  if (!schoolId.value) return fill(null);
  const res = await load.run(() => documents.profile(schoolId.value));
  if (res) return fill(res);
  if (load.error.value instanceof ApiError && load.error.value.status === 404) {
    load.error.value = null;
    missing.value = true;
    fill(null);
  }
}

async function submit(): Promise<void> {
  form.tried = true;
  if (invalid.value) return;
  const res = await save.run(() =>
    documents.putProfile(schoolId.value, {
      school_code: form.code.trim(), display_name: form.name.trim(), header_text: form.header.trim() || null, logo_url: form.logo.trim() || null,
      primary_color: form.color.trim(), default_locale: form.lang,
    }),
  );
  if (!res) return;
  missing.value = false;
  fill(res);
  saved.value = res.version;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('schools');
  schoolId.value = higher.scopedSchoolId ?? (schools.value.length === 1 ? (schools.value[0]?.id ?? '') : '');
  await fetchProfile();
});
</script>

<template>
  <HigherFrame :title="t('hi.doc.profileTitle')" :lede="t('hi.doc.profileLede')" section="documents" :selector="false">
    <div class="field">
      <label for="sp-school">{{ t('hx.doc.school') }}</label>
      <select id="sp-school" v-model="schoolId" @change="fetchProfile"><option value="">—</option><option v-for="s in schools" :key="s.id" :value="s.id">{{ s.code ? `${s.code} · ${s.name}` : s.name }}</option></select>
    </div>
    <ErrorNotice :error="load.error.value" />
    <p v-if="!schoolId" class="muted">{{ t('hx.prof.chooseSchool') }}</p>
    <template v-else>
      <p v-if="missing" class="notice notice--warning" role="status" data-test="no-profile">{{ t('hi.doc.profileNone') }}</p>
      <p v-else-if="profile" class="muted">{{ t('hx.prof.current', { version: profile.version }) }}</p>

      <div class="hi-two hi-section">
        <form class="card card--strong" novalidate data-test="profile-form" @submit.prevent="submit">
          <fieldset :disabled="!canEdit" class="sp-fields">
            <div class="field"><label for="sp-code">{{ t('hi.doc.schoolCode') }}</label><input id="sp-code" v-model="form.code" type="text" maxlength="10" autocapitalize="characters" aria-describedby="sp-code-hint" :aria-invalid="form.tried && bad.code" /><span id="sp-code-hint" class="hint">{{ t('hi.doc.schoolCodeHint') }}</span><span v-if="form.tried && bad.code" class="error">{{ t('hi.doc.codeBad') }}</span></div>
            <div class="field"><label for="sp-name">{{ t('hi.doc.displayName') }}</label><input id="sp-name" v-model="form.name" type="text" maxlength="160" :aria-invalid="form.tried && bad.name" /><span v-if="form.tried && bad.name" class="error">{{ t('hx.prof.nameRequired') }}</span></div>
            <div class="field"><label for="sp-header">{{ t('hi.doc.header') }}</label><textarea id="sp-header" v-model="form.header" rows="3" maxlength="500" /></div>
            <div class="field"><label for="sp-logo">{{ t('hi.doc.logo') }}</label><input id="sp-logo" v-model="form.logo" type="url" inputmode="url" :aria-invalid="form.tried && bad.logo" /><span v-if="form.tried && bad.logo" class="error">{{ t('hi.doc.logoBad') }}</span></div>
            <div class="field"><label for="sp-color">{{ t('hi.doc.color') }}</label><input id="sp-color" v-model="form.color" type="text" maxlength="7" :aria-invalid="form.tried && bad.color" /><span v-if="form.tried && bad.color" class="error">{{ t('hi.doc.colorBad') }}</span></div>
            <div class="field"><label for="sp-lang">{{ t('hi.doc.defaultLocale') }}</label><select id="sp-lang" v-model="form.lang"><option value="fr">Français</option><option value="en">English</option></select></div>
          </fieldset>
          <ErrorNotice :error="save.error.value" />
          <button v-if="canEdit" type="submit" class="btn btn--primary btn--block" :disabled="save.busy.value">{{ t('hx.prof.save') }}</button>
          <p v-if="saved !== null" class="notice notice--success hi-section" role="status" data-test="saved">{{ t('hx.prof.saved', { version: saved }) }}</p>
        </form>

        <div>
          <section class="card" aria-labelledby="sp-prev">
            <h2 id="sp-prev">{{ t('hx.prof.preview') }}</h2>
            <div class="sp-preview" :style="{ borderTopColor: bad.color ? undefined : form.color }" data-test="preview">
              <img v-if="form.logo && !bad.logo" :src="form.logo" alt="" class="sp-logo" />
              <div><p class="sp-name">{{ form.name || '—' }}</p><p class="muted hi-small sp-header">{{ form.header }}</p></div>
            </div>
            <p class="hint">{{ t('hi.doc.signersGap') }}</p>
          </section>
          <section v-if="profile?.versions?.length" class="card hi-section" aria-labelledby="sp-versions">
            <h2 id="sp-versions">{{ t('hi.doc.versions') }}</h2>
            <ul class="hi-list"><li v-for="v in profile.versions" :key="v.version">{{ t('hi.doc.profileVersion', { version: v.version, date: formatDate(v.created_at, locale) }) }} · {{ v.school_code }} · {{ v.display_name }}</li></ul>
          </section>
        </div>
      </div>
    </template>
  </HigherFrame>
</template>

<style scoped>
.sp-fields {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}
.sp-preview {
  display: flex;
  gap: 12px;
  align-items: center;
  border-top: 6px solid var(--ink);
  padding-top: 12px;
}
.sp-logo {
  max-width: 64px;
  max-height: 64px;
}
.sp-name {
  font-weight: 700;
  margin: 0;
}
.sp-header {
  white-space: pre-line;
  margin: 0;
}
</style>
