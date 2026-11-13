<script setup lang="ts">
// Consentement photo : l'école enregistre le formulaire signé par le parent (référence de la pièce classée, date, signataire) et le
// révoque à sa demande. L'historique n'est jamais effacé. Sans consentement actif, aucune photo de l'enfant n'est servie aux familles.
import { ref, watch } from 'vue';
import { nursery, type Child, type PhotoConsent } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatDateTime } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { today, useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const childId = ref('');
const consent = ref<PhotoConsent | null>(null);
const form = ref({ evidence_ref: '', signed_on: today(), signed_by_name: '' });
const reason = ref('');

watch(section, async (id) => {
  childId.value = '';
  consent.value = null;
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
});
watch(childId, async (id) => {
  consent.value = id ? ((await run(() => nursery.photoConsent(id))) ?? null) : null;
});
async function record(): Promise<void> {
  const next = await run(() => nursery.recordPhotoConsent(childId.value, { evidence_ref: form.value.evidence_ref.trim(), signed_on: form.value.signed_on, signed_by_name: form.value.signed_by_name.trim() }));
  if (next) {
    consent.value = next;
    form.value = { evidence_ref: '', signed_on: today(), signed_by_name: '' };
  }
}
async function revoke(): Promise<void> {
  const next = await run(() => nursery.revokePhotoConsent(childId.value, reason.value.trim()));
  if (next) {
    consent.value = next;
    reason.value = '';
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.consent.title') }}</h1>
      <p class="lede">{{ t('nur.consent.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <SectionPicker v-model="section" id="pc-section" />
      <div class="field">
        <label for="pc-child">{{ t('nur.auth.child') }}</label>
        <select id="pc-child" v-model="childId"><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option></select>
      </div>
    </section>

    <template v-if="consent">
      <p class="card" role="status" aria-live="polite">
        <span :class="['tag', consent.active ? 'tag--ok' : 'tag--warn']">{{ consent.active ? t('nur.consent.active') : t('nur.consent.none') }}</span>
        {{ consent.active ? t('nur.consent.activeHelp') : t('nur.consent.noneHelp') }}
      </p>

      <form v-if="!consent.active && can(...P.consent)" class="card" @submit.prevent="record">
        <h2>{{ t('nur.consent.record') }}</h2>
        <div class="field"><label for="pc-ref">{{ t('nur.consent.evidence') }}</label><input id="pc-ref" v-model="form.evidence_ref" type="text" maxlength="120" required /><span class="hint">{{ t('nur.consent.evidenceHint') }}</span></div>
        <div class="field"><label for="pc-on">{{ t('nur.consent.signedOn') }}</label><input id="pc-on" v-model="form.signed_on" type="date" required /></div>
        <div class="field"><label for="pc-by">{{ t('nur.consent.signedBy') }}</label><input id="pc-by" v-model="form.signed_by_name" type="text" maxlength="160" required /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.consent.recordDo') }}</button>
      </form>

      <form v-if="consent.active && can(...P.consent)" class="card" @submit.prevent="revoke">
        <h2>{{ t('nur.consent.revoke') }}</h2>
        <div class="field"><label for="pc-reason">{{ t('nur.consent.reason') }}</label><input id="pc-reason" v-model="reason" type="text" maxlength="255" required /></div>
        <button type="submit" class="btn" :disabled="busy || !reason.trim()">{{ t('nur.consent.revokeDo') }}</button>
      </form>

      <section class="card" aria-labelledby="pc-hist">
        <h2 id="pc-hist">{{ t('nur.consent.history') }}</h2>
        <p v-if="consent.history.length === 0" class="muted">{{ t('nur.consent.noHistory') }}</p>
        <ul class="plain">
          <li v-for="h in consent.history" :key="h.id">
            <strong>{{ t(`nur.consent.channel.${h.channel}` as MessageKey) }}</strong> · {{ h.signed_by_name }} · {{ h.signed_on }}
            <span v-if="h.evidence_ref"> · {{ h.evidence_ref }}</span>
            <span :class="['tag', h.status === 'active' ? 'tag--ok' : 'tag--neutral']">{{ t(`nur.consent.status.${h.status}` as MessageKey) }}</span>
            <span v-if="h.revoked_at" class="muted"> {{ formatDateTime(h.revoked_at) }} {{ h.revoke_reason }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
