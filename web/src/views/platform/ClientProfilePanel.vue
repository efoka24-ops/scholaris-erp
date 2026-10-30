<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, normalizePhone, type Tenant } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Fiche d'un client : modifier (nom, contact, ville, région) ; suspendre, réactiver, archiver (motif, jamais de suppression).
const props = defineProps<{ client: Tenant }>();
const emit = defineEmits<{ changed: [] }>();
const { t, locale } = useI18n();
const action = useSensitiveAction();

type Kind = 'suspend' | 'reactivate' | 'archive';
const editing = ref(false);
const deciding = ref<Kind | null>(null);
const reason = ref('');
const done = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const form = reactive({ name: '', contact_name: '', contact_email: '', contact_phone: '', city: '', region: '' });

const state = computed(() => props.client.state ?? 'active');
const stateLabel = (s: string): MessageKey => `pa.cli.state.${s}` as MessageKey;
const kinds = computed<Kind[]>(() => (state.value === 'active' ? ['suspend'] : state.value === 'suspended' ? ['reactivate', 'archive'] : []));
const kindLabel = (k: Kind): string => t(k === 'suspend' ? 'pa.cli.suspend' : k === 'reactivate' ? 'pa.cli.reactivate' : 'pa.cli.archive');

function reset(): void {
  const c = props.client;
  Object.assign(form, { name: c.name, contact_name: c.contact?.name ?? '', contact_email: c.contact?.email ?? '', contact_phone: c.contact?.phone ?? '', city: c.city ?? '', region: c.region ?? '' });
  errors.value = {};
}
watch(() => props.client, reset, { immediate: true });

async function save(): Promise<void> {
  const e: Record<string, string> = {};
  if (form.name.trim().length < 3) e.name = t('pa.cli.nameInvalid');
  const phone = form.contact_phone.trim() === '' ? null : normalizePhone(form.contact_phone);
  if (form.contact_phone.trim() !== '' && phone === null) e.contact_phone = t('pa.phoneInvalid');
  errors.value = e;
  if (Object.keys(e).length > 0) return;
  const c = props.client;
  const next: Record<string, string | null> = {
    name: form.name.trim(),
    contact_name: form.contact_name.trim() || null,
    contact_email: form.contact_email.trim() || null,
    contact_phone: phone,
    city: form.city.trim() || null,
    region: form.region.trim() || null,
  };
  const before: Record<string, string | null> = { name: c.name, contact_name: c.contact?.name ?? null, contact_email: c.contact?.email ?? null, contact_phone: c.contact?.phone ?? null, city: c.city ?? null, region: c.region ?? null };
  const changes = Object.fromEntries(Object.entries(next).filter(([k, v]) => v !== before[k] && !(k === 'contact_name' && v === null)));
  if (Object.keys(changes).length === 0) {
    editing.value = false;
    return;
  }
  const res = await action.run<Tenant>(actions.updateClient(c.id, changes), t('pa.cli.edit'));
  if (!res) return;
  editing.value = false;
  done.value = t('pa.done');
  emit('changed');
}

async function decide(): Promise<void> {
  if (!deciding.value) return;
  const text = reason.value.trim();
  if (deciding.value !== 'reactivate' && text === '') {
    errors.value = { reason: t('plat.cli.reasonRequired') };
    return;
  }
  const res = await action.run<Tenant>(actions.clientState(props.client.id, deciding.value, text || null), `${kindLabel(deciding.value)} · ${props.client.name}`);
  if (!res) return;
  deciding.value = null;
  reason.value = '';
  done.value = t('pa.done');
  emit('changed');
}
</script>

<template>
  <section class="card block" aria-labelledby="cp-title">
    <div class="row row--between">
      <h2 id="cp-title">{{ t('pa.cli.profile') }}</h2>
      <span class="tag" :class="state === 'active' ? 'tag--success' : 'tag--warning'">{{ t(stateLabel(state)) }}</span>
    </div>
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <dl v-if="!editing" class="facts">
      <div><dt>{{ t('pa.cli.contact') }}</dt><dd>{{ client.contact?.name ?? '—' }}</dd></div>
      <div><dt>{{ t('pa.email') }}</dt><dd>{{ client.contact?.email ?? '—' }}</dd></div>
      <div><dt>{{ t('pa.phone') }}</dt><dd>{{ client.contact?.phone ?? '—' }}</dd></div>
      <div><dt>{{ t('plat.col.city') }}</dt><dd>{{ [client.city, client.region].filter(Boolean).join(', ') || '—' }}</dd></div>
      <div v-if="client.state_reason"><dt>{{ t('pa.reason') }}</dt><dd>{{ client.state_reason }}</dd></div>
      <div v-if="client.archived_at"><dt>{{ t('pa.cli.archivedOn') }}</dt><dd>{{ formatDate(client.archived_at, locale) }}</dd></div>
    </dl>

    <form v-else class="form" novalidate @submit.prevent="save">
      <div class="grid2">
        <div class="field">
          <label for="cp-name">{{ t('pa.cli.name') }}</label>
          <input id="cp-name" v-model="form.name" type="text" maxlength="160" :aria-invalid="!!errors.name" />
          <span v-if="errors.name" class="error-line">{{ errors.name }}</span>
        </div>
        <div class="field"><label for="cp-contact">{{ t('pa.cli.contact') }}</label><input id="cp-contact" v-model="form.contact_name" type="text" maxlength="160" /></div>
        <div class="field"><label for="cp-email">{{ t('pa.email') }}</label><input id="cp-email" v-model="form.contact_email" type="email" maxlength="190" /></div>
        <div class="field">
          <label for="cp-phone">{{ t('pa.phone') }}</label>
          <input id="cp-phone" v-model="form.contact_phone" type="tel" maxlength="25" :aria-invalid="!!errors.contact_phone" />
          <span v-if="errors.contact_phone" class="error-line">{{ errors.contact_phone }}</span>
        </div>
        <div class="field"><label for="cp-city">{{ t('plat.col.city') }}</label><input id="cp-city" v-model="form.city" type="text" maxlength="80" /></div>
        <div class="field"><label for="cp-region">{{ t('pa.region') }}</label><input id="cp-region" v-model="form.region" type="text" maxlength="80" /></div>
      </div>
      <div class="row">
        <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('pa.save') }}</button>
        <button type="button" class="btn btn--quiet" @click="editing = false; reset()">{{ t('plat.cancel') }}</button>
      </div>
    </form>

    <div v-if="!editing && state !== 'archived'" class="row" role="group" :aria-label="t('pa.cli.profile')">
      <button type="button" class="btn btn--quiet" @click="editing = true">{{ t('pa.cli.edit') }}</button>
      <button v-for="k in kinds" :key="k" type="button" class="btn" :aria-expanded="deciding === k" @click="deciding = deciding === k ? null : k; reason = ''; errors = {}">{{ kindLabel(k) }}</button>
    </div>
    <p v-if="state === 'archived'" class="muted">{{ t('pa.cli.archivedText') }}</p>

    <form v-if="deciding" class="form panel" novalidate @submit.prevent="decide">
      <p>{{ t(deciding === 'suspend' ? 'pa.cli.suspendText' : deciding === 'archive' ? 'pa.cli.archiveText' : 'pa.cli.reactivateText') }}</p>
      <div class="field">
        <label for="cp-reason">{{ deciding === 'reactivate' ? t('plat.cli.reasonOptional') : t('plat.cli.reason') }}</label>
        <textarea id="cp-reason" v-model="reason" rows="3" maxlength="500" :aria-invalid="!!errors.reason" />
        <span v-if="errors.reason" class="error-line">{{ errors.reason }}</span>
      </div>
      <div class="row">
        <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ kindLabel(deciding) }}</button>
        <button type="button" class="btn btn--quiet" @click="deciding = null">{{ t('plat.cancel') }}</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.block {
  margin-top: 20px;
}
.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 32px;
  margin: 14px 0;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.facts dd {
  margin: 2px 0 0;
  font-weight: 700;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
  max-width: 560px;
}
.error-line {
  display: block;
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
