<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PublicLayout from './PublicLayout.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { TRACKING_TOKEN, sendComplement, trackAccessRequest, type AccessTracking } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { requestStatusKey, requestTone } from './display';

// Suivi d'une demande d'accès par son jeton, et réponse à une demande de complément — design : Verification.html / Fondations.html.
const props = defineProps<{ token?: string }>();
const { t, locale } = useI18n();
const router = useRouter();

const request = ref<AccessTracking | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const notFound = ref(false);
const complement = ref('');
const complementError = ref<string | null>(null);
const sending = ref(false);
const sendError = ref<unknown>(null);
const sent = ref(false);
const tokenInput = ref(props.token ?? '');
const tokenInvalid = ref(false);

const validToken = computed(() => !!props.token && TRACKING_TOKEN.test(props.token));

async function load(): Promise<void> {
  request.value = null;
  notFound.value = false;
  error.value = null;
  if (!props.token) return;
  if (!validToken.value) {
    tokenInvalid.value = true;
    return;
  }
  loading.value = true;
  try {
    request.value = await trackAccessRequest(props.token);
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true;
    else error.value = e;
  } finally {
    loading.value = false;
  }
}

async function lookup(): Promise<void> {
  const token = tokenInput.value.trim().toLowerCase();
  tokenInvalid.value = !TRACKING_TOKEN.test(token);
  if (tokenInvalid.value) return;
  await router.push({ name: 'access-tracking', params: { token } });
}

async function submitComplement(): Promise<void> {
  sendError.value = null;
  const text = complement.value.trim();
  complementError.value = text === '' ? t('pub.track.complementRequired') : text.length > 2000 ? t('pub.track.complementTooLong') : null;
  if (complementError.value || !props.token) return;
  sending.value = true;
  try {
    request.value = await sendComplement(props.token, text);
    complement.value = '';
    sent.value = true;
  } catch (e) {
    sendError.value = e;
    if (e instanceof ApiError && e.status === 422) complementError.value = e.fieldErrors.message?.[0] ?? null;
  } finally {
    sending.value = false;
  }
}

watch(() => props.token, () => {
  tokenInput.value = props.token ?? '';
  sent.value = false;
  void load();
});
onMounted(load);
</script>

<template>
  <PublicLayout>
    <div class="page narrow">
      <header class="page-head">
        <p class="eyebrow">{{ t('pub.track.eyebrow') }}</p>
        <h1>{{ t('pub.track.title') }}</h1>
      </header>

      <form v-if="!request" class="card row lookup" novalidate @submit.prevent="lookup">
        <div class="field grow">
          <label for="tr-token">{{ t('pub.track.tokenField') }}</label>
          <input id="tr-token" v-model="tokenInput" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="64" :aria-invalid="tokenInvalid" :aria-describedby="tokenInvalid ? 'tr-token-err' : undefined" />
          <span v-if="tokenInvalid" id="tr-token-err" class="error">{{ t('pub.track.tokenInvalid') }}</span>
        </div>
        <button type="submit" class="btn btn--primary">{{ t('pub.track.go') }}</button>
      </form>

      <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-if="notFound" class="notice notice--warning" role="alert">{{ t('pub.track.notFound') }}</p>
      <ErrorNotice :error="error" />

      <article v-if="request" class="card card--strong" aria-labelledby="tr-title">
        <div class="row row--between">
          <p class="eyebrow">{{ t('pub.track.reference', { ref: request.reference }) }}</p>
          <span class="tag" :class="requestTone(request.status) ? `tag--${requestTone(request.status)}` : ''">{{ t(requestStatusKey(request.status)) }}</span>
        </div>
        <h2 id="tr-title" class="name">{{ request.institution_name }}</h2>
        <dl class="facts">
          <div><dt>{{ t('pub.track.app') }}</dt><dd>{{ request.app_label }}</dd></div>
          <div><dt>{{ t('pub.track.submitted') }}</dt><dd>{{ formatDateTime(request.submitted_at, locale) }}</dd></div>
          <div><dt>{{ t('pub.track.updated') }}</dt><dd>{{ formatDateTime(request.updated_at, locale) }}</dd></div>
        </dl>

        <p v-if="sent" class="notice notice--success" role="status">{{ t('pub.track.sent') }}</p>
        <p v-if="request.status === 'pending'" class="state-text">{{ t('pub.track.pendingText') }}</p>

        <template v-if="request.status === 'accepted'">
          <p class="state-text">{{ t('pub.track.acceptedText') }}</p>
          <router-link class="btn btn--primary" :to="{ name: 'activation' }">{{ t('pub.track.activate') }} →</router-link>
        </template>

        <template v-if="request.status === 'rejected'">
          <p class="state-text">{{ t('pub.track.rejectedText') }}</p>
          <p v-if="request.decision_reason"><strong>{{ t('pub.track.reason') }} :</strong> {{ request.decision_reason }}</p>
        </template>

        <section v-if="request.status === 'info_requested'" class="info" aria-labelledby="tr-info-title">
          <h3 id="tr-info-title">{{ t('pub.track.infoTitle') }}</h3>
          <blockquote class="quote">{{ request.info_request }}</blockquote>
          <form novalidate @submit.prevent="submitComplement">
            <div class="field">
              <label for="tr-complement">{{ t('pub.track.complement') }}</label>
              <textarea id="tr-complement" v-model="complement" rows="6" maxlength="2000" :aria-invalid="!!complementError" :aria-describedby="complementError ? 'tr-complement-hint tr-complement-err' : 'tr-complement-hint'" />
              <span id="tr-complement-hint" class="hint">{{ t('pub.track.complementHint') }} ({{ complement.length }} / 2000)</span>
              <span v-if="complementError" id="tr-complement-err" class="error">{{ complementError }}</span>
            </div>
            <ErrorNotice :error="sendError" />
            <button type="submit" class="btn btn--primary" :disabled="sending">{{ sending ? t('common.loading') : t('pub.track.send') }}</button>
          </form>
        </section>
      </article>
    </div>
  </PublicLayout>
</template>

<style scoped>
.narrow {
  max-width: 880px;
}
.lookup {
  align-items: flex-end;
  margin-bottom: 16px;
}
.lookup .field {
  margin-bottom: 0;
}
.grow {
  flex: 1 1 260px;
}
.name {
  font-size: 24px;
  margin: 8px 0 16px;
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px 24px;
  margin: 0 0 16px;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.facts dd {
  margin: 2px 0 0;
  font-weight: 700;
}
.state-text {
  font-size: 16px;
}
.info {
  border-top: 1px solid var(--border);
  padding-top: 16px;
  margin-top: 8px;
}
.quote {
  margin: 0 0 16px;
  padding: 12px 16px;
  border-left: 6px solid var(--accent);
  background: var(--panel);
  white-space: pre-line;
}
</style>
