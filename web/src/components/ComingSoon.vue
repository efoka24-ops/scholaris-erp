<script setup lang="ts">
import { useI18n } from '@/i18n';

// Fonction prévue par la maquette mais sans interface côté serveur : annoncée honnêtement, jamais simulée.
defineProps<{ title: string; text: string }>();
const { t } = useI18n();
</script>

<template>
  <section class="soon card" role="note">
    <span class="tag">{{ t('soon.tag') }}</span>
    <h2>{{ title }}</h2>
    <p class="muted">{{ text }}</p>
  </section>
</template>

<style scoped>
.soon {
  border-style: dashed;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.soon h2 {
  margin: 0;
  font-size: 17px;
}
.soon p {
  margin: 0;
}
</style>
