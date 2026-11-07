<script setup lang="ts">
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
  <div class="page">
    <h1>{{ t('forbidden.title') }}</h1>
    <p>{{ t('forbidden.text') }}</p>
    <router-link class="btn btn--primary" :to="{ name: 'home' }">{{ t('common.backHome') }}</router-link>
  </div>
</template>
