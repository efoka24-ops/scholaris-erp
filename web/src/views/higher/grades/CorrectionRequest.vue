<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, type AbsenceCode } from '@/api/higher-grades';
import { useHigherAction } from '../useHigherAction';
import { parseCell } from './marks';
import { useGradesI18n } from './i18n';

// Demande de correction d'une note verrouillée (API-12) : nouvelle valeur, motif et pièce obligatoires.
const props = defineProps<{ markId: string; title: string; max: number; step: number; codes: Record<string, AbsenceCode>; queryId?: string | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'sent'): void }>();
const { tg } = useGradesI18n();
const act = useHigherAction();

const score = ref('');
const absence = ref('');
const reason = ref('');
const attachment = ref('');
const tried = ref(false);

const parsed = computed(() => parseCell(score.value, props.max, props.step, props.codes));
const valueBad = computed(() => tried.value && (score.value.trim() === '' ? !absence.value : !parsed.value.ok));

async function send(): Promise<void> {
  tried.value = true;
  if (valueBad.value || !reason.value.trim() || !attachment.value.trim()) return;
  const value = score.value.trim() !== '' && parsed.value.ok ? parsed.value.value : { score: null, absence: absence.value };
  const res = await act.run(() =>
    grades.requestCorrection(props.markId, {
      ...(value.absence ? { absence: value.absence } : { score: value.score }),
      reason: reason.value.trim(),
      attachment_ref: attachment.value.trim(),
      ...(props.queryId ? { query_id: props.queryId } : {}),
    }),
  );
  if (res) emit('sent');
}
</script>

<template>
  <form class="card card--strong hi-section hi-form" novalidate data-test="correction-request" @submit.prevent="send">
    <h2 class="hi-wide">{{ tg('hg.corr.request') }} · {{ title }}</h2>
    <div class="field">
      <label for="cr-score">{{ tg('hg.corr.newValue') }}</label>
      <input id="cr-score" v-model="score" type="text" inputmode="decimal" autocomplete="off" :aria-invalid="valueBad" />
    </div>
    <div class="field">
      <label for="cr-abs">{{ tg('hg.corr.newAbsence') }}</label>
      <select id="cr-abs" v-model="absence" :disabled="score.trim() !== ''"><option value="">—</option><option v-for="(c, key) in codes" :key="key" :value="key">{{ c.aliases[0] ?? key }} · {{ c.label }}</option></select>
      <span v-if="valueBad" class="error">{{ tg('hg.corr.valueRequired') }}</span>
    </div>
    <div class="field hi-wide">
      <label for="cr-reason">{{ tg('hg.corr.reason') }}</label>
      <textarea id="cr-reason" v-model="reason" maxlength="500" :aria-invalid="tried && !reason.trim()" />
      <span v-if="tried && !reason.trim()" class="error">{{ tg('hg.required') }}</span>
    </div>
    <div class="field hi-wide">
      <label for="cr-att">{{ tg('hg.corr.attachment') }}</label>
      <input id="cr-att" v-model="attachment" type="text" maxlength="255" :aria-invalid="tried && !attachment.trim()" />
      <span v-if="tried && !attachment.trim()" class="error">{{ tg('hg.required') }}</span>
    </div>
    <ErrorNotice class="hi-wide" :error="act.error.value" />
    <div class="hi-actions hi-wide">
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ tg('hg.corr.send') }}</button>
      <button type="button" class="btn btn--quiet" @click="emit('close')">{{ tg('hg.life.cancel') }}</button>
    </div>
  </form>
</template>
