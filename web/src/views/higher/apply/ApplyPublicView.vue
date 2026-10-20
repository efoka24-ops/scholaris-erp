<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import CodeInput from '@/components/CodeInput.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError, http } from '@/api/http';
import { publicApply, type OpenCampaign, type PublicInstitute, type RegistrationChallenge } from '@/api/higher-apply';
import { formatDate, formatXaf } from '@/lib/format';
import { hk, useApplyI18n } from './i18n';

// Page publique « Candidater » (sans compte, pensée pour un téléphone) : formations ouvertes d'un institut, puis création de
// l'espace candidat (identité, code reçu par SMS ou e-mail, mot de passe). Un lien invalide affiche toujours le même message.
const props = defineProps<{ slug: string }>();
const { ta, locale } = useApplyI18n();

type Step = 'list' | 'identify' | 'code' | 'password' | 'done';
const step = ref<Step>('list');
const institute = ref<PublicInstitute | null>(null);
const notFound = ref(false);
const loading = ref(true);
const busy = ref(false);
const error = ref<unknown>(null);
const chosen = ref<OpenCampaign | null>(null);
const challenge = ref<RegistrationChallenge | null>(null);
const resendIn = ref(0);
const code = ref('');
const ticket = ref('');
const form = reactive({ first_name: '', last_name: '', identifier: '', website: '', password: '', confirmation: '', tried: false });
let timer: ReturnType<typeof setInterval> | null = null;

const mismatch = computed(() => form.confirmation.length > 0 && form.confirmation !== form.password);
const canSetPassword = computed(() => form.password.length >= 10 && form.password === form.confirmation);
const redirect = computed(() => `/superieur/ma-candidature${chosen.value ? `?campagne=${encodeURIComponent(chosen.value.id)}` : ''}`);
const missing = (value: string): boolean => form.tried && value.trim() === '';

onMounted(async () => {
  try {
    institute.value = await publicApply.institute(props.slug);
  } catch (e) {
    // Lien inconnu, désactivé ou mal formé : même message, aucune précision.
    if (e instanceof ApiError && e.status === 404) notFound.value = true;
    else error.value = e;
  } finally {
    loading.value = false;
  }
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});

function startTimer(): void {
  if (timer) clearInterval(timer);
  timer = setInterval(() => {
    resendIn.value = Math.max(0, resendIn.value - 1);
  }, 1000);
}

function choose(campaign: OpenCampaign): void {
  chosen.value = campaign;
  step.value = 'identify';
}

async function register(): Promise<void> {
  form.tried = true;
  error.value = null;
  if ([form.first_name, form.last_name, form.identifier].some((v) => v.trim() === '')) return;
  busy.value = true;
  try {
    const res = await publicApply.register(props.slug, {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      identifier: form.identifier.trim(),
      locale: locale.value === 'en' ? 'en' : 'fr',
      ...(form.website ? { website: form.website } : {}),
    });
    challenge.value = res;
    resendIn.value = res.resend_in;
    code.value = '';
    step.value = 'code';
    startTimer();
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && e.status === 429 && e.retryAfter) resendIn.value = e.retryAfter;
  } finally {
    busy.value = false;
  }
}

async function verify(): Promise<void> {
  error.value = null;
  busy.value = true;
  try {
    const res = await http.post<{ ticket: string }>('/auth/activation/verify', { identifier: form.identifier.trim(), code: code.value.trim() }, { establishmentId: false });
    ticket.value = res.ticket;
    step.value = 'password';
  } catch (e) {
    error.value = e;
  } finally {
    busy.value = false;
  }
}

async function savePassword(): Promise<void> {
  error.value = null;
  if (!canSetPassword.value) return;
  busy.value = true;
  try {
    await http.post('/auth/activation/password', { ticket: ticket.value, password: form.password, password_confirmation: form.confirmation }, { establishmentId: false });
    form.password = '';
    form.confirmation = '';
    step.value = 'done';
  } catch (e) {
    error.value = e;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="ap-wrap">
    <p v-if="loading" role="status" class="muted">{{ ta('ha.pub.loading') }}</p>

    <section v-else-if="notFound" class="card ap-box" role="alert" data-test="not-found">
      <p class="eyebrow">SCHOLARIS</p>
      <p>{{ ta('ha.pub.notFound') }}</p>
    </section>

    <template v-else-if="institute">
      <header class="ap-head">
        <p class="eyebrow">SCHOLARIS</p>
        <h1 class="t28">{{ ta('ha.pub.title', { name: institute.institute.name }) }}</h1>
        <p class="muted">{{ ta('ha.pub.lede') }}</p>
      </header>

      <section v-if="step === 'list'" aria-labelledby="ap-list">
        <h2 id="ap-list">{{ ta('ha.pub.campaigns') }}</h2>
        <p v-if="institute.campaigns.length === 0" class="notice" data-test="none">{{ ta('ha.pub.none') }}</p>
        <ul class="ap-list">
          <li v-for="c in institute.campaigns" :key="c.id">
            <article class="card ap-campaign" :data-test="`campaign-${c.id}`">
              <h3>{{ c.program }} · {{ c.level }}</h3>
              <p class="muted">{{ c.title }} · {{ c.school }}</p>
              <ul class="ap-facts">
                <li>{{ ta('ha.pub.dates', { from: formatDate(c.opens_on, locale), to: formatDate(c.closes_on, locale) }) }}</li>
                <li>{{ ta('ha.pub.seats', { count: c.seats }) }}</li>
                <li>{{ c.application_fee_xaf > 0 ? ta('ha.pub.fee', { amount: formatXaf(c.application_fee_xaf, locale) }) : ta('ha.pub.feeNone') }}</li>
                <li>{{ ta(hk('ha.pub.mode.', c.selection_mode)) }}</li>
              </ul>
              <details v-if="c.required_documents.length">
                <summary>{{ ta('ha.pub.documents') }}</summary>
                <ul>
                  <li v-for="d in c.required_documents" :key="d.code">{{ d.label }}<span v-if="d.blocking" class="muted"> ({{ ta('ha.pub.blocking') }})</span></li>
                </ul>
              </details>
              <button type="button" class="btn btn--primary btn--block" @click="choose(c)">{{ ta('ha.pub.apply') }}</button>
            </article>
          </li>
        </ul>
        <p><router-link :to="{ name: 'login', query: { redirect: '/superieur/ma-candidature' } }">{{ ta('ha.reg.haveAccount') }}</router-link></p>
      </section>

      <section v-else class="card ap-box" aria-labelledby="ap-reg">
        <h2 id="ap-reg">{{ ta('ha.reg.title') }}</h2>
        <p v-if="chosen" class="muted">{{ chosen.program }} · {{ chosen.level }}</p>

        <form v-if="step === 'identify'" novalidate @submit.prevent="register">
          <p class="muted">{{ ta('ha.reg.lede') }}</p>
          <div class="field">
            <label for="ap-fn">{{ ta('ha.reg.firstName') }}</label>
            <input id="ap-fn" v-model="form.first_name" type="text" autocomplete="given-name" required :aria-invalid="missing(form.first_name)" />
            <span v-if="missing(form.first_name)" class="error">{{ ta('ha.reg.required') }}</span>
          </div>
          <div class="field">
            <label for="ap-ln">{{ ta('ha.reg.lastName') }}</label>
            <input id="ap-ln" v-model="form.last_name" type="text" autocomplete="family-name" required :aria-invalid="missing(form.last_name)" />
            <span v-if="missing(form.last_name)" class="error">{{ ta('ha.reg.required') }}</span>
          </div>
          <div class="field">
            <label for="ap-id">{{ ta('ha.reg.identifier') }}</label>
            <input id="ap-id" v-model="form.identifier" type="text" inputmode="email" autocomplete="username" autocapitalize="none" required aria-describedby="ap-id-hint" :aria-invalid="missing(form.identifier)" />
            <span id="ap-id-hint" class="hint">{{ ta('ha.reg.identifierHint') }}</span>
            <span v-if="missing(form.identifier)" class="error">{{ ta('ha.reg.required') }}</span>
          </div>
          <!-- Champ piège : invisible pour les personnes, rempli par les robots. -->
          <div class="ap-trap" aria-hidden="true"><label for="ap-web">Website</label><input id="ap-web" v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></div>
          <ErrorNotice :error="error" />
          <button type="submit" class="btn btn--primary btn--block" :disabled="busy">{{ ta('ha.reg.send') }}</button>
          <button type="button" class="btn btn--quiet" @click="step = 'list'">{{ ta('ha.back') }}</button>
        </form>

        <form v-else-if="step === 'code'" novalidate @submit.prevent="verify">
          <p role="status">{{ ta('ha.reg.sent', { to: challenge?.masked_to ?? '' }) }}</p>
          <div class="field">
            <span class="label">{{ ta('ha.reg.code') }}</span>
            <CodeInput id="ap-code" v-model="code" />
          </div>
          <ErrorNotice :error="error" />
          <button type="submit" class="btn btn--primary btn--block" :disabled="busy || code.trim().length !== 6">{{ ta('ha.reg.verify') }}</button>
          <p class="muted">{{ ta('ha.reg.noCode') }}</p>
          <button type="button" class="btn btn--quiet" :disabled="resendIn > 0 || busy" @click="register">
            {{ resendIn > 0 ? ta('ha.reg.resendIn', { seconds: resendIn }) : ta('ha.reg.resend') }}
          </button>
          <router-link :to="{ name: 'login', query: { redirect } }">{{ ta('ha.reg.haveAccount') }}</router-link>
        </form>

        <form v-else-if="step === 'password'" novalidate @submit.prevent="savePassword">
          <div class="field">
            <label for="ap-pw">{{ ta('ha.reg.password') }}</label>
            <input id="ap-pw" v-model="form.password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="ap-pw-hint" />
            <span id="ap-pw-hint" class="hint">{{ ta('ha.reg.passwordRule') }}</span>
          </div>
          <div class="field">
            <label for="ap-pw2">{{ ta('ha.reg.confirm') }}</label>
            <input id="ap-pw2" v-model="form.confirmation" type="password" autocomplete="new-password" required :aria-invalid="mismatch" />
            <span v-if="mismatch" class="error">{{ ta('ha.reg.mismatch') }}</span>
          </div>
          <ErrorNotice :error="error" />
          <button type="submit" class="btn btn--primary btn--block" :disabled="busy || !canSetPassword">{{ ta('ha.reg.save') }}</button>
        </form>

        <div v-else class="notice notice--success" role="status" data-test="done">
          <p><strong>{{ ta('ha.reg.done') }}</strong></p>
          <router-link class="btn btn--primary" :to="{ name: 'login', query: { redirect } }">{{ ta('ha.reg.login') }}</router-link>
        </div>
      </section>
    </template>

    <ErrorNotice v-if="!loading && !notFound && !institute" :error="error" />
  </main>
</template>

<style scoped>
.ap-wrap {
  max-width: 560px;
  margin: 0 auto;
  padding: 1rem;
}
.ap-head {
  margin-bottom: 1rem;
}
.ap-list {
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
  display: grid;
  gap: 0.75rem;
}
.ap-facts {
  padding-left: 1.1rem;
}
.ap-box {
  border-top: 6px solid var(--accent);
}
.ap-trap {
  position: absolute;
  left: -10000px;
  width: 1px;
  height: 1px;
  overflow: hidden;
}
</style>
