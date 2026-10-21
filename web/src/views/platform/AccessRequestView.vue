<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PublicLayout from './PublicLayout.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import {
  APP_TARGETS,
  TRACKING_TOKEN,
  fetchPublicApplications,
  normalizePhone,
  submitAccessRequest,
  type AccessRequestInput,
  type AccessRequestReceipt,
  type AppTarget,
  type PublicApplication,
} from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { sectorKey, targetKey } from './display';
import { SCHOOL_TYPES, groupByLevel, isSchoolType, needsSchoolType } from './catalogGroups';

// Demande d'accès sans compte — design : Inscription.html (sections de formulaire, libellés 13 px, champs 44 px) et Login.html.
// Protections : champ piège « website » caché, jamais rempli par une personne ; validation stricte côté serveur (422).
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();

type Field =
  | 'institution_name' | 'target_type' | 'city' | 'region' | 'contact_first_name' | 'contact_last_name'
  | 'contact_email' | 'contact_phone' | 'contact_position' | 'ministerial_authorization' | 'app_key' | 'establishment_subtype' | 'message';

const ORDER: Field[] = [
  'institution_name', 'target_type', 'city', 'region', 'ministerial_authorization', 'contact_first_name', 'contact_last_name',
  'contact_email', 'contact_phone', 'contact_position', 'app_key', 'establishment_subtype', 'message',
];

const form = reactive<Record<Field, string>>({
  institution_name: '', target_type: '', city: '', region: '', contact_first_name: '', contact_last_name: '',
  contact_email: '', contact_phone: '', contact_position: '', ministerial_authorization: '', app_key: '', establishment_subtype: '', message: '',
});
/** Champ piège : lié à un champ invisible ; une personne ne le remplit jamais. */
const website = ref('');
const errors = ref<Partial<Record<Field, string>>>({});
const catalog = ref<PublicApplication[]>([]);
const catalogError = ref<unknown>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const receipt = ref<AccessRequestReceipt | null>(null);
const doneTitle = ref<HTMLElement | null>(null);
const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
const trackToken = ref('');
const trackInvalid = ref(false);

const NAME = /^\p{L}[\p{L}\p{M} '’.-]*$/u;
const AUTHORIZATION = /^[\p{L}\p{N} /._°-]+$/u;
const EMAIL = /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/;

const appsForTarget = computed(() => groupByLevel(catalog.value.filter((a) => (a.family ?? a.target) === form.target_type))[0]?.regimes ?? []);
/** Secondaire : une seule application, le type d'établissement (obligatoire) la personnalise. */
const regimeText = (r: (typeof appsForTarget.value)[number]): string => {
  const name = appsForTarget.value.length > 1 ? t(sectorKey(r.sector)) : r.app.label;
  return r.app.status === 'available' ? name : t('pub.req.soonSuffix', { label: name });
};
const askSchoolType = computed(() => needsSchoolType(form.target_type));

watch(
  () => form.target_type,
  () => {
    if (!appsForTarget.value.some((r) => r.app.key === form.app_key)) {
      const available = appsForTarget.value.filter((r) => r.app.status === 'available');
      form.app_key = appsForTarget.value.length === 1 && appsForTarget.value[0] ? appsForTarget.value[0].app.key : available.length === 1 && available[0] ? available[0].app.key : '';
    }
  },
);

watch(askSchoolType, (asked) => {
  if (!asked) form.establishment_subtype = '';
});

onMounted(async () => {
  try {
    catalog.value = await fetchPublicApplications();
  } catch (e) {
    catalogError.value = e;
  }
  const wanted = typeof route.query.application === 'string' ? route.query.application : null;
  const app = catalog.value.find((a) => a.key === wanted);
  if (app) {
    form.target_type = app.family ?? app.target;
    await nextTick();
    form.app_key = app.key;
  }
  const subtype = typeof route.query.subtype === 'string' ? route.query.subtype : '';
  if (isSchoolType(subtype) && form.target_type === 'secondary') form.establishment_subtype = subtype;
});

const clean = (v: string): string => v.replace(/\s+/g, ' ').trim();
const between = (v: string, min: number, max: number): boolean => v.length >= min && v.length <= max && !/[<>]/.test(v);

function validate(): Partial<Record<Field, string>> {
  const e: Partial<Record<Field, string>> = {};
  const msg = (k: MessageKey): string => t(k);
  if (!between(clean(form.institution_name), 3, 160)) e.institution_name = msg('pub.req.err.institution');
  if (!(APP_TARGETS as string[]).includes(form.target_type)) e.target_type = msg('pub.req.err.target');
  if (!between(clean(form.city), 2, 80)) e.city = msg('pub.req.err.city');
  if (!between(clean(form.region), 2, 80)) e.region = msg('pub.req.err.region');
  const auth = clean(form.ministerial_authorization);
  if (auth !== '' && (!between(auth, 2, 120) || !AUTHORIZATION.test(auth))) e.ministerial_authorization = msg('pub.req.err.authorization');
  for (const f of ['contact_first_name', 'contact_last_name'] as const) {
    const v = clean(form[f]);
    if (!between(v, 1, 80) || !NAME.test(v)) e[f] = msg('pub.req.err.name');
  }
  const email = clean(form.contact_email);
  if (email.length > 190 || !EMAIL.test(email)) e.contact_email = msg('pub.req.err.email');
  if (clean(form.contact_phone) !== '' && normalizePhone(form.contact_phone) === null) e.contact_phone = msg('pub.req.err.phone');
  const position = clean(form.contact_position);
  if (position !== '' && !between(position, 2, 80)) e.contact_position = msg('pub.req.err.position');
  if (form.app_key === '') e.app_key = msg('pub.req.err.app');
  if (askSchoolType.value && !isSchoolType(form.establishment_subtype)) e.establishment_subtype = msg('pub.req.err.schoolType');
  if (form.message.trim().length > 2000) e.message = msg('pub.req.err.message');
  return e;
}

async function focusFirstError(): Promise<void> {
  await nextTick();
  const first = ORDER.find((f) => errors.value[f]);
  if (first) document.getElementById(`ar-${first}`)?.focus();
}

function payload(): AccessRequestInput {
  const opt = (v: string): string | undefined => (clean(v) === '' ? undefined : clean(v));
  const body: AccessRequestInput = {
    institution_name: clean(form.institution_name),
    target_type: form.target_type as AppTarget,
    city: clean(form.city),
    region: clean(form.region),
    contact_first_name: clean(form.contact_first_name),
    contact_last_name: clean(form.contact_last_name),
    contact_email: clean(form.contact_email).toLowerCase(),
    contact_phone: normalizePhone(form.contact_phone) ?? undefined,
    contact_position: opt(form.contact_position),
    ministerial_authorization: opt(form.ministerial_authorization),
    app_key: form.app_key,
    establishment_subtype: askSchoolType.value ? form.establishment_subtype : undefined,
    message: form.message.trim() === '' ? undefined : form.message.trim(),
    locale: locale.value,
  };
  // Le champ piège n'est transmis que s'il a été rempli (par un robot) : le serveur répond alors par un leurre.
  if (website.value !== '') body.website = website.value;
  return JSON.parse(JSON.stringify(body)) as AccessRequestInput;
}

async function submit(): Promise<void> {
  error.value = null;
  errors.value = validate();
  if (Object.keys(errors.value).length > 0) {
    await focusFirstError();
    return;
  }
  busy.value = true;
  try {
    receipt.value = await submitAccessRequest(payload());
    copyState.value = 'idle';
    await nextTick();
    doneTitle.value?.focus();
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && e.status === 422) {
      const fromServer: Partial<Record<Field, string>> = {};
      for (const [field, messages] of Object.entries(e.fieldErrors)) {
        if ((ORDER as string[]).includes(field) && messages[0]) fromServer[field as Field] = messages[0];
      }
      errors.value = fromServer;
      await focusFirstError();
    }
  } finally {
    busy.value = false;
  }
}

async function copyToken(): Promise<void> {
  if (!receipt.value) return;
  try {
    await navigator.clipboard.writeText(receipt.value.tracking_token);
    copyState.value = 'copied';
  } catch {
    copyState.value = 'failed';
  }
}

function selectToken(event: Event): void {
  (event.target as HTMLInputElement).select();
}

function restart(): void {
  receipt.value = null;
  for (const f of ORDER) form[f] = '';
  errors.value = {};
}

async function track(): Promise<void> {
  const token = trackToken.value.trim().toLowerCase();
  trackInvalid.value = !TRACKING_TOKEN.test(token);
  if (trackInvalid.value) return;
  await router.push({ name: 'access-tracking', params: { token } });
}

const describedBy = (f: Field, hint = false): string | undefined => [hint ? `ar-${f}-hint` : '', errors.value[f] ? `ar-${f}-err` : ''].filter(Boolean).join(' ') || undefined;
</script>

<template>
  <PublicLayout>
    <div class="page narrow">
      <header class="page-head">
        <p class="eyebrow">{{ t('pub.req.eyebrow') }}</p>
        <h1>{{ t('pub.req.title') }}</h1>
        <p class="lede">{{ t('pub.req.lede') }}</p>
      </header>

      <section v-if="receipt" class="card card--strong done" aria-labelledby="ar-done-title">
        <h2 id="ar-done-title" ref="doneTitle" tabindex="-1">{{ t('pub.req.doneTitle') }}</h2>
        <p>{{ receipt.message || t('pub.req.doneText') }}</p>
        <div class="field">
          <label for="ar-token">{{ t('pub.req.tokenLabel') }}</label>
          <input id="ar-token" class="token" type="text" readonly :value="receipt.tracking_token" aria-describedby="ar-token-hint" @focus="selectToken" />
          <span id="ar-token-hint" class="hint">{{ t('pub.req.tokenHint') }}</span>
        </div>
        <div class="row">
          <button type="button" class="btn btn--ink" @click="copyToken">{{ t('pub.req.copy') }}</button>
          <router-link class="btn btn--primary" :to="{ name: 'access-tracking', params: { token: receipt.tracking_token } }">{{ t('pub.req.trackNow') }} →</router-link>
          <button type="button" class="btn btn--quiet" @click="restart">{{ t('pub.req.another') }}</button>
        </div>
        <p v-if="copyState === 'copied'" class="ok" role="status">{{ t('pub.req.copied') }}</p>
        <p v-else-if="copyState === 'failed'" class="bad" role="status">{{ t('pub.req.copyFailed') }}</p>
      </section>

      <form v-else class="stack" novalidate @submit.prevent="submit">
        <p class="muted">{{ t('pub.req.required') }}</p>
        <ErrorNotice :error="catalogError" />
        <ErrorNotice :error="error" />
        <p v-if="Object.keys(errors).length > 0 && !error" class="notice notice--warning" role="alert">{{ t('pub.req.fixErrors') }}</p>

        <fieldset class="card">
          <legend class="h3">{{ t('pub.req.section.institution') }}</legend>
          <div class="field">
            <label for="ar-institution_name">{{ t('pub.req.institution') }}</label>
            <input id="ar-institution_name" v-model="form.institution_name" type="text" maxlength="160" autocomplete="organization" required :aria-invalid="!!errors.institution_name" :aria-describedby="describedBy('institution_name')" />
            <span v-if="errors.institution_name" id="ar-institution_name-err" class="error">{{ errors.institution_name }}</span>
          </div>
          <div class="cols">
            <div class="field">
              <label for="ar-target_type">{{ t('pub.req.target') }}</label>
              <select id="ar-target_type" v-model="form.target_type" required :aria-invalid="!!errors.target_type" :aria-describedby="describedBy('target_type')">
                <option value="" disabled>{{ t('pub.req.chooseTarget') }}</option>
                <option v-for="tg in APP_TARGETS" :key="tg" :value="tg">{{ t(targetKey(tg)) }}</option>
              </select>
              <span v-if="errors.target_type" id="ar-target_type-err" class="error">{{ errors.target_type }}</span>
            </div>
            <div class="field">
              <label for="ar-ministerial_authorization">{{ t('pub.req.authorization') }}</label>
              <input id="ar-ministerial_authorization" v-model="form.ministerial_authorization" type="text" maxlength="120" autocomplete="off" :aria-invalid="!!errors.ministerial_authorization" :aria-describedby="describedBy('ministerial_authorization', true)" />
              <span id="ar-ministerial_authorization-hint" class="hint">{{ t('pub.req.authorizationHint') }}</span>
              <span v-if="errors.ministerial_authorization" id="ar-ministerial_authorization-err" class="error">{{ errors.ministerial_authorization }}</span>
            </div>
            <div class="field">
              <label for="ar-city">{{ t('pub.req.city') }}</label>
              <input id="ar-city" v-model="form.city" type="text" maxlength="80" autocomplete="address-level2" required :aria-invalid="!!errors.city" :aria-describedby="describedBy('city')" />
              <span v-if="errors.city" id="ar-city-err" class="error">{{ errors.city }}</span>
            </div>
            <div class="field">
              <label for="ar-region">{{ t('pub.req.region') }}</label>
              <input id="ar-region" v-model="form.region" type="text" maxlength="80" autocomplete="address-level1" required :aria-invalid="!!errors.region" :aria-describedby="describedBy('region')" />
              <span v-if="errors.region" id="ar-region-err" class="error">{{ errors.region }}</span>
            </div>
          </div>
        </fieldset>

        <fieldset class="card">
          <legend class="h3">{{ t('pub.req.section.contact') }}</legend>
          <div class="cols">
            <div class="field">
              <label for="ar-contact_first_name">{{ t('pub.req.firstName') }}</label>
              <input id="ar-contact_first_name" v-model="form.contact_first_name" type="text" maxlength="80" autocomplete="given-name" required :aria-invalid="!!errors.contact_first_name" :aria-describedby="describedBy('contact_first_name')" />
              <span v-if="errors.contact_first_name" id="ar-contact_first_name-err" class="error">{{ errors.contact_first_name }}</span>
            </div>
            <div class="field">
              <label for="ar-contact_last_name">{{ t('pub.req.lastName') }}</label>
              <input id="ar-contact_last_name" v-model="form.contact_last_name" type="text" maxlength="80" autocomplete="family-name" required :aria-invalid="!!errors.contact_last_name" :aria-describedby="describedBy('contact_last_name')" />
              <span v-if="errors.contact_last_name" id="ar-contact_last_name-err" class="error">{{ errors.contact_last_name }}</span>
            </div>
            <div class="field">
              <label for="ar-contact_email">{{ t('pub.req.email') }}</label>
              <input id="ar-contact_email" v-model="form.contact_email" type="email" maxlength="190" autocomplete="email" inputmode="email" required :aria-invalid="!!errors.contact_email" :aria-describedby="describedBy('contact_email', true)" />
              <span id="ar-contact_email-hint" class="hint">{{ t('pub.req.emailHint') }}</span>
              <span v-if="errors.contact_email" id="ar-contact_email-err" class="error">{{ errors.contact_email }}</span>
            </div>
            <div class="field">
              <label for="ar-contact_phone">{{ t('pub.req.phone') }}</label>
              <input id="ar-contact_phone" v-model="form.contact_phone" type="tel" maxlength="25" autocomplete="tel" inputmode="tel" :aria-invalid="!!errors.contact_phone" :aria-describedby="describedBy('contact_phone', true)" />
              <span id="ar-contact_phone-hint" class="hint">{{ t('pub.req.phoneHint') }}</span>
              <span v-if="errors.contact_phone" id="ar-contact_phone-err" class="error">{{ errors.contact_phone }}</span>
            </div>
            <div class="field">
              <label for="ar-contact_position">{{ t('pub.req.position') }}</label>
              <input id="ar-contact_position" v-model="form.contact_position" type="text" maxlength="80" autocomplete="organization-title" :aria-invalid="!!errors.contact_position" :aria-describedby="describedBy('contact_position', true)" />
              <span id="ar-contact_position-hint" class="hint">{{ t('pub.req.positionHint') }}</span>
              <span v-if="errors.contact_position" id="ar-contact_position-err" class="error">{{ errors.contact_position }}</span>
            </div>
          </div>
        </fieldset>

        <fieldset class="card">
          <legend class="h3">{{ t('pub.req.section.app') }}</legend>
          <fieldset class="field regime-field">
            <legend>{{ appsForTarget.length > 1 ? t('pub.req.regime') : t('pub.req.app') }}</legend>
            <div class="regimes" role="radiogroup" :aria-describedby="describedBy('app_key', true)">
              <label v-for="(r, i) in appsForTarget" :key="r.app.key" class="regime" :class="{ 'regime--on': form.app_key === r.app.key }">
                <input :id="i === 0 ? 'ar-app_key' : undefined" v-model="form.app_key" type="radio" name="ar-regime" :value="r.app.key" :aria-invalid="!!errors.app_key" />
                <span>{{ regimeText(r) }}</span>
                <span v-if="appsForTarget.length > 1" class="muted small">{{ r.app.label }}</span>
              </label>
            </div>
            <span id="ar-app_key-hint" class="hint">{{ form.target_type === '' ? t('pub.req.chooseTargetFirst') : appsForTarget.length === 0 ? t('pub.req.noAppForTarget') : (appsForTarget.length > 1 ? t('pub.req.regimeHint') : '') }}</span>
            <span v-if="errors.app_key" id="ar-app_key-err" class="error">{{ errors.app_key }}</span>
          </fieldset>
          <div v-if="askSchoolType" class="field">
            <label for="ar-establishment_subtype">{{ t('pub.req.schoolType') }}</label>
            <select id="ar-establishment_subtype" v-model="form.establishment_subtype" required :aria-invalid="!!errors.establishment_subtype" :aria-describedby="describedBy('establishment_subtype', true)">
              <option value="" disabled>{{ t('pub.req.chooseSchoolType') }}</option>
              <option v-for="st in SCHOOL_TYPES" :key="st" :value="st">{{ t(('pub.schoolType.' + st) as MessageKey) }}</option>
            </select>
            <span id="ar-establishment_subtype-hint" class="hint">{{ t('pub.req.schoolTypeHint') }}</span>
            <span v-if="errors.establishment_subtype" id="ar-establishment_subtype-err" class="error">{{ errors.establishment_subtype }}</span>
          </div>
          <div class="field">
            <label for="ar-message">{{ t('pub.req.message') }}</label>
            <textarea id="ar-message" v-model="form.message" maxlength="2000" rows="5" :aria-invalid="!!errors.message" :aria-describedby="describedBy('message', true)" />
            <span id="ar-message-hint" class="hint">{{ t('pub.req.messageHint') }} ({{ form.message.length }} / 2000)</span>
            <span v-if="errors.message" id="ar-message-err" class="error">{{ errors.message }}</span>
          </div>
        </fieldset>

        <!-- Champ piège : hors écran, ignoré des lecteurs d'écran et du clavier, jamais prérempli. -->
        <div class="trap" aria-hidden="true">
          <label for="ar-website">{{ t('pub.req.honeypot') }}</label>
          <input id="ar-website" v-model="website" type="text" name="website" tabindex="-1" autocomplete="off" />
        </div>

        <button type="submit" class="btn btn--primary btn--large" :disabled="busy">{{ busy ? t('pub.req.sending') : t('pub.req.submit') }}</button>
      </form>

      <section class="card have" aria-labelledby="ar-have-title">
        <h2 id="ar-have-title" class="h3">{{ t('pub.req.haveToken') }}</h2>
        <p class="muted">{{ t('pub.req.haveTokenText') }}</p>
        <form class="row have__form" novalidate @submit.prevent="track">
          <div class="field grow">
            <label for="ar-track">{{ t('pub.track.tokenField') }}</label>
            <input id="ar-track" v-model="trackToken" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="64" :aria-invalid="trackInvalid" :aria-describedby="trackInvalid ? 'ar-track-err' : undefined" />
            <span v-if="trackInvalid" id="ar-track-err" class="error">{{ t('pub.track.tokenInvalid') }}</span>
          </div>
          <button type="submit" class="btn">{{ t('pub.track.go') }}</button>
        </form>
      </section>
    </div>
  </PublicLayout>
</template>

<style scoped>
.regime-field {
  border: 0;
  padding: 0;
}
.regime-field legend {
  font-size: 13px;
  font-weight: 700;
  padding: 0;
  margin-bottom: 6px;
}
.regimes {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.regime {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: var(--tap);
  padding: 4px 14px;
  border: 1.5px solid var(--border, currentColor);
  border-radius: 999px;
  font-weight: 700;
  cursor: pointer;
}
.regime--on {
  border-color: var(--ink, #14213d);
  box-shadow: inset 0 0 0 1px var(--ink, #14213d);
}
.regime .small {
  font-size: 13px;
  font-weight: 400;
}
.narrow {
  max-width: 880px;
}
fieldset {
  margin: 0;
  min-width: 0;
}
legend.h3 {
  font-size: 17px;
  font-weight: 800;
  padding: 0 6px;
}
.cols {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 0 16px;
}
.token {
  font-family: ui-monospace, Consolas, monospace;
  font-size: 15px;
  letter-spacing: 0.02em;
}
.done h2:focus {
  outline: none;
}
.ok {
  color: var(--success);
  font-weight: 700;
  margin: 12px 0 0;
}
.bad {
  color: var(--warning);
  font-weight: 700;
  margin: 12px 0 0;
}
.trap {
  position: absolute;
  left: -10000px;
  top: auto;
  width: 1px;
  height: 1px;
  overflow: hidden;
}
.have {
  margin-top: 32px;
}
.have__form {
  align-items: flex-end;
}
.have__form .field {
  margin-bottom: 0;
}
.grow {
  flex: 1 1 260px;
}
</style>
