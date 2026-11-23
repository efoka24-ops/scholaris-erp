<script setup lang="ts">
import { computed } from 'vue';
import { describeError, otherFieldMessages } from '@/api/errors';
import { useI18n } from '@/i18n';

const props = defineProps<{ error: unknown }>();
const { t, locale } = useI18n();
const described = computed(() => describeError(props.error, locale.value));
const others = computed(() => otherFieldMessages(described.value));
</script>

<template>
  <div v-if="error" class="notice notice--error" role="alert">
    <p><strong>{{ described.message }}</strong></p>
    <p v-if="described.hint">{{ described.hint }}</p>
    <ul v-if="others.length > 0">
      <li v-for="(message, i) in others" :key="i">{{ message }}</li>
    </ul>
    <p v-if="described.traceId" class="trace">{{ t('error.trace') }} : <code>{{ described.traceId }}</code></p>
  </div>
</template>
