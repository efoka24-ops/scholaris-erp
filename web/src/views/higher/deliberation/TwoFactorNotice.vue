<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { useConfirmation } from '@/views/account/useConfirmation';
import { dt } from './i18n';

// Confirmation récente exigée (signature, publication) : renvoi vers l'écran de confirmation (code de double authentification, ou
// mot de passe pour qui n'a pas de 2FA) puis retour ici. La route `twofactor` choisit l'écran adapté.
const router = useRouter();
const route = useRoute();
const confirmation = useConfirmation();
async function go(): Promise<void> {
  await router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}
</script>

<template>
  <div class="notice notice--warning" role="alert" data-test="tfa">
    <p><strong>{{ confirmation.title(dt('hdl.tfa.title')) }}</strong></p>
    <p>{{ confirmation.text(dt('hdl.tfa.text')) }}</p>
    <button type="button" class="btn btn--ink" @click="go">{{ confirmation.go(dt('hdl.tfa.go')) }}</button>
  </div>
</template>
