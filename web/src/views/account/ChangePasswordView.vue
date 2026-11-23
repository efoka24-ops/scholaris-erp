<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { changePassword } from '@/api/account';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Changer mon mot de passe. Écran FORCÉ après la première connexion avec un mot de passe provisoire (le serveur n'ouvre rien d'autre
// tant que le mot de passe n'est pas remplacé), utilisable aussi à tout moment depuis « Sécurité ».
const { t } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const router = useRouter();
const route = useRoute();

const forced = computed(() => auth.passwordChangeRequired);
const current = ref('');
const password = ref('');
const confirmation = ref('');
const touched = ref(false);
const loading = ref(false);
const error = ref<unknown>(null);
const attemptsLeft = ref<number | null>(null);

const currentMissing = computed(() => touched.value && current.value === '');
const tooShort = computed(() => touched.value && password.value.length < 10);
const same = computed(() => touched.value && password.value !== '' && password.value === current.value);
const mismatch = computed(() => confirmation.value !== '' && confirmation.value !== password.value);
const canSubmit = computed(() => current.value !== '' && password.value.length >= 10 && password.value !== current.value && confirmation.value === password.value);

async function submit(): Promise<void> {
  touched.value = true;
  error.value = null;
  attemptsLeft.value = null;
  if (!canSubmit.value) return;
  loading.value = true;
  try {
    await changePassword({ current_password: current.value, new_password: password.value, new_password_confirmation: confirmation.value });
    current.value = '';
    password.value = '';
    confirmation.value = '';
    // Le profil change d'état (plus de mot de passe provisoire) : le routeur laisse passer, la 2FA éventuelle suit.
    await auth.loadMe(true);
    ctx.clear();
    const back = route.query.redirect;
    if (auth.twoFactorSetupNeeded) await router.replace({ name: 'twofactor-setup' });
    else if (auth.twoFactorPending) await router.replace({ name: 'twofactor' });
    else await router.replace(typeof back === 'string' && back.startsWith('/') && !back.startsWith('//') ? back : { name: 'home' });
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && typeof e.problem?.attempts_left === 'number') attemptsLeft.value = e.problem.attempts_left;
    current.value = '';
  } finally {
    loading.value = false;
  }
}

async function logout(): Promise<void> {
  await auth.logout();
  ctx.clear();
  await router.replace({ name: 'login' });
}
</script>

<template>
  <main class="wrap">
    <section class="card box" aria-labelledby="cp-title">
      <p class="eyebrow">{{ t('acc.pw.eyebrow') }}</p>
      <h1 id="cp-title" class="t28">{{ forced ? t('acc.pw.forcedTitle') : t('acc.pw.title') }}</h1>
      <p v-if="forced" class="notice notice--warning" role="status">{{ t('acc.pw.forcedText') }}</p>

      <form novalidate @submit.prevent="submit">
        <div class="field">
          <label for="cp-current">{{ forced ? t('acc.pw.currentForced') : t('acc.pw.current') }}</label>
          <input id="cp-current" v-model="current" type="password" autocomplete="current-password" required :aria-invalid="currentMissing" />
          <span v-if="currentMissing" class="error">{{ t('acc.pw.currentRequired') }}</span>
        </div>
        <div class="field">
          <label for="cp-new">{{ t('acc.pw.new') }}</label>
          <input id="cp-new" v-model="password" type="password" autocomplete="new-password" minlength="10" required :aria-invalid="tooShort || same" aria-describedby="cp-rules" />
          <span id="cp-rules" class="hint">{{ t('acc.pw.rules') }}</span>
          <span v-if="tooShort" class="error">{{ t('acc.pw.newRequired') }}</span>
          <span v-if="same" class="error">{{ t('acc.pw.same') }}</span>
        </div>
        <div class="field">
          <label for="cp-confirm">{{ t('acc.pw.confirm') }}</label>
          <input id="cp-confirm" v-model="confirmation" type="password" autocomplete="new-password" required :aria-invalid="mismatch" />
          <span v-if="mismatch" class="error">{{ t('acc.pw.mismatch') }}</span>
        </div>
        <ErrorNotice :error="error" />
        <p v-if="attemptsLeft !== null" class="bad" role="status">{{ t('acc.pw.attempts', { count: attemptsLeft }) }}</p>
        <button type="submit" class="btn btn--primary btn--block" :disabled="loading">{{ t('acc.pw.save') }}</button>
      </form>

      <p class="back">
        <button v-if="forced" type="button" class="btn btn--quiet" @click="logout">{{ t('acc.pw.logout') }}</button>
        <router-link v-else :to="{ name: 'security' }">{{ t('acc.pw.back') }}</router-link>
      </p>
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
.t28 {
  font-size: 28px;
}
.bad {
  font-size: 13px;
  font-weight: 700;
  color: var(--warning);
}
.back {
  margin: 1rem 0 0;
}
</style>
