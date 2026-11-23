<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from './linkify';

// Texte brut d'un message (sauts de ligne conservés), liens http(s) cliquables ouverts sans référent.
const props = defineProps<{ body: string }>();
const segments = computed(() => linkify(props.body));
</script>

<template>
  <p class="msg-text">
    <template v-for="(s, i) in segments" :key="i">
      <a v-if="s.href" :href="s.href" target="_blank" rel="noopener noreferrer nofollow">{{ s.text }}</a>
      <template v-else>{{ s.text }}</template>
    </template>
  </p>
</template>

<style scoped>
.msg-text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  margin: 0;
}
</style>
