<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import CodeInput from '@/components/CodeInput.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError, http } from '@/api/http';
import type { CodeChallenge } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Double authentification — design : TwoFA.html. Après le mot de passe : code TOTP, SMS ou code de secours.
const { t } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const router = useRouter();
const route = useRoute();

type Method = 'totp' | 'sms' | 'recovery';
const method = ref<Method>('totp');
const code = ref('');
const trust = ref(false);
const loading = ref(false);
const error = ref<unknown>(null);
const attemptsLeft = ref<number | null>(null);
const smsInfo = ref<CodeChallenge | null>(null);
const notMeDone = ref(false);

const roleLine = computed(() => {
  const code = auth.me?.context.role ?? null;
  const label = ctx.roles.find((r) => r.code === code)?.label ?? code ?? '';
  return [t('twofa.roleLine', { role: label }), ctx.activeTile?.name].filter(Boolean).join(' · ');
});

async function sendSms(): Promise<void> {
  error.value = null;
  loading.value = true;
  try {
    smsInfo.value = await http.post<CodeChallenge>('/auth/2fa/sms');
    method.value = 'sms';
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function submit(): Promise<void> {
  error.value = null;
  loading.value = true;
  try {
    if (method.value === 'recovery') {
      await http.post('/auth/2fa/recovery', { code: code.value.trim(), trust_device: trust.value });
    } else {
      await http.post('/auth/2fa/verify', { code: code.value.trim(), method: method.value, trust_device: trust.value });
    }
    await auth.loadMe(true);
    ctx.clear();
    // Revalidation demandée par une action sensible (plateforme) : retour à la page d'origine, chemin interne seulement.
    const back = route.query.redirect;
    await router.replace(typeof back === 'string' && back.startsWith('/') && !back.startsWith('//') ? back : { name: 'home' });
  } catch (e) {
    error.value = e;
    attemptsLeft.value = e instanceof ApiError && typeof e.problem?.attempts_left === 'number' ? e.problem.attempts_left : null;
    code.value = '';
    if (e instanceof ApiError && e.status === 401) {
      // Trois échecs : la session est fermée par le serveur.
      auth.clear();
      await router.replace({ name: 'login' });
    }
  } finally {
    loading.value = false;
  }
}

async function notMe(): Promise<void> {
  if (!window.confirm(t('twofa.notMeConfirm'))) return;
  loading.value = true;
  try {
    await http.post('/auth/not-me');
    notMeDone.value = true;
    auth.clear();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="mobile">
    <header class="top">
      <div class="brand"><span class="logo-sq logo-sq--s">S</span><span class="brand__name">SCHOLARIS</span></div>
      <div v-if="auth.lastLogin?.new_device" class="device" role="status">
        <span class="device__icon" aria-hidden="true">▮</span>
        <div>
          <b>{{ t('twofa.newDevice') }}</b><br />
          <span class="muted-dark">{{ t('twofa.newDeviceText') }}</span>
        </div>
      </div>
    </header>

    <section class="main" aria-labelledby="tf-title">
      <div v-if="notMeDone" class="notice notice--warning" role="status">
        <p>{{ t('twofa.notMeDone') }}</p>
        <router-link class="btn" :to="{ name: 'reset' }">{{ t('login.forgot') }}</router-link>
      </div>

      <form v-else class="form" novalidate @submit.prevent="submit">
        <div>
          <p class="eyebrow">{{ t('twofa.title') }}</p>
          <h1 id="tf-title" class="title">{{ method === 'recovery' ? t('twofa.recoveryTitle') : method === 'sms' ? t('twofa.smsTitle') : t('twofa.appTitle') }}</h1>
          <p class="sub">{{ roleLine }}</p>
        </div>

        <p v-if="method === 'sms'" class="sub">{{ t('twofa.smsSent', { to: smsInfo?.masked_to ?? '' }) }}</p>
        <p v-else-if="method === 'recovery'" class="sub">{{ t('twofa.recoveryIntro') }}</p>

        <div class="field nomargin">
          <label class="sr-only" for="tf-code">{{ method === 'recovery' ? t('twofa.recoveryLabel') : t('twofa.codeLabel') }}</label>
          <input v-if="method === 'recovery'" id="tf-code" v-model="code" type="text" autocomplete="one-time-code" maxlength="12" :aria-invalid="attemptsLeft !== null" required />
          <CodeInput v-else id="tf-code" v-model="code" :invalid="attemptsLeft !== null" @complete="submit" />
        </div>
        <p v-if="attemptsLeft !== null" class="bad">{{ t('twofa.attemptsLeft', { count: attemptsLeft }) }} {{ t('twofa.checkClock') }}</p>
        <label class="check">
          <input v-model="trust" type="checkbox" />
          <span>{{ t('twofa.trust') }}</span>
        </label>

        <ErrorNotice :error="error" />

        <div class="other">
          <p class="eyebrow">{{ t('twofa.other') }}</p>
          <button v-if="method !== 'sms'" type="button" class="opt" :disabled="loading" @click="sendSms">{{ t('twofa.bySms') }}<span>›</span></button>
          <button v-if="method !== 'recovery'" type="button" class="opt" @click="method = 'recovery'">{{ t('twofa.useRecovery') }}<span>›</span></button>
          <button v-if="method !== 'totp'" type="button" class="opt" @click="method = 'totp'">{{ t('twofa.useApp') }}<span>›</span></button>
        </div>
        <button type="button" class="notme" @click="notMe">{{ t('twofa.notMe') }}</button>

        <button type="submit" class="btn btn--primary btn--large btn--block" :disabled="loading || code.trim() === ''">{{ t('twofa.verify') }}</button>
      </form>
    </section>
  </main>
</template>

<style scoped>
.mobile {
  max-width: 480px;
  margin: 0 auto;
  min-height: 100vh;
  background: var(--surface);
  display: flex;
  flex-direction: column;
}
.top {
  background: var(--ink);
  color: #fff;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.brand {
  display: flex;
  align-items: center;
  gap: 8px;
}
.logo-sq--s {
  width: 26px;
  height: 26px;
  font-size: 14px;
}
.brand__name {
  font-weight: 800;
  letter-spacing: 0.04em;
}
.device {
  border: 1px solid var(--dark-control-border);
  padding: 14px;
  display: flex;
  gap: 12px;
  font-size: 13px;
  line-height: 1.45;
}
.device__icon {
  color: var(--accent);
}
.muted-dark {
  color: var(--on-dark-muted);
}
.main {
  flex: 1;
  padding: 24px 20px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.title {
  margin: 8px 0 6px;
  font-size: 26px;
  line-height: 1.15;
}
.sub {
  margin: 0;
  font-size: 14px;
  color: var(--text-2);
}
.nomargin {
  margin: 0;
}
.bad {
  margin: -8px 0 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--warning);
}
.other {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.opt {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 56px;
  padding: 0 14px;
  border: 1px solid var(--border);
  background: var(--surface);
  font: 600 15px var(--font);
  text-align: left;
  cursor: pointer;
}
.opt span {
  margin-left: auto;
}
.notme {
  background: none;
  border: 0;
  padding: 0;
  min-height: var(--tap);
  text-align: left;
  font: 700 14px var(--font);
  color: var(--warning);
  cursor: pointer;
}
</style>
