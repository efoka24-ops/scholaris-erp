<script setup lang="ts">
// Remédiation (arrêté du 14 août 2026 : registre et fiche de suivi individualisée obligatoires) : séances de la classe avec élèves
// présents, minutes par semaine et par discipline comparées à la valeur paramétrée, fiches de suivi et revues datées.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primaryP2, type RemediationPlan, type RemediationRegister } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { todayIso } from '@/lib/format';
import { P } from './access';
import { usePicker } from './usePicker';
import { useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const picker = usePicker();
const register = ref<RemediationRegister | null>(null);
const plans = ref<RemediationPlan[]>([]);
const plan = ref<RemediationPlan | null>(null);
const notice = ref<string | null>(null);
const canWrite = computed(() => can(...P.remediationWrite));
const session = reactive({ held_on: todayIso(), duration: 60, form: '', subject: '', objective: '', people: [] as string[] });
const draftPlan = reactive({ person: '', subject: '', difficulties: '', actions: '' });
const review = reactive({ note: '', status: 'in_progress' as 'open' | 'in_progress' | 'resolved' });
const formLabel = (code: string): string => {
  const f = register.value?.forms[code];
  return f ? (locale.value === 'en' ? f.en : f.fr) : code;
};

async function load(): Promise<void> {
  register.value = null;
  plan.value = null;
  if (picker.classId.value === '') return;
  await run(async () => {
    register.value = await primaryP2.remediation(picker.classId.value);
    plans.value = await primaryP2.plans(picker.classId.value);
    session.form = Object.keys(register.value.forms)[0] ?? '';
    draftPlan.person = register.value.learners[0]?.person_id ?? '';
  });
}

async function addSession(): Promise<void> {
  const done = await run(() => primaryP2.addRemediation(picker.classId.value, {
    held_on: session.held_on, duration_minutes: Number(session.duration), remediation_form: session.form, subject_label: session.subject.trim(), objective: session.objective.trim(), person_ids: [...session.people],
  }));
  if (done) {
    notice.value = t('pri.rem.sessionSaved');
    session.people = [];
    session.objective = '';
    await load();
  }
}

async function addPlan(): Promise<void> {
  const done = await run(() => primaryP2.openPlan({ person_id: draftPlan.person, class_id: picker.classId.value, subject_label: draftPlan.subject.trim(), difficulties: draftPlan.difficulties.trim(), actions: draftPlan.actions.trim() }));
  if (done) {
    plan.value = done;
    draftPlan.difficulties = '';
    draftPlan.actions = '';
    plans.value = await primaryP2.plans(picker.classId.value);
  }
}

async function showPlan(id: string): Promise<void> {
  plan.value = (await run(() => primaryP2.plan(id))) ?? null;
}

async function addReview(): Promise<void> {
  const current = plan.value;
  if (!current) return;
  const done = await run(() => primaryP2.reviewPlan(current.id, { progress_note: review.note.trim(), status_after: review.status }));
  if (done) {
    plan.value = done;
    review.note = '';
    plans.value = await primaryP2.plans(picker.classId.value);
  }
}

watch(picker.classId, () => void load());
onMounted(async () => {
  await run(() => picker.load());
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.rem.title') }}</h1>
      <p class="lede">{{ t('pri.rem.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="field">
        <label for="rm-class">{{ t('pri.call.class') }}</label>
        <select id="rm-class" v-model="picker.classId.value"><option v-for="c in picker.classes.value" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </div>
      <p v-if="notice" class="notice" role="status">{{ notice }}</p>
    </section>

    <template v-if="register">
      <section class="card" aria-labelledby="rm-weekly">
        <h2 id="rm-weekly">{{ t('pri.rem.weekly') }}</h2>
        <p class="muted">{{ t('pri.rem.target', { minutes: register.weekly_minutes_target }) }}</p>
        <p v-if="register.weekly.length === 0" class="muted">{{ t('pri.rem.noSession') }}</p>
        <div v-for="w in register.weekly" :key="w.week" class="week">
          <p class="week__label">{{ t('pri.rem.week', { week: w.week }) }}</p>
          <ul class="rows">
            <li v-for="s in w.subjects" :key="s.subject">
              <span>{{ s.subject }}<template v-if="s.fundamental"> · {{ t('pri.rem.fundamental') }}</template></span>
              <span class="tag" :class="s.meets_target ? 'tag--success' : 'tag--warning'">{{ s.minutes }} min</span>
            </li>
          </ul>
        </div>
      </section>

      <section class="card" aria-labelledby="rm-register">
        <h2 id="rm-register">{{ t('pri.rem.register') }}</h2>
        <ul class="rows">
          <li v-for="s in register.sessions" :key="s.id">
            <span>{{ s.held_on }} · {{ s.subject_label }} · {{ formLabel(s.remediation_form) }} · {{ s.duration_minutes }} min</span>
            <span class="muted">{{ s.participants.map((p) => p.name ?? '').join(', ') }}</span>
          </li>
        </ul>
        <form v-if="canWrite" class="form" @submit.prevent="addSession">
          <h3>{{ t('pri.rem.addSession') }}</h3>
          <div class="grid2">
            <div class="field"><label for="rm-date">{{ t('pri.rem.date') }}</label><input id="rm-date" v-model="session.held_on" type="date" required /></div>
            <div class="field"><label for="rm-dur">{{ t('pri.rem.duration') }}</label><input id="rm-dur" v-model="session.duration" type="number" min="1" max="600" required /></div>
            <div class="field"><label for="rm-form">{{ t('pri.rem.form') }}</label><select id="rm-form" v-model="session.form"><option v-for="(f, code) in register.forms" :key="code" :value="code">{{ locale === 'en' ? f.en : f.fr }}</option></select></div>
            <div class="field"><label for="rm-subj">{{ t('pri.rem.subject') }}</label><input id="rm-subj" v-model="session.subject" type="text" maxlength="160" required /></div>
          </div>
          <div class="field"><label for="rm-obj">{{ t('pri.rem.objective') }}</label><input id="rm-obj" v-model="session.objective" type="text" maxlength="255" required /></div>
          <fieldset class="people">
            <legend>{{ t('pri.rem.learners') }}</legend>
            <label v-for="l in register.learners" :key="l.person_id" class="check"><input v-model="session.people" type="checkbox" :value="l.person_id" /> {{ l.name }}</label>
          </fieldset>
          <button type="submit" class="btn btn--primary" :disabled="busy || session.people.length === 0">{{ t('pri.rem.save') }}</button>
        </form>
      </section>

      <section class="card" aria-labelledby="rm-plans">
        <h2 id="rm-plans">{{ t('pri.rem.plans') }}</h2>
        <p v-if="plans.length === 0" class="muted">{{ t('pri.rem.noPlan') }}</p>
        <ul class="rows">
          <li v-for="p in plans" :key="p.id">
            <span><strong>{{ p.name }}</strong> · {{ p.subject_label }}</span>
            <span class="tag">{{ t(`pri.rem.status.${p.status}` as MessageKey) }}</span>
            <button type="button" class="btn btn--quiet" @click="showPlan(p.id)">{{ t('pri.rem.open') }}</button>
          </li>
        </ul>
        <form v-if="canWrite" class="form" @submit.prevent="addPlan">
          <h3>{{ t('pri.rem.newPlan') }}</h3>
          <div class="field"><label for="rm-pp">{{ t('pri.cards.learner') }}</label><select id="rm-pp" v-model="draftPlan.person"><option v-for="l in register.learners" :key="l.person_id" :value="l.person_id">{{ l.name }}</option></select></div>
          <div class="field"><label for="rm-ps">{{ t('pri.rem.subject') }}</label><input id="rm-ps" v-model="draftPlan.subject" type="text" maxlength="160" required /></div>
          <div class="field"><label for="rm-pd">{{ t('pri.rem.difficulties') }}</label><textarea id="rm-pd" v-model="draftPlan.difficulties" rows="2" maxlength="2000" required /></div>
          <div class="field"><label for="rm-pa">{{ t('pri.rem.actions') }}</label><textarea id="rm-pa" v-model="draftPlan.actions" rows="2" maxlength="2000" required /></div>
          <button type="submit" class="btn" :disabled="busy">{{ t('pri.rem.openPlan') }}</button>
        </form>
      </section>

      <section v-if="plan" class="card" aria-labelledby="rm-plan">
        <h2 id="rm-plan">{{ t('pri.rem.sheet', { name: plan.name }) }}</h2>
        <p><strong>{{ plan.subject_label }}</strong> · {{ t(`pri.rem.status.${plan.status}` as MessageKey) }} · {{ t('pri.rem.since', { date: plan.opened_on }) }}</p>
        <p>{{ t('pri.rem.difficulties') }} : {{ plan.difficulties }}</p>
        <p>{{ t('pri.rem.actions') }} : {{ plan.actions }}</p>
        <p class="muted">{{ t('pri.rem.attended', { count: plan.sessions_attended ?? 0, minutes: plan.minutes_attended ?? 0 }) }}</p>
        <ul class="rows">
          <li v-for="r in plan.reviews ?? []" :key="r.id"><span>{{ r.reviewed_on }} · {{ r.progress_note }}</span><span class="tag">{{ t(`pri.rem.status.${r.status_after}` as MessageKey) }}</span></li>
        </ul>
        <form v-if="canWrite && plan.status !== 'resolved'" class="form" @submit.prevent="addReview">
          <div class="field"><label for="rm-rn">{{ t('pri.rem.progress') }}</label><input id="rm-rn" v-model="review.note" type="text" maxlength="500" required /></div>
          <div class="field">
<label for="rm-rs">{{ t('pri.rem.statusAfter') }}</label>
            <select id="rm-rs" v-model="review.status"><option v-for="s in ['open', 'in_progress', 'resolved']" :key="s" :value="s">{{ t(`pri.rem.status.${s}` as MessageKey) }}</option></select>
          </div>
          <button type="submit" class="btn" :disabled="busy">{{ t('pri.rem.addReview') }}</button>
        </form>
      </section>
    </template>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.rows li { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border, #dcdcd8); padding: 6px 0; }
.week__label { font-weight: 600; margin: 8px 0 4px; }
.form { display: grid; gap: 6px; margin-top: 16px; }
.people { display: grid; gap: 4px; border: 1px solid var(--border, #dcdcd8); padding: 8px; }
.check { display: flex; gap: 8px; align-items: center; min-height: 2.75rem; }
textarea { width: 100%; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
