<script setup lang="ts">
// Messages de l'école aux parents d'une ou plusieurs sections (messagerie ordinaire : consentement et plages horaires respectés).
import { onMounted, ref } from 'vue';
import { nursery, type OutgoingMessage, type Section } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatDateTime } from '@/lib/format';
import { useI18n } from '@/i18n';
import { useAction } from './useNur';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();
const sections = ref<Section[]>([]);
const chosen = ref<string[]>([]);
const form = ref({ subject: '', body: '' });
const history = ref<OutgoingMessage[]>([]);
const outcome = ref('');

async function load(): Promise<void> {
  history.value = (await run(() => nursery.messages())) ?? [];
}
function toggle(id: string): void {
  chosen.value = chosen.value.includes(id) ? chosen.value.filter((s) => s !== id) : [...chosen.value, id];
}
async function send(): Promise<void> {
  const result = await run(() => nursery.sendMessage({ subject: form.value.subject.trim(), body: form.value.body.trim(), ...(chosen.value.length > 0 ? { section_ids: chosen.value } : {}) }));
  if (result) {
    outcome.value = t('nur.msg.sent', { count: result.recipients, skipped: result.skipped_by_restriction });
    form.value = { subject: '', body: '' };
    await load();
  }
}
onMounted(async () => {
  sections.value = (await run(() => nursery.sections())) ?? [];
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.msg.title') }}</h1>
      <p class="lede">{{ t('nur.msg.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="outcome" class="notice" role="status" aria-live="polite">{{ outcome }}</p>
    <form class="card" @submit.prevent="send">
      <fieldset class="tags">
        <legend>{{ t('nur.msg.to') }}</legend>
        <p class="muted">{{ t('nur.msg.toHelp') }}</p>
        <label v-for="s in sections" :key="s.id" class="check"><input type="checkbox" :checked="chosen.includes(s.id)" @change="toggle(s.id)" /> {{ s.label }}</label>
      </fieldset>
      <div class="field"><label for="ms-sub">{{ t('nur.msg.subject') }}</label><input id="ms-sub" v-model="form.subject" type="text" maxlength="160" required /></div>
      <div class="field"><label for="ms-body">{{ t('nur.msg.body') }}</label><textarea id="ms-body" v-model="form.body" rows="4" maxlength="600" required></textarea></div>
      <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.msg.send') }}</button>
    </form>
    <section class="card">
      <h2>{{ t('nur.msg.history') }}</h2>
      <p v-if="history.length === 0" class="muted">{{ t('nur.msg.none') }}</p>
      <ul class="plain">
        <li v-for="m in history" :key="m.id"><strong>{{ m.subject }}</strong> · {{ formatDateTime(m.created_at, locale) }} · {{ t('nur.msg.recipients', { count: m.recipients }) }}</li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.hidden { display: none; }
.tags { border: 1px solid var(--border, #dcdcd8); border-radius: 8px; margin: 0 0 12px; padding: 8px 12px; }
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
.plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
textarea { width: 100%; }
</style>
