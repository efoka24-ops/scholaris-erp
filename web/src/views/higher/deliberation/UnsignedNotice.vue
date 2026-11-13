<script setup lang="ts">
import { dt } from './i18n';

// Refus 409 RG-DEL-3 expliqué : rien n'est publiable ni émis avant la signature du PV par le président.
</script>

<template>
  <div class="notice notice--warning" role="alert" data-test="rg-del-3">
    <p><strong>{{ dt('hdl.doc.rgDel3Title') }}</strong></p>
    <p>{{ dt('hdl.doc.rgDel3Text') }}</p>
  </div>
</template>
