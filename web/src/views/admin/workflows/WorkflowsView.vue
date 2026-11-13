<script setup lang="ts">
import AdminSubnav from '../AdminSubnav.vue';
import { useI18n } from '@/i18n';
import WorkflowsPanel from './WorkflowsPanel.vue';

// Administration > Circuits de validation : circuits de l'établissement actif (permission workflows.manage).
const { t } = useI18n();
</script>

<template>
  <div class="page">
    <AdminSubnav />
    <h1>{{ t('wf.title') }}</h1>
    <WorkflowsPanel :scope="{ kind: 'establishment' }" />
  </div>
</template>
