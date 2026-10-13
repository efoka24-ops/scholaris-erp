<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import CodeInput from '@/components/CodeInput.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError, http } from '@/api/http';
import type { CodeChallenge } from '@/api/types';
import { useI18n } from '@/i18n';

// Activation du compte (première connexion) ou réinitialisation du mot de passe : identifiant, code reçu, nouveau mot de passe.
const props = withDefaults(defineProps<{ mode?: 'activation' | 'reset' }>(), { mode: 'activation' });
const { t } = useI18n();

type Step = 'identify' | 'code' | 'password' | 'done';
const step = ref<Step>('identify');
const identifier = ref('');
const channel = ref<'sms' | 'email'>('sms');
const code = ref('');
const password = ref('');
const confirmation = ref('');
const ticket = ref('');
const challenge = ref<CodeChallenge | null>(null);
const expiresIn = ref(0);
const resendIn = ref(0);
const attemptsLeft = ref<number | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const title = computed(() => (props.mode === 'reset' ? t('activation.resetTitle') : t('activation.title')));
const order: Record<Step, number> = { identify: 1, code: 2, password: 3, done: 4 };
const stepClass = (n: number): string => (order[step.value] > n ? 'done' : order[step.value] === n ? 'now' : '');
const mmss = (s: number): string => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
const passwordTooShort = computed(() => password.value.length > 0 && password.value.length < 10);
const mismatch = computed(() => confirmation.value.length > 0 && confirmation.value !== password.value);
const canSetPassword = computed(() => password.value.length >= 10 && password.value === confirmation.value);

function startTimers(): void {
  if (timer) clearInterval(timer);
  timer = setInterval(() => {
    expiresIn.value = Math.max(0, expiresIn.value - 1);
    resendIn.value = Math.max(0, resendIn.value - 1);
  }, 1000);
}
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});

async function request(next?: 'sms' | 'email'): Promise<void> {
  if (next) channel.value = next;
  error.value = null;
  if (identifier.value.trim() === '') {
    error.value = new ApiError(422, { type: 'about:blank', title: '', status: 422, detail: t('login.identifierRequired') });
    return;
  }
  loading.value = true;
  try {
    const path = props.mode === 'reset' ? '/auth/password/forgot' : '/auth/activation/request';
    const res = await http.post<CodeChallenge>(path, { identifier: identifier.value.trim(), channel: channel.value });
    challenge.value = res;
    expiresIn.value = res.expires_in;
    resendIn.value = res.resend_in;
    attemptsLeft.value = null;
    code.value = '';
    step.value = 'code';
    startTimers();
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && e.status === 429 && e.retryAfter) resendIn.value = e.retryAfter;
  } finally {
    loading.value = false;
  }
}

async function verify(): Promise<void> {
  error.value = null;
  loading.value = true;
  try {
    const res = await http.post<{ ticket: string }>('/auth/activation/verify', { identifier: identifier.value.trim(), code: code.value.trim() });
    ticket.value = res.ticket;
    step.value = 'password';
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && typeof e.problem?.attempts_left === 'number') attemptsLeft.value = e.problem.attempts_left;
  } finally {
    loading.value = false;
  }
}

async function setPassword(): Promise<void> {
  error.value = null;
  if (!canSetPassword.value) return;
  loading.value = true;
  try {
    await http.post('/auth/activation/password', { ticket: ticket.value, password: password.value, password_confirmation: confirmation.value });
    password.value = '';
    confirmation.value = '';
    step.value = 'done';
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="wrap">
    <section class="card box" aria-labelledby="act-title">
      <p class="eyebrow">SCHOLARIS</p>
      <h1 id="act-title" class="t28">{{ title }}</h1>
      <ol class="steps" :aria-label="t('activation.steps')">
        <li :class="stepClass(1)" :aria-current="step === 'identify' ? 'step' : undefined">{{ t('activation.step1') }}</li>
        <li :class="stepClass(2)" :aria-current="step === 'code' ? 'step' : undefined">{{ t('activation.step2') }}</li>
        <li :class="stepClass(3)" :aria-current="step === 'password' ? 'step' : undefined">{{ t('activation.step3') }}</li>
      </ol>

      <form v-if="step === 'identify'" novalidate @submit.prevent="request()">
        <p class="muted">{{ mode === 'reset' ? t('activation.resetIntro') : t('activation.intro') }}</p>
        <div class="field">
          <label for="act-id">{{ t('login.identifier') }}</label>
          <input id="act-id" v-model="identifier" type="text" inputmode="email" autocomplete="username" autocapitalize="none" required />
        </div>
        <ErrorNotice :error="error" />
        <button type="submit" class="btn btn--primary btn--block" :disabled="loading">{{ t('activation.sendCode') }}</button>
      </form>

      <form v-else-if="step === 'code'" novalidate @submit.prevent="verify">
        <p>{{ t('activation.codeSent', { to: challenge?.masked_to ?? '' }) }}</p>
        <div class="field">
          <span class="label" id="act-code-label">{{ t('activation.codeLabel') }}</span>
          <CodeInput id="act-code" v-model="code" />
          <span class="hint" role="timer">{{ expiresIn > 0 ? t('activation.expiresIn', { time: mmss(expiresIn) }) : t('activation.expired') }}</span>
          <span v-if="attemptsLeft !== null" class="hint">{{ t('activation.attemptsLeft', { count: attemptsLeft }) }}</span>
        </div>
        <ErrorNotice :error="error" />
        <button type="submit" class="btn btn--primary btn--block" :disabled="loading || code.trim().length !== 6">{{ t('activation.verify') }}</button>
        <div class="row resend help">
          <strong class="full">{{ t('activation.nothing') }}</strong>
          <button type="button" class="btn btn--quiet" :disabled="resendIn > 0 || loading" @click="request()">
            {{ resendIn > 0 ? t('activation.resendIn', { seconds: resendIn }) : t('activation.resend') }}
          </button>
          <button v-if="channel === 'sms'" type="button" class="btn btn--quiet" :disabled="resendIn > 0 || loading" @click="request('email')">
            {{ t('activation.byEmail') }}
          </button>
        </div>
      </form>

      <form v-else-if="step === 'password'" novalidate @submit.prevent="setPassword">
        <div class="field">
          <label for="act-pw">{{ t('activation.newPassword') }}</label>
          <input id="act-pw" v-model="password" type="password" autocomplete="new-password" minlength="10" required :aria-invalid="passwordTooShort" aria-describedby="act-pw-hint" />
          <span id="act-pw-hint" class="hint">{{ t('activation.passwordRule') }}</span>
        </div>
        <div class="field">
          <label for="act-pw2">{{ t('activation.confirmPassword') }}</label>
          <input id="act-pw2" v-model="confirmation" type="password" autocomplete="new-password" required :aria-invalid="mismatch" />
          <span v-if="mismatch" class="error">{{ t('activation.mismatch') }}</span>
        </div>
        <ErrorNotice :error="error" />
        <button type="submit" class="btn btn--primary btn--block" :disabled="loading || !canSetPassword">{{ t('activation.save') }}</button>
      </form>

      <div v-else class="notice notice--success" role="status">
        <p>
          <strong>{{ t('activation.done') }}</strong>
        </p>
        <router-link class="btn btn--primary" :to="{ name: 'login' }">{{ t('activation.goLogin') }}</router-link>
      </div>

      <p v-if="step !== 'done'" class="back"><router-link :to="{ name: 'login' }">{{ t('activation.back') }}</router-link></p>
    </section>
  </main>
</template>

<style scoped>
.wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.box {
  width: 100%;
  max-width: 440px;
  border-top: 6px solid var(--accent);
}
.steps {
  display: flex;
  gap: 0.5rem;
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
  font-size: 12px;
  color: var(--muted);
}
.steps li {
  flex: 1;
  border-top: 4px solid var(--border);
  padding-top: 0.25rem;
}
.steps li.done {
  border-top-color: var(--ink);
}
.steps li.now {
  border-top-color: var(--accent);
  color: var(--ink);
  font-weight: 700;
}
.t28 {
  font-size: 28px;
}
.help {
  background: var(--bg);
  padding: 14px;
  font-size: 14px;
}
.full {
  width: 100%;
}
.resend {
  margin-top: 0.75rem;
}
.back {
  margin: 1rem 0 0;
}
</style>
