<script setup lang="ts">
defineProps<{ tone?: 'success' | 'warning' | 'ink' | 'neutral' }>();
</script>

<template>
  <span class="tag" :class="tone ? `tag--${tone}` : ''"><slot /></span>
</template>
