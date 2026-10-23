<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { CurriculumElement } from '@/api/higher';
import { attendance, type Assiduity, type AssiduityStudent, type AssiduityTarget } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import YearField from '../YearField.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import BarredLists from './BarredLists.vue';
import ElementField from './ElementField.vue';
import GroupField from './GroupField.vue';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Assiduité par groupe et EC (EX-PRE-02 à 05) : alerte NOT-07, non-autorisés à composer, transmission au service des examens,
// levée tracée par le directeur d'école (dérogation).
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const target = reactive({ curriculum_id: '', element_id: '', group_id: '', academic_year_id: '' });
const elementTitle = ref('');
const touched = ref(false);
const result = ref<Assiduity | null>(null);
const onlyBarred = ref(false);
const message = ref('');
const lifting = ref<AssiduityStudent | null>(null);
const lift = reactive({ reason: '', document_ref: '' });
const liftTried = ref(false);
const version = ref(0);

const canManage = computed(() => ctx.can(...TP.attendanceManage) && !higher.readOnly);
const canLift = computed(() => ctx.can(...TP.lift) && !higher.readOnly);
const students = computed(() => (result.value?.students ?? []).filter((s) => !onlyBarred.value || s.status === 'barred' || s.status === 'lifted'));
const tone = (s: string): string => (s === 'ok' ? 'success' : s === 'lifted' ? 'neutral' : 'warning');
const pct = (n: number): string => (locale.value === 'en' ? `${n.toFixed(1)}%` : `${n.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`);

function current(): AssiduityTarget | null {
  touched.value = true;
  if (!target.element_id || !target.group_id || !target.academic_year_id) return null;
  return { element_id: target.element_id, group_id: target.group_id, academic_year_id: target.academic_year_id };
}

async function load(): Promise<void> {
  const q = current();
  if (!q) return;
  message.value = '';
  const res = await list.run(() => attendance.assiduity(q));
  if (res) result.value = res;
}

async function sendAlerts(): Promise<void> {
  const q = current();
  if (!q) return;
  const res = await act.run(() => attendance.alerts({ ...q, element_title: elementTitle.value || q.element_id }));
  if (res) message.value = t('ht.ass.alertsDone', { count: res.alerted.length, already: res.already.length });
}

async function transmit(): Promise<void> {
  const q = current();
  if (!q) return;
  const res = await act.run(() => attendance.transmit(q));
  if (res) {
    message.value = t('ht.done');
    version.value++;
  }
}

async function confirmLift(): Promise<void> {
  liftTried.value = true;
  const s = lifting.value;
  if (!s || !lift.reason.trim()) return;
  const res = await act.run(() => attendance.lift({ person_id: s.person_id, element_id: target.element_id, group_id: target.group_id, reason: lift.reason.trim(), document_ref: lift.document_ref.trim() || null }));
  if (!res) return;
  lifting.value = null;
  liftTried.value = false;
  Object.assign(lift, { reason: '', document_ref: '' });
  await load();
}

onMounted(() => void higher.ensure());
</script>

<template>
  <TeachingFrame group="attendance" :title="t('ht.ass.title')" :lede="result ? t('ht.ass.lede', { alert: result.alert_percent, max: result.max_percent }) : t('ht.ass.ledeShort')">
    <form class="card hi-form" novalidate @submit.prevent="load">
      <GroupField id="as-grp" v-model="target.group_id" :invalid="touched" />
      <ElementField id="as-el" v-model:curriculum-id="target.curriculum_id" v-model:element-id="target.element_id" :invalid="touched" @picked="(e: CurriculumElement | null) => (elementTitle = e ? `${e.code} · ${e.title}` : '')" />
      <YearField id="as-year" v-model="target.academic_year_id" :invalid="touched && !target.academic_year_id" />
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="list.busy.value">{{ t('ht.load') }}</button></div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="message" class="notice notice--success" role="status">{{ message }}</p>

    <template v-if="result">
      <div class="hi-actions hi-section">
        <label class="check"><input v-model="onlyBarred" type="checkbox" /> {{ t('ht.ass.onlyBarred') }}</label>
        <span class="hi-grow" />
        <button v-if="canManage" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="sendAlerts">{{ t('ht.ass.alerts') }}</button>
        <button v-if="canManage" type="button" class="btn btn--ink" :disabled="act.busy.value" @click="transmit">{{ t('ht.ass.transmit') }}</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('ht.ass.matricule') }}</th><th scope="col" class="num">{{ t('ht.ass.sessions') }}</th><th scope="col" class="num">{{ t('ht.ass.unjustified') }}</th><th scope="col" class="num">{{ t('ht.ass.lates') }}</th><th scope="col" class="num">{{ t('ht.ass.rate') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in students" :key="s.person_id" data-test="assiduity-row">
              <th scope="row">{{ s.matricule ?? s.person_id }}</th>
              <td class="num">{{ s.sessions }}</td><td class="num">{{ s.unjustified }}</td><td class="num">{{ s.lates }}</td><td class="num">{{ pct(s.rate_percent) }}</td>
              <td><span class="tag" :class="`tag--${tone(s.status)}`">{{ t(hk('ht.ass.state.', s.status)) }}</span></td>
              <td class="hi-actions"><button v-if="canLift && s.status === 'barred'" type="button" class="hi-linkbtn" @click="lifting = s">{{ t('ht.ass.lift') }}</button></td>
            </tr>
            <tr v-if="students.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else class="muted hi-section">{{ t('ht.pickTarget') }}</p>

    <section v-if="lifting" class="card card--strong hi-section" role="dialog" aria-labelledby="lift-title" data-test="lift">
      <h2 id="lift-title">{{ t('ht.ass.lift') }} · {{ lifting.matricule ?? lifting.person_id }}</h2>
      <p>{{ t('ht.ass.liftText') }}</p>
      <div class="field"><label for="lift-reason">{{ t('hi.reason') }}</label><textarea id="lift-reason" v-model="lift.reason" :aria-invalid="liftTried && !lift.reason.trim()" /><span v-if="liftTried && !lift.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
      <div class="field"><label for="lift-doc">{{ t('ht.ass.document') }}</label><input id="lift-doc" v-model="lift.document_ref" type="text" /></div>
      <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="confirmLift">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="lifting = null">{{ t('hi.back') }}</button></div>
    </section>

    <BarredLists :group-id="target.group_id" :version="version" />
  </TeachingFrame>
</template>
