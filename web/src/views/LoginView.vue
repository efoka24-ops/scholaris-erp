<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { useAuthStore } from '@/stores/auth';
import { useI18n } from '@/i18n';

// Connexion — design : Login.html (panneau noir à gauche, formulaire à droite).
const { t, locale, setLocale } = useI18n();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const identifier = ref('');
const password = ref('');
/** Mode « application d'authentification » : identifiant + code à 6 chiffres, réservé à l'administrateur de la plateforme. */
const authenticatorMode = ref(false);
const code = ref('');
const codeInvalid = ref(false);
const remember = ref(false);
const loading = ref(false);
const error = ref<unknown>(null);
const lockSeconds = ref(0);
const identifierInvalid = ref(false);
const passwordInvalid = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

const locked = computed(() => lockSeconds.value > 0);
const lockLabel = computed(() => {
  const m = Math.floor(lockSeconds.value / 60);
  const s = String(lockSeconds.value % 60).padStart(2, '0');
  return `${m}:${s}`;
});

function startLock(seconds: number): void {
  lockSeconds.value = seconds;
  if (timer) clearInterval(timer);
  timer = setInterval(() => {
    lockSeconds.value = Math.max(0, lockSeconds.value - 1);
    if (lockSeconds.value === 0 && timer) {
      clearInterval(timer);
      timer = null;
    }
  }, 1000);
}
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});

async function submit(): Promise<void> {
  error.value = null;
  identifierInvalid.value = identifier.value.trim() === '';
  passwordInvalid.value = !authenticatorMode.value && password.value === '';
  codeInvalid.value = authenticatorMode.value && !/^\d{6}$/.test(code.value.replace(/\s+/g, ''));
  if (identifierInvalid.value || passwordInvalid.value || codeInvalid.value) return;
  loading.value = true;
  try {
    const res = authenticatorMode.value
      ? await auth.loginWithAuthenticator({ identifier: identifier.value, code: code.value, remember: remember.value })
      : await auth.login({ identifier: identifier.value, password: password.value, remember: remember.value });
    password.value = '';
    code.value = '';
    if (res.two_factor_setup_required) {
      await router.replace({ name: 'twofactor-setup' });
    } else if (res.two_factor_required) {
      await router.replace({ name: 'twofactor' });
    } else {
      const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
      await router.replace(target);
    }
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && (e.status === 423 || e.status === 429) && e.retryAfter) startLock(e.retryAfter);
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="login">
    <section class="hero">
      <div class="hero__brand">
        <span class="logo-sq logo-sq--lg">S</span>
        <span class="hero__name">SCHOLARIS</span>
      </div>
      <div class="hero__body">
        <p class="hero__title">{{ t('login.hero1') }}<br />{{ t('login.hero2') }}<br />{{ t('login.hero3') }}</p>
        <p class="hero__text">{{ t('login.heroText') }}</p>
      </div>
      <ul class="hero__facts">
        <li>{{ t('login.fact1') }}</li>
        <li>{{ t('login.fact2') }}</li>
        <li>{{ t('login.fact3') }}</li>
      </ul>
    </section>

    <section class="panel" aria-labelledby="login-title">
      <div class="lang" role="group" :aria-label="t('shell.language')">
        <button type="button" :aria-pressed="locale === 'fr'" @click="setLocale('fr')">FR</button>
        <button type="button" :aria-pressed="locale === 'en'" @click="setLocale('en')">EN</button>
      </div>
      <div class="panel__center">
        <form class="form" novalidate @submit.prevent="submit">
          <div>
            <p class="eyebrow">{{ t('login.title') }}</p>
            <h1 id="login-title" class="form__title">{{ t('login.heading') }}</h1>
          </div>
          <div class="field">
            <label for="identifier">{{ t('login.identifier') }}</label>
            <input
              id="identifier"
              v-model="identifier"
              type="text"
              inputmode="email"
              autocomplete="username"
              autocapitalize="none"
              required
              :aria-invalid="identifierInvalid"
              :aria-describedby="identifierInvalid ? 'identifier-error' : undefined"
            />
            <span v-if="identifierInvalid" id="identifier-error" class="error">{{ t('login.identifierRequired') }}</span>
          </div>
          <div v-if="authenticatorMode" class="field">
            <label for="authenticator-code">{{ t('login.authCode') }}</label>
            <input
              id="authenticator-code"
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="7"
              placeholder="000 000"
              required
              :aria-invalid="codeInvalid"
              :aria-describedby="codeInvalid ? 'authenticator-code-error' : undefined"
            />
            <span v-if="codeInvalid" id="authenticator-code-error" class="error">{{ t('login.authCodeRequired') }}</span>
            <span class="hint">{{ t('login.authCodeHint') }}</span>
          </div>
          <div v-else class="field">
            <div class="row row--between pw-head">
              <label for="password">{{ t('login.password') }}</label>
              <router-link :to="{ name: 'reset' }" class="small-link">{{ t('login.forgot') }}</router-link>
            </div>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              :aria-invalid="passwordInvalid"
              :aria-describedby="passwordInvalid ? 'password-error' : undefined"
            />
            <span v-if="passwordInvalid" id="password-error" class="error">{{ t('login.passwordRequired') }}</span>
          </div>
          <label class="check">
            <input v-model="remember" type="checkbox" />
            <span>{{ t('login.remember') }}</span>
          </label>

          <ErrorNotice :error="error" />
          <p v-if="locked" class="notice notice--warning" role="status">{{ t('login.lockedFor', { time: lockLabel }) }}</p>

          <button type="submit" class="btn btn--primary btn--large btn--block" :disabled="loading || locked">
            {{ loading ? t('common.loading') : t('login.submit') }}
          </button>

          <div class="first">
            <span>{{ authenticatorMode ? t('login.authBackText') : t('login.authText') }}</span>
            <button type="button" class="btn" @click="authenticatorMode = !authenticatorMode">
              {{ authenticatorMode ? t('login.authBack') : t('login.authUse') }}
            </button>
          </div>
          <div class="first">
            <span>{{ t('login.firstTime') }}</span>
            <router-link class="btn" :to="{ name: 'activation' }">{{ t('login.activate') }}</router-link>
          </div>
          <div class="first">
            <span>{{ t('login.requestAccess') }}</span>
            <router-link class="btn btn--ink" :to="{ name: 'access-request' }">{{ t('login.requestAccessCta') }}</router-link>
            <router-link class="small-link discover" :to="{ name: 'public-home' }">{{ t('login.discover') }} →</router-link>
          </div>
        </form>
      </div>
      <p class="legal">
        <router-link :to="{ name: 'privacy' }">{{ t('shell.privacyLink') }}</router-link>
      </p>
    </section>
  </main>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  flex-wrap: wrap;
  background: var(--surface);
}
.hero {
  flex: 1 1 420px;
  background: var(--ink);
  color: #fff;
  padding: 48px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 48px;
}
.hero__brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.logo-sq--lg {
  width: 36px;
  height: 36px;
  font-size: 20px;
}
.hero__name {
  font-weight: 800;
  font-size: 20px;
  letter-spacing: 0.04em;
}
.hero__body {
  max-width: 520px;
}
.hero__title {
  margin: 0;
  font-size: 48px;
  font-weight: 800;
  line-height: 1.05;
  letter-spacing: -0.02em;
}
.hero__text {
  margin: 20px 0 0;
  font-size: 17px;
  color: var(--on-dark-muted);
  line-height: 1.5;
}
.hero__facts {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
  font-size: 13px;
  color: var(--on-dark-muted);
}
.panel {
  flex: 1 1 480px;
  padding: 48px;
  display: flex;
  flex-direction: column;
}
.lang {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.lang button {
  min-height: 40px;
  min-width: var(--tap);
  padding: 0 12px;
  font: 700 15px var(--font);
  background: var(--surface);
  border: 1px solid var(--ink);
  cursor: pointer;
}
.lang button[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
  border-width: 2px;
}
.panel__center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.form {
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.form .field {
  margin: 0;
}
.form__title {
  margin: 8px 0 0;
  font-size: 32px;
}
.form input[type='text'],
.form input[type='password'] {
  min-height: 48px;
  font-size: 16px;
  padding: 0 14px;
}
.pw-head {
  align-items: baseline;
}
.pw-head label {
  margin: 0 0 6px;
}
.small-link {
  font-size: 13px;
  font-weight: 700;
}
.discover {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
}
.first {
  border-top: 1px solid var(--border);
  padding-top: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 14px;
  color: var(--text-2);
}
.legal {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  font-size: 13px;
  color: var(--muted);
  margin: 0;
}
.legal a {
  color: var(--muted);
}
@media (max-width: 640px) {
  .hero,
  .panel {
    padding: 24px;
  }
  .hero__title {
    font-size: 32px;
  }
}
</style>
