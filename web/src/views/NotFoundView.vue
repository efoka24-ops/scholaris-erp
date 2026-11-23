<script setup lang="ts">
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
  <main class="page">
    <h1>{{ t('notFound.title') }}</h1>
    <p>{{ t('notFound.text') }}</p>
    <router-link class="btn btn--primary" :to="{ name: 'home' }">{{ t('common.backHome') }}</router-link>
  </main>
</template>
