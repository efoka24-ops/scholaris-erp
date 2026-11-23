<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { admissions, type Campaign, type CampaignStatistics, type ContestSession, type RankingRow } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import PersonPicker from './PersonPicker.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Fiche de campagne : statistiques (EX-ADM-08), jury et classement (EX-ADM-04), concours : épreuves et convocations (EX-ADM-05).
// Design : Dashboard.html (cartes KPI) et Frais.html (tableaux).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const campaign = ref<Campaign | null>(null);
const stats = ref<CampaignStatistics | null>(null);
const sessions = ref<ContestSession[]>([]);
const ranking = ref<RankingRow[]>([]);
const convoked = ref<string | null>(null);
const president = ref<string | null>(null);
const memberPick = ref<string | null>(null);
const members = ref<string[]>([]);
const session = reactive({ contest_date: '', center_name: '', room_name: '', room_capacity: 30 });

const canManage = computed(() => ctx.can(...P.applicationManage) && !higher.readOnly);
const hasContest = computed(() => campaign.value?.selection_mode !== 'file');

async function fetchAll(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const res = await load.run(async () => {
    const c = await admissions.campaign(props.id);
    const [s, ss] = await Promise.all([admissions.statistics(props.id), c.selection_mode === 'file' ? Promise.resolve([] as ContestSession[]) : admissions.sessions(props.id)]);
    return { c, s, ss };
  });
  if (!res) return;
  campaign.value = res.c;
  stats.value = res.s;
  sessions.value = res.ss;
  president.value = res.c.jury?.president_person_id ?? null;
  members.value = [...(res.c.jury?.member_person_ids ?? [])];
}

function addMember(): void {
  if (memberPick.value && !members.value.includes(memberPick.value)) members.value.push(memberPick.value);
  memberPick.value = null;
}

async function saveJury(): Promise<void> {
  if (!president.value) return;
  const pres = president.value;
  const res = await act.run(() => admissions.setJury(props.id, { president_person_id: pres, member_person_ids: members.value }));
  if (res) campaign.value = res;
}

async function rank(): Promise<void> {
  const res = await act.run(() => admissions.rank(props.id));
  if (res) ranking.value = res;
}

async function createSession(): Promise<void> {
  if (!session.contest_date || !session.center_name.trim() || !session.room_name.trim()) return;
  const res = await act.run(() => admissions.createSession(props.id, { ...session, room_capacity: Number(session.room_capacity) }));
  if (!res) return;
  Object.assign(session, { contest_date: '', room_name: '' });
  sessions.value = await admissions.sessions(props.id).catch(() => sessions.value);
}

async function convoke(s: ContestSession): Promise<void> {
  const res = await act.run(() => admissions.convoke(s.id));
  if (res === undefined) return;
  const count = Array.isArray(res) ? res.length : Number((res as { count?: number }).count ?? 0);
  convoked.value = t('hi.adm.convoked', { count });
  sessions.value = await admissions.sessions(props.id).catch(() => sessions.value);
}

onMounted(async () => {
  await higher.ensure();
  await fetchAll();
});
</script>

<template>
  <HigherFrame :title="campaign ? campaign.title || higher.programName(campaign.program_id) : t('hi.adm.campaign')" section="admissions" :selector="false">
    <template #actions>
      <router-link :to="{ name: 'higher-campaigns' }" class="btn btn--quiet">← {{ t('hi.backToList') }}</router-link>
      <router-link v-if="campaign" :to="{ name: 'higher-applications', query: { campaign: campaign.id } }" class="btn">{{ t('hi.adm.seeApplications') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <template v-if="campaign">
      <div class="row">
        <span class="tag" :class="`tag--${toneOf(campaign.status) ?? 'neutral'}`">{{ t(k('hi.adm.state.', campaign.status)) }}</span>
        <span>{{ higher.levelName(campaign.level_id) }} · {{ higher.yearName(campaign.academic_year_id) }}</span>
        <span class="muted">{{ formatDate(campaign.opens_on, locale) }} → {{ formatDate(campaign.closes_on, locale) }}</span>
        <span>{{ t(k('hi.adm.mode.', campaign.selection_mode)) }}</span>
      </div>
      <p v-if="campaign.cancelled_reason" class="notice notice--warning">{{ t('hi.reason') }} : {{ campaign.cancelled_reason }}</p>

      <section v-if="stats" class="hi-section" aria-labelledby="stats-title">
        <h2 id="stats-title">{{ t('hi.adm.stats') }}</h2>
        <div class="grid grid--kpi">
          <article class="kpi"><h3 class="eyebrow">{{ t('hi.adm.stats.applications') }}</h3><p class="kpi__value">{{ stats.applications }}</p><p class="kpi__detail">{{ t('hi.adm.stats.online') }} {{ stats.by_channel.online ?? 0 }} · {{ t('hi.adm.stats.counter') }} {{ stats.by_channel.counter ?? 0 }}</p></article>
          <article class="kpi"><h3 class="eyebrow">{{ t('hi.adm.stats.seatsTaken') }}</h3><p class="kpi__value">{{ stats.seats_taken }} / {{ stats.seats }}</p><div class="kpi__bar" aria-hidden="true"><span :style="{ width: `${Math.min(100, stats.seats ? (100 * stats.seats_taken) / stats.seats : 0)}%` }" /></div><p class="kpi__detail">{{ t('hi.adm.stats.seatsLeft') }} : {{ stats.seats_left }}</p></article>
          <article class="kpi"><h3 class="eyebrow">{{ t('hi.adm.stats.over') }}</h3><p class="kpi__value">{{ stats.over_capacity_admissions }}</p></article>
          <article class="kpi"><h3 class="eyebrow">{{ t('hi.adm.stats.fees') }}</h3><p class="kpi__value">{{ formatXaf(stats.fees_collected_xaf, locale) }}</p></article>
          <article class="kpi"><h3 class="eyebrow">{{ t('hi.adm.stats.rate') }}</h3><p class="kpi__value">{{ stats.enrolment_rate_percent }} %</p></article>
        </div>
        <ul class="hi-list hi-small">
          <li v-for="(n, s) in stats.by_status" :key="s"><span class="hi-grow">{{ t(k('hi.app.st.', String(s))) }}</span><strong>{{ n }}</strong></li>
        </ul>
      </section>

      <div class="hi-two hi-section">
        <section class="card" aria-labelledby="jury-title">
          <h2 id="jury-title">{{ t('hi.adm.jury') }}</h2>
          <p v-if="!campaign.jury" class="muted">{{ t('hi.adm.juryNone') }}</p>
          <dl v-else class="hi-kv"><dt>{{ t('hi.adm.juryPresident') }}</dt><dd class="hi-mono">{{ campaign.jury.president_person_id }}</dd><dt>{{ t('hi.adm.juryMembers') }}</dt><dd class="hi-mono">{{ campaign.jury.member_person_ids.join(', ') || '—' }}</dd></dl>
          <template v-if="canManage">
            <PersonPicker id="jury-president" v-model="president" :label="t('hi.adm.juryPresident')" />
            <PersonPicker id="jury-member" v-model="memberPick" :label="t('hi.adm.juryMembers')" />
            <button type="button" class="btn btn--quiet" :disabled="!memberPick" @click="addMember">{{ t('hi.adm.juryAddMember') }}</button>
            <ul v-if="members.length" class="hi-list hi-small"><li v-for="m in members" :key="m"><span class="hi-grow hi-mono">{{ m }}</span><button type="button" class="hi-linkbtn" @click="members = members.filter((x) => x !== m)">{{ t('hi.fin.removeFee') }}</button></li></ul>
            <button type="button" class="btn btn--primary hi-section" :disabled="act.busy.value || !president" @click="saveJury">{{ t('hi.adm.jurySave') }}</button>
          </template>
        </section>

        <section class="card" aria-labelledby="rank-title">
          <h2 id="rank-title">{{ t('hi.adm.ranking') }}</h2>
          <p class="muted hi-small">{{ t('hi.adm.rankHint') }}</p>
          <button v-if="canManage" type="button" class="btn" :disabled="act.busy.value" @click="rank">{{ t('hi.adm.rankAction') }}</button>
          <div v-if="ranking.length" class="table-wrap hi-section">
            <table>
              <thead><tr><th scope="col" class="num">{{ t('hi.adm.rank') }}</th><th scope="col">{{ t('hi.app.reference') }}</th><th scope="col" class="num">{{ t('hi.adm.score') }}</th><th scope="col">{{ t('hi.status') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in ranking" :key="r.application_id">
                  <td class="num">{{ r.rank_position }}</td>
                  <th scope="row"><router-link :to="{ name: 'higher-application', params: { id: r.application_id } }">{{ r.reference }}</router-link></th>
                  <td class="num">{{ (r.score_hundredths / 100).toFixed(2) }}</td>
                  <td><span class="tag" :class="r.list_kind === 'main' ? 'tag--success' : 'tag--neutral'">{{ t(k('hi.adm.list.', r.list_kind)) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="card hi-section" aria-labelledby="contest-title">
        <h2 id="contest-title">{{ t('hi.adm.contest') }}</h2>
        <p v-if="!hasContest" class="muted">{{ t('hi.adm.contestFileOnly') }}</p>
        <template v-else>
          <p v-if="sessions.length === 0" class="muted">{{ t('hi.adm.contestNone') }}</p>
          <ul v-else class="hi-list">
            <li v-for="s in sessions" :key="s.id">
              <span class="hi-grow"><strong>{{ formatDate(s.contest_date, locale) }}</strong> · {{ s.center_name }} · {{ s.room_name }} ({{ s.seats?.length ?? 0 }} / {{ s.room_capacity }})</span>
              <button v-if="canManage" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="convoke(s)">{{ t('hi.adm.convoke') }}</button>
            </li>
          </ul>
          <p v-if="convoked" class="notice notice--success" role="status">{{ convoked }}</p>
          <form v-if="canManage" class="hi-form hi-section" novalidate @submit.prevent="createSession">
            <h3 class="hi-wide">{{ t('hi.adm.sessionNew') }}</h3>
            <div class="field"><label for="ss-date">{{ t('hi.adm.sessionDate') }}</label><input id="ss-date" v-model="session.contest_date" type="date" /></div>
            <div class="field"><label for="ss-center">{{ t('hi.adm.sessionCenter') }}</label><input id="ss-center" v-model="session.center_name" type="text" /></div>
            <div class="field"><label for="ss-room">{{ t('hi.adm.sessionRoom') }}</label><input id="ss-room" v-model="session.room_name" type="text" /></div>
            <div class="field"><label for="ss-cap">{{ t('hi.adm.sessionCapacity') }}</label><input id="ss-cap" v-model.number="session.room_capacity" type="number" min="1" /></div>
            <div class="field"><button type="submit" class="btn" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
          </form>
        </template>
      </section>
      <ErrorNotice :error="act.error.value" />
    </template>
  </HigherFrame>
</template>
