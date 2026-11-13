<script setup lang="ts">
import { reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { APP_TARGETS, actions, type AppTarget, type ClientEstablishment } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { targetKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Établissements d'un client : créer (type nursery, primary, secondary, higher), renommer, fermer (motif), rouvrir.
const props = defineProps<{ tenant: string; establishments: ClientEstablishment[]; readonly?: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { t, locale } = useI18n();
const action = useSensitiveAction();
const creating = ref(false);
const form = reactive({ name: '', type: '' as AppTarget | '' });
const editing = ref<{ id: string; mode: 'rename' | 'close' } | null>(null);
const value = ref('');
const error = ref<string | null>(null);
const done = ref<string | null>(null);

async function create(): Promise<void> {
  error.value = null;
  if (form.name.trim().length < 2 || form.type === '') {
    error.value = t('pa.est.invalid');
    return;
  }
  const res = await action.run<ClientEstablishment>(actions.createEstablishment(props.tenant, form.name.trim(), form.type), t('pa.est.create'));
  if (!res) return;
  Object.assign(form, { name: '', type: '' });
  creating.value = false;
  done.value = t('pa.done');
  emit('changed');
}

function start(e: ClientEstablishment, mode: 'rename' | 'close'): void {
  editing.value = editing.value?.id === e.id && editing.value.mode === mode ? null : { id: e.id, mode };
  value.value = mode === 'rename' ? e.name : '';
  error.value = null;
}

async function submit(e: ClientEstablishment): Promise<void> {
  if (!editing.value) return;
  const text = value.value.trim();
  if (text === '' || (editing.value.mode === 'rename' && text.length < 2)) {
    error.value = editing.value.mode === 'rename' ? t('pa.est.invalid') : t('plat.cli.reasonRequired');
    return;
  }
  const act = editing.value.mode === 'rename' ? actions.renameEstablishment(props.tenant, e.id, text) : actions.establishmentState(props.tenant, e.id, true, text);
  const res = await action.run<ClientEstablishment>(act, `${t(editing.value.mode === 'rename' ? 'pa.est.rename' : 'pa.est.close')} · ${e.name}`);
  if (!res) return;
  editing.value = null;
  done.value = t('pa.done');
  emit('changed');
}

async function reopen(e: ClientEstablishment): Promise<void> {
  const res = await action.run<ClientEstablishment>(actions.establishmentState(props.tenant, e.id, false, null), `${t('pa.est.reopen')} · ${e.name}`);
  if (!res) return;
  done.value = t('pa.done');
  emit('changed');
}
</script>

<template>
  <section class="block" aria-labelledby="est-title">
    <div class="row row--between">
      <h2 id="est-title">{{ t('plat.cli.establishments') }}</h2>
      <button v-if="!readonly" type="button" class="btn" :aria-expanded="creating" @click="creating = !creating">{{ t('pa.est.create') }}</button>
    </div>
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <form v-if="creating" class="card row create" novalidate @submit.prevent="create">
      <div class="field grow">
        <label for="est-name">{{ t('pa.est.name') }}</label>
        <input id="est-name" v-model="form.name" type="text" maxlength="160" />
      </div>
      <div class="field">
        <label for="est-type">{{ t('plat.col.target') }}</label>
        <select id="est-type" v-model="form.type">
          <option value="" disabled>{{ t('pa.choose') }}</option>
          <option v-for="tp in APP_TARGETS" :key="tp" :value="tp">{{ t(targetKey(tp)) }}</option>
        </select>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('pa.est.create') }}</button>
    </form>
    <p v-if="error" class="error-line" role="alert">{{ error }}</p>

    <p v-if="establishments.length === 0" class="card muted">{{ t('plat.cli.noEstablishments') }}</p>
    <ul v-else class="est">
      <li v-for="e in establishments" :key="e.id" class="card card--tight">
        <div class="row row--between">
          <span class="est__name">{{ e.name }}</span>
          <span class="tag">{{ t(targetKey(e.type)) }}</span>
        </div>
        <p v-if="e.closed_at" class="muted small">{{ t('pa.est.closedOn', { date: formatDate(e.closed_at, locale) }) }}</p>
        <div v-if="!readonly" class="row actions">
          <button type="button" class="btn btn--quiet" @click="start(e, 'rename')">{{ t('pa.est.rename') }}</button>
          <button v-if="!e.closed_at" type="button" class="btn btn--quiet" @click="start(e, 'close')">{{ t('pa.est.close') }}</button>
          <button v-else type="button" class="btn btn--quiet" :disabled="action.busy.value" @click="reopen(e)">{{ t('pa.est.reopen') }}</button>
        </div>
        <form v-if="editing?.id === e.id" class="row create" novalidate @submit.prevent="submit(e)">
          <div class="field grow">
            <label :for="`est-v-${e.id}`">{{ editing.mode === 'rename' ? t('pa.est.name') : t('plat.cli.reason') }}</label>
            <input :id="`est-v-${e.id}`" v-model="value" type="text" maxlength="500" />
          </div>
          <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ editing.mode === 'rename' ? t('pa.save') : t('pa.est.close') }}</button>
        </form>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.block {
  margin-top: 28px;
}
.est {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
}
.est__name {
  font-weight: 700;
}
.create {
  align-items: flex-end;
  margin-bottom: 12px;
}
.create .field {
  margin-bottom: 0;
}
.grow {
  flex: 1 1 220px;
}
.actions {
  gap: 4px;
  margin-top: 8px;
}
.small {
  font-size: 13px;
  margin: 4px 0 0;
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
