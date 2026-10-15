<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import CodeInput from '@/components/CodeInput.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError, http } from '@/api/http';
import type { TwoFactorSetup } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Activation de la double authentification — design : TwoFASetup.html : clé à saisir (ou ouvrir dans l'application), premier code, codes de secours.
const { t } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const router = useRouter();

const setup = ref<TwoFactorSetup | null>(null);
const code = ref('');
const backupCodes = ref<string[]>([]);
const loading = ref(false);
const error = ref<unknown>(null);
const attemptsLeft = ref<number | null>(null);
const saved = ref(false);

const roleLabels = computed(() =>
  (setup.value?.required_for ?? []).map((r) => (typeof r === 'string' ? r : (r.label ?? r.code ?? ''))).filter(Boolean).join(', '),
);

onMounted(async () => {
  try {
    setup.value = await http.post<TwoFactorSetup>('/auth/2fa/setup');
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      // Déjà activée : il suffit de la franchir.
      await router.replace({ name: 'twofactor' });
      return;
    }
    error.value = e;
  }
});

async function confirm(): Promise<void> {
  error.value = null;
  loading.value = true;
  try {
    const res = await http.post<{ two_factor_enabled: boolean; backup_codes: string[] }>('/auth/2fa/confirm', { code: code.value.trim() });
    backupCodes.value = res.backup_codes;
  } catch (e) {
    error.value = e;
    attemptsLeft.value = e instanceof ApiError && typeof e.problem?.attempts_left === 'number' ? e.problem.attempts_left : null;
    code.value = '';
    if (e instanceof ApiError && e.status === 401) {
      auth.clear();
      await router.replace({ name: 'login' });
    }
  } finally {
    loading.value = false;
  }
}

async function finish(): Promise<void> {
  await auth.loadMe(true);
  ctx.clear();
  await router.replace({ name: 'home' });
}

async function copyCodes(): Promise<void> {
  try {
    await navigator.clipboard.writeText(backupCodes.value.join('\n'));
  } catch {
    /* le presse-papiers peut être refusé : les codes restent affichés */
  }
}
</script>

<template>
  <main class="wrap">
    <section class="card box" aria-labelledby="sf-title">
      <p class="eyebrow">SCHOLARIS</p>
      <h1 id="sf-title">{{ t('twofa.setupTitle') }}</h1>
      <div v-if="roleLabels" class="banner" role="note"><b>{{ t('twofa.required', { roles: roleLabels }) }}</b></div>

      <template v-if="backupCodes.length === 0">
        <ErrorNotice :error="error" />
        <template v-if="setup">
          <ol class="how numbered">
            <li>{{ t('twofa.step1') }}</li>
            <li>
              {{ t('twofa.step2') }}
              <p class="secret" aria-label="Clé secrète"><code>{{ setup.secret_grouped }}</code></p>
              <a class="btn btn--quiet" :href="setup.otpauth_uri">{{ t('twofa.openApp') }}</a>
            </li>
            <li>{{ t('twofa.step3') }}</li>
          </ol>
          <form novalidate @submit.prevent="confirm">
            <div class="field">
              <span class="label" id="sf-code-label">{{ t('twofa.codeLabel') }}</span>
              <CodeInput id="sf-code" v-model="code" :invalid="attemptsLeft !== null" />
              <span v-if="attemptsLeft !== null" class="hint">{{ t('twofa.attemptsLeft', { count: attemptsLeft }) }}</span>
            </div>
            <button type="submit" class="btn btn--primary btn--large btn--block" :disabled="loading || code.trim().length !== 6">{{ t('twofa.activate') }}</button>
          </form>
        </template>
      </template>

      <template v-else>
        <div class="notice notice--success" role="status">
          <p>
            <strong>{{ t('twofa.enabled') }}</strong>
          </p>
          <p>{{ t('twofa.backupIntro') }}</p>
        </div>
        <ul class="codes" :aria-label="t('twofa.backupTitle')">
          <li v-for="c in backupCodes" :key="c"><code>{{ c }}</code></li>
        </ul>
        <div class="row">
          <button type="button" class="btn" @click="copyCodes">{{ t('twofa.copy') }}</button>
        </div>
        <label class="check"><input v-model="saved" type="checkbox" /><span>{{ t('twofa.savedCodes') }}</span></label>
        <button type="button" class="btn btn--primary btn--block" :disabled="!saved" @click="finish">{{ t('twofa.continue') }}</button>
      </template>
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
  max-width: 480px;
  border-top: 6px solid var(--accent);
}
.banner {
  background: var(--ink);
  color: #fff;
  padding: 16px 20px;
  margin-bottom: 16px;
}
.numbered li::marker {
  font-weight: 800;
}
.how {
  padding-left: 1.25rem;
}
.how li {
  margin-bottom: 0.75rem;
}
.secret {
  font-size: 20px;
  letter-spacing: 0.08em;
  margin: 0.5rem 0;
  word-break: break-all;
}
.codes {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;
  list-style: none;
  padding: 0;
}
.codes li {
  border: 1px dashed var(--border-strong);
  padding: 0.5rem;
  text-align: center;
  font-size: 16px;
}
</style>
