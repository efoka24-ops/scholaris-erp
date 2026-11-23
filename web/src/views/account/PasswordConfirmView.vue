<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { reconfirmPassword } from '@/api/account';
import { useI18n } from '@/i18n';

// Confirmation par mot de passe : tient lieu de « Saisir mon code » pour les personnes qui n'ont pas de double authentification.
// Valable 10 minutes ; puis retour à l'écran d'où l'action sensible a été demandée (`redirect`, chemin interne seulement).
const { t } = useI18n();
const router = useRouter();
const route = useRoute();

const password = ref('');
const loading = ref(false);
const error = ref<unknown>(null);
const attemptsLeft = ref<number | null>(null);

function target(): string | { name: string } {
  const back = route.query.redirect;
  return typeof back === 'string' && back.startsWith('/') && !back.startsWith('//') ? back : { name: 'home' };
}

async function submit(): Promise<void> {
  error.value = null;
  attemptsLeft.value = null;
  if (password.value === '') return;
  loading.value = true;
  try {
    await reconfirmPassword(password.value);
    password.value = '';
    await router.replace(target());
  } catch (e) {
    error.value = e;
    if (e instanceof ApiError && typeof e.problem?.attempts_left === 'number') attemptsLeft.value = e.problem.attempts_left;
    password.value = '';
  } finally {
    loading.value = false;
  }
}

async function cancel(): Promise<void> {
  await router.replace(target());
}
</script>

<template>
  <main class="wrap">
    <section class="card box" aria-labelledby="pc-title">
      <p class="eyebrow">{{ t('acc.confirm.eyebrow') }}</p>
      <h1 id="pc-title" class="t28">{{ t('acc.confirm.title') }}</h1>
      <p class="muted">{{ t('acc.confirm.text') }}</p>
      <form novalidate @submit.prevent="submit">
        <div class="field">
          <label for="pc-password">{{ t('acc.confirm.password') }}</label>
          <input id="pc-password" v-model="password" type="password" autocomplete="current-password" required :aria-invalid="attemptsLeft !== null" />
        </div>
        <ErrorNotice :error="error" />
        <p v-if="attemptsLeft !== null" class="bad" role="status">{{ t('acc.confirm.attempts', { count: attemptsLeft }) }}</p>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="loading || password === ''" data-test="reconfirm">{{ t('acc.confirm.submit') }}</button>
          <button type="button" class="btn btn--quiet" @click="cancel">{{ t('acc.confirm.cancel') }}</button>
        </div>
      </form>
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
</style>
