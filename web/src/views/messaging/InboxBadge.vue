<script setup lang="ts">
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { useUnreadStore } from './unread';

// Lien vers la messagerie, à côté de la cloche : nombre de messages non lus, relu à chaque changement d'écran ou de contexte.
const { t } = useI18n();
const ctx = useContextStore();
const unread = useUnreadStore();
const route = useRoute();
const visible = computed(() => !!ctx.establishmentId && unread.eligible());

watch([() => route.fullPath, () => ctx.establishmentId, () => ctx.activeRole], () => void unread.refresh(), { immediate: true });
</script>

<template>
  <router-link v-if="visible" :to="{ name: 'inbox' }" class="sel bell" :aria-label="t('msg.inbox.badge', { count: unread.count })">
    <span aria-hidden="true">✉</span>
    <span v-if="unread.count > 0" class="count">{{ unread.count > 99 ? '99+' : unread.count }}</span>
  </router-link>
</template>
