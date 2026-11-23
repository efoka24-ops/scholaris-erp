<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  createConversation,
  createSupportConversation,
  fetchContacts,
  fetchLearners,
  SUPPORT_ROLES,
  type Contact,
  type LearnerChoice,
} from '@/api/messaging';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';

// Nouvelle conversation. Les élèves et interlocuteurs proposés viennent du serveur (règles de périmètre : enseignant <-> parents
// de ses élèves, direction <-> parents) : aucun élargissement côté écran. Mode support (?support=1) : objet et message seulement.
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const ctx = useContextStore();

const support = computed(() => route.query.support === '1' && SUPPORT_ROLES.includes(ctx.activeRole ?? ''));
const learners = ref<LearnerChoice[]>([]);
const contacts = ref<Contact[]>([]);
const loaded = ref(false);
const form = reactive({ learnerId: '', contact: '', subject: '', body: '' });
const saving = ref(false);
const error = ref<unknown>(null);

const chosen = computed(() => contacts.value.find((c) => `${c.kind}:${c.person_id}` === form.contact) ?? null);
const ready = computed(() => form.subject.trim() !== '' && form.body.trim() !== '' && (support.value || chosen.value !== null));

async function loadLearners(): Promise<void> {
  if (support.value) return;
  error.value = null;
  try {
    learners.value = await fetchLearners();
    if (learners.value.length === 1) form.learnerId = learners.value[0]!.learner_id;
  } catch (e) {
    error.value = e;
  } finally {
    loaded.value = true;
  }
}

watch(
  () => form.learnerId,
  async (id) => {
    contacts.value = [];
    form.contact = '';
    if (!id) return;
    try {
      contacts.value = await fetchContacts(id);
      if (contacts.value.length === 1) form.contact = `${contacts.value[0]!.kind}:${contacts.value[0]!.person_id}`;
    } catch (e) {
      error.value = e;
    }
  },
);

async function submit(): Promise<void> {
  if (!ready.value || saving.value) return;
  saving.value = true;
  error.value = null;
  try {
    const created = support.value
      ? await createSupportConversation(form.subject.trim(), form.body.trim())
      : await createConversation({
          subject: form.subject.trim(),
          kind: chosen.value!.kind,
          learner_id: form.learnerId,
          participant_ids: [chosen.value!.person_id],
          body: form.body.trim(),
        });
    await router.replace({ name: 'inbox-thread', params: { id: created.id } });
  } catch (e) {
    error.value = e;
  } finally {
    saving.value = false;
  }
}

onMounted(loadLearners);
</script>

<template>
  <div class="page">
    <router-link :to="{ name: 'inbox' }" class="back">{{ t('msg.thread.back') }}</router-link>
    <header class="page-head">
      <p class="eyebrow">{{ t('msg.eyebrow') }}</p>
      <h1>{{ support ? t('msg.new.supportTitle') : t('msg.new.title') }}</h1>
      <p class="lede">{{ support ? t('msg.new.supportLede') : t('msg.new.lede') }}</p>
    </header>

    <ErrorNotice :error="error" />
    <form class="card stack" novalidate @submit.prevent="submit">
      <template v-if="!support">
        <p v-if="loaded && learners.length === 0" class="muted">{{ t('msg.new.noLearner') }}</p>
        <div class="field">
          <label for="nc-learner">{{ t('msg.new.learner') }}</label>
          <select id="nc-learner" v-model="form.learnerId" required>
            <option value="" disabled>{{ t('msg.new.chooseLearner') }}</option>
            <option v-for="l in learners" :key="l.learner_id" :value="l.learner_id">{{ l.name }}</option>
          </select>
        </div>
        <div v-if="form.learnerId" class="field">
          <label for="nc-contact">{{ t('msg.new.contact') }}</label>
          <p v-if="contacts.length === 0" class="muted">{{ t('msg.new.noContact') }}</p>
          <select v-else id="nc-contact" v-model="form.contact" required>
            <option value="" disabled>{{ t('msg.new.contact') }}</option>
            <option v-for="c in contacts" :key="`${c.kind}:${c.person_id}`" :value="`${c.kind}:${c.person_id}`">
              {{ c.name }} · {{ t(`msg.kind.${c.kind}` as 'msg.kind.teacher_parent') }}
            </option>
          </select>
        </div>
      </template>
      <div class="field">
        <label for="nc-subject">{{ t('msg.new.subject') }}</label>
        <input id="nc-subject" v-model="form.subject" type="text" maxlength="160" required />
      </div>
      <div class="field">
        <label for="nc-body">{{ t('msg.new.body') }}</label>
        <textarea id="nc-body" v-model="form.body" rows="5" maxlength="5000" required />
      </div>
      <button type="submit" class="btn" :disabled="!ready || saving">{{ saving ? t('msg.thread.sending') : t('msg.new.create') }}</button>
    </form>
  </div>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 8px;
}
.stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-width: 640px;
}
.stack textarea,
.stack input,
.stack select {
  width: 100%;
}
.stack .btn {
  align-self: flex-start;
}
</style>
