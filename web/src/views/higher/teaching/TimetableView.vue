<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { addDays, mondayOf, teaching, WEEKDAYS, type Conflict, type Room, type TimetableChange, type TimetableEntry } from '@/api/higher-teaching';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import PersonPicker from '../PersonPicker.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import ChangeForm from './ChangeForm.vue';
import GroupField from './GroupField.vue';
import SlotForm from './SlotForm.vue';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Emploi du temps de la semaine (EX-EDT-01 à 05) : par groupe, par enseignant ou le mien ; créneaux, conflits, reports.
// L'appel se fait sur l'écran du socle (route « attendance ») : on y renvoie depuis chaque créneau.
type Mode = 'group' | 'teacher' | 'mine';
const { t } = useHt();
const ctx = useContextStore();
const auth = useAuthStore();
const higher = useHigherStore();
const router = useRouter();
const list = useHigherAction();
const act = useHigherAction();

const canManage = computed(() => ctx.can(...TP.timetableManage) && !higher.readOnly);
const canRead = computed(() => ctx.can(...TP.timetableRead));
const mode = ref<Mode>(canRead.value ? 'group' : 'mine');
const groupId = ref('');
const personId = ref<string | null>(null);
const week = ref(mondayOf(new Date().toISOString().slice(0, 10)));
const entries = ref<TimetableEntry[]>([]);
const changes = ref<TimetableChange[]>([]);
const conflicts = ref<Conflict[]>([]);
const rooms = ref<Room[]>([]);
const changing = ref<{ entry: TimetableEntry; date: string } | null>(null);
const canCall = computed(() => router.hasRoute('attendance') && ctx.can(...TP.attendanceTake));

const days = computed(() =>
  WEEKDAYS.map((d) => ({ d, date: addDays(week.value, d - 1), items: entries.value.filter((e) => e.weekday === d).sort((a, b) => a.start_time.localeCompare(b.start_time)) }))
    .filter((x) => x.d <= 6 || x.items.length > 0),
);
const roomCode = (id: string | null): string => rooms.value.find((r) => r.id === id)?.code ?? '—';
const changeOf = (e: TimetableEntry, date: string): TimetableChange | undefined => changes.value.find((c) => c.entry_id === e.id && c.occurs_on === date);

async function load(): Promise<void> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>), week: week.value };
  if (mode.value === 'group') {
    if (!groupId.value) return;
    q.group_id = groupId.value;
  } else {
    const who = mode.value === 'mine' ? auth.me?.person_id : personId.value;
    if (!who) return;
    q.person_id = who;
  }
  const res = await list.run(() => teaching.timetable(q));
  if (res) {
    entries.value = res.entries;
    changes.value = res.changes;
  }
}

async function loadConflicts(): Promise<void> {
  if (!canManage.value) return;
  const res = await act.run(() => teaching.conflicts(higher.scope()));
  if (res) conflicts.value = res;
}

function shift(days: number): void {
  week.value = addDays(week.value, days);
  void load();
}

async function remove(e: TimetableEntry): Promise<void> {
  const res = await act.run(() => teaching.deleteEntry(e.id));
  if (res !== undefined) await Promise.all([load(), loadConflicts()]);
}

async function refresh(): Promise<void> {
  changing.value = null;
  await Promise.all([load(), loadConflicts()]);
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  if (canRead.value) {
    const r = await act.run(() => teaching.rooms(higher.scope()));
    if (r) rooms.value = r;
  }
  await refresh();
});
</script>

<template>
  <TeachingFrame :title="t('ht.tt.title')" :lede="t('ht.tt.lede')" @school-change="refresh">
    <div class="hi-tabs" role="tablist">
      <button v-if="canRead" type="button" role="tab" :aria-selected="mode === 'group'" @click="mode = 'group'; load()">{{ t('ht.tt.byGroup') }}</button>
      <button v-if="canRead" type="button" role="tab" :aria-selected="mode === 'teacher'" @click="mode = 'teacher'; load()">{{ t('ht.tt.byTeacher') }}</button>
      <button v-if="auth.me?.person_id" type="button" role="tab" :aria-selected="mode === 'mine'" @click="mode = 'mine'; load()">{{ t('ht.tt.mine') }}</button>
    </div>
    <div class="hi-form">
      <GroupField v-if="mode === 'group'" id="tt-group" :model-value="groupId" @update:model-value="(v: string) => { groupId = v; load(); }" />
      <div v-if="mode === 'teacher'" class="hi-wide"><PersonPicker id="tt-person" :model-value="personId" :label="t('ht.teacher')" @update:model-value="(v: string | null) => { personId = v; load(); }" /></div>
      <div class="field"><label for="tt-week">{{ t('ht.tt.week') }}</label><input id="tt-week" :value="week" type="date" @change="week = mondayOf(($event.target as HTMLInputElement).value || week); load()" /></div>
      <div class="field hi-actions">
        <button type="button" class="btn btn--quiet" :aria-label="t('ht.tt.prev')" @click="shift(-7)">←</button>
        <button type="button" class="btn btn--quiet" :aria-label="t('ht.tt.next')" @click="shift(7)">→</button>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />

    <p v-if="entries.length === 0" class="muted">{{ t('ht.tt.empty') }}</p>
    <div v-else class="tt-week" data-test="tt-week">
      <section v-for="day in days" :key="day.d" class="tt-day">
        <h2 class="tt-day__head">{{ t(hk('ht.tt.day.', day.d)) }} <span class="muted hi-small">{{ day.date.slice(8, 10) }}/{{ day.date.slice(5, 7) }}</span></h2>
        <article v-for="e in day.items" :key="e.id" class="tt-slot" :class="{ 'tt-slot--changed': changeOf(e, day.date) }" data-test="tt-slot">
          <p class="tt-slot__time">{{ e.start_time }}–{{ e.end_time }} · {{ t(hk('ht.kind.', e.session_kind)) }}</p>
          <p class="tt-slot__title">{{ e.element_title }}</p>
          <p class="muted hi-small">{{ higher.groupName(e.group_id) }} · {{ t('ht.room') }} {{ roomCode(e.room_id) }}</p>
          <p v-if="changeOf(e, day.date)" class="tag tag--warning">{{ t(hk('ht.tt.change.', changeOf(e, day.date)!.change_kind)) }}</p>
          <div class="hi-actions">
            <router-link v-if="canCall" :to="{ name: 'attendance' }" class="hi-linkbtn" data-test="call-roll">{{ t('ht.tt.callRoll') }}</router-link>
            <button v-if="canManage" type="button" class="hi-linkbtn" @click="changing = { entry: e, date: day.date }">{{ t('ht.tt.change') }}</button>
            <button v-if="canManage" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="remove(e)">{{ t('ht.tt.delete') }}</button>
          </div>
        </article>
      </section>
    </div>

    <ChangeForm v-if="changing" :key="changing.entry.id + changing.date" :entry="changing.entry" :occurs-on="changing.date" :rooms="rooms" @done="refresh" @close="changing = null" />

    <section v-if="canManage" class="hi-section" data-test="conflicts">
      <h2>{{ t('ht.tt.conflicts') }}</h2>
      <p class="muted hi-small">{{ t('ht.tt.conflictsLede') }}</p>
      <ul v-if="conflicts.length" class="hi-list"><li v-for="(c, i) in conflicts" :key="i"><span class="tag tag--warning">{{ t(hk('ht.tt.conflict.', c.kind)) }}</span> {{ c.message }}</li></ul>
      <p v-else class="muted">{{ t('ht.tt.noConflicts') }}</p>
    </section>

    <SlotForm v-if="canManage" :group-id="mode === 'group' ? groupId : undefined" :week-start="week" :rooms="rooms.filter((r) => r.active)" @created="refresh" />
  </TeachingFrame>
</template>

<style scoped>
.tt-week {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}
.tt-day__head {
  font-size: 15px;
  margin: 0 0 8px;
  padding-bottom: 6px;
  border-bottom: 2px solid var(--ink);
}
.tt-slot {
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  background: var(--surface);
  padding: 8px 10px;
  margin-bottom: 8px;
}
.tt-slot--changed {
  border-left-color: var(--warning);
}
.tt-slot p {
  margin: 0 0 4px;
}
.tt-slot__time {
  font-weight: 700;
  font-size: 13px;
}
.tt-slot__title {
  font-weight: 700;
}
</style>
