<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { dt } from './i18n';

// Double authentification récente exigée (signature, publication) : renvoi vers l'écran de 2FA existant puis retour ici.
const router = useRouter();
const route = useRoute();
async function go(): Promise<void> {
  await router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}
</script>

<template>
  <div class="notice notice--warning" role="alert" data-test="tfa">
    <p><strong>{{ dt('hdl.tfa.title') }}</strong></p>
    <p>{{ dt('hdl.tfa.text') }}</p>
    <button type="button" class="btn btn--ink" @click="go">{{ dt('hdl.tfa.go') }}</button>
  </div>
</template>
