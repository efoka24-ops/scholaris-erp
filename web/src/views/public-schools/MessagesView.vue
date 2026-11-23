<script setup lang="ts">
// Messages aux parents : SMS à une classe (enseignant ou directeur), à un niveau ou à toute l'école (directeur). Le même envoi
// rejoué (client_uuid) ne part pas deux fois ; le journal des envois de l'école est affiché en dessous.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type Audience, type ClassRow, type MessageBatch } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { P } from './access';
import { currentYear, useAction, usePermissions } from './usePs';

const { t, tp, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const classes = ref<ClassRow[]>([]);
const journal = ref<MessageBatch[]>([]);
const sentNow = ref<MessageBatch | null>(null);
const uuid = ref(crypto.randomUUID());
const form = reactive({ audience: 'class' as Audience, target: '', template: 'pub.class_notice', details: '', date: '', time: '', place: '' });
const director = computed(() => can(...P.pupilsWrite));
const valid = computed(() => (form.audience === 'school' || form.target.trim() !== '') && form.details.trim() !== '');
const TEMPLATES = ['pub.class_notice', 'pub.not14.parent'] as const;
const templateLabel = (key: string): string => (key === 'pub.not14.parent' ? t('ps.messages.template.essential') : key === 'pub.class_notice' ? t('ps.messages.template.notice') : key);
const audienceLabel = (a: Audience): string => t(a === 'class' ? 'ps.messages.audience.class' : a === 'level' ? 'ps.messages.audience.level' : 'ps.messages.audience.school');

async function load(): Promise<void> {
  await run(async () => {
    const year = await currentYear();
    if (year) classes.value = await publicSchools.classes(year.id);
    journal.value = await publicSchools.messages();
  });
}

function onAudience(): void {
  form.target = '';
}

async function send(): Promise<void> {
  const variables: Record<string, string> = { details: form.details.trim() };
  if (form.date) variables.date = form.date;
  if (form.time) variables.time = form.time;
  if (form.place.trim()) variables.place = form.place.trim();
  const batch = await run(() =>
    publicSchools.sendMessage({
      audience: form.audience,
      ...(form.audience === 'school' ? {} : { target: form.target.trim() }),
      template_key: form.template,
      variables,
      client_uuid: uuid.value,
    }),
  );
  if (batch) {
    sentNow.value = batch;
    uuid.value = crypto.randomUUID();
    form.details = '';
    journal.value = [batch, ...journal.value.filter((b) => b.id !== batch.id)];
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.messages.title') }}</h1>
      <p class="lede">{{ t('ps.messages.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <form class="card form" @submit.prevent="send">
      <div class="field">
        <label for="ms-audience">{{ t('ps.messages.audience') }}</label>
        <select id="ms-audience" v-model="form.audience" @change="onAudience">
          <option value="class">{{ audienceLabel('class') }}</option>
          <option v-if="director" value="level">{{ audienceLabel('level') }}</option>
          <option v-if="director" value="school">{{ audienceLabel('school') }}</option>
        </select>
      </div>
      <div v-if="form.audience === 'class'" class="field">
        <label for="ms-class">{{ t('ps.messages.class') }}</label>
        <select id="ms-class" v-model="form.target" required>
          <option value="" disabled>{{ t('ps.classes.pick') }}</option>
          <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <div v-else-if="form.audience === 'level'" class="field">
        <label for="ms-level">{{ t('ps.messages.level') }}</label>
        <input id="ms-level" v-model="form.target" type="text" required />
      </div>
      <div class="field">
        <label for="ms-template">{{ t('ps.messages.template') }}</label>
        <select id="ms-template" v-model="form.template">
          <option v-for="k in TEMPLATES" :key="k" :value="k">{{ templateLabel(k) }}</option>
        </select>
      </div>
      <div class="field">
        <label for="ms-details">{{ t('ps.messages.details') }}</label>
        <textarea id="ms-details" v-model="form.details" maxlength="300" required></textarea>
      </div>
      <div class="field"><label for="ms-date">{{ t('ps.messages.date') }}</label><input id="ms-date" v-model="form.date" type="date" /></div>
      <div class="field"><label for="ms-time">{{ t('ps.messages.time') }}</label><input id="ms-time" v-model="form.time" type="time" /></div>
      <div class="field"><label for="ms-place">{{ t('ps.messages.place') }}</label><input id="ms-place" v-model="form.place" type="text" maxlength="120" /></div>
      <button type="submit" class="btn btn--primary" :disabled="busy || !valid">{{ t('ps.messages.send') }}</button>
      <p v-if="sentNow" class="notice notice--success" role="status">
        {{ sentNow.replayed ? t('ps.messages.replayed') : tp('ps.messages.sent', sentNow.recipients_count) }}
      </p>
    </form>

    <section class="card" aria-labelledby="ms-journal">
      <h2 id="ms-journal">{{ t('ps.messages.journal') }}</h2>
      <p v-if="journal.length === 0" class="muted">{{ t('ps.messages.none') }}</p>
      <ul v-else class="log">
        <li v-for="b in journal" :key="b.id">
          <strong>{{ audienceLabel(b.audience) }}</strong> · {{ templateLabel(b.template_key) }} · {{ tp('ps.messages.recipients', b.recipients_count) }}
          <span class="muted"> · {{ formatDateTime(b.created_at, locale) }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.form { display: grid; gap: 4px; max-width: 36rem; }
.log { margin: 0; padding-left: 1.2rem; display: grid; gap: 8px; }
</style>
