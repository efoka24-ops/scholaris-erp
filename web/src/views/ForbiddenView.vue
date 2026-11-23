<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const ctx = useContextStore();

// IPES-ADMIN : motif transmis par le garde (aucun rôle dans l'application, ou un autre rôle de la personne ouvre l'écran).
const reason = computed(() => (typeof route.query.reason === 'string' ? route.query.reason : null));
const otherRole = computed(() => {
  if (reason.value !== 'other_role' || typeof route.query.role !== 'string') return null;
  const code = route.query.role;
  return ctx.roles.find((r) => r.code === code) ?? null;
});
const switching = ref(false);
const failed = ref(false);

async function useOtherRole(): Promise<void> {
  if (!otherRole.value || !ctx.activeId) return;
  switching.value = true;
  failed.value = false;
  try {
    await ctx.switchTo(ctx.activeId, otherRole.value.code);
    const from = typeof route.query.from === 'string' && route.query.from.startsWith('/') ? route.query.from : '/';
    await router.replace(from);
  } catch {
    failed.value = true;
  } finally {
    switching.value = false;
  }
}
</script>

<template>
  <div class="page">
    <h1>{{ t('forbidden.title') }}</h1>
    <template v-if="reason === 'no_app_role'">
      <p data-test="no-app-role">{{ t('forbidden.noAppRole') }}</p>
    </template>
    <template v-else-if="otherRole">
      <p data-test="other-role">{{ t('forbidden.otherRole', { role: otherRole.label }) }}</p>
      <p v-if="failed" class="field__error" role="alert">{{ t('forbidden.switchFailed') }}</p>
      <p>
        <button type="button" class="btn btn--primary" :disabled="switching" @click="useOtherRole">{{ t('forbidden.useRole', { role: otherRole.label }) }}</button>
      </p>
    </template>
    <p v-else>{{ t('forbidden.text') }}</p>
    <router-link class="btn" :class="{ 'btn--primary': !otherRole }" :to="{ name: 'home' }">{{ t('common.backHome') }}</router-link>
  </div>
</template>
