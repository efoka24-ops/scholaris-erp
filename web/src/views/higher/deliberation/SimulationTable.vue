<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Simulation, SimStudent } from '@/api/higher-deliberation';
import { dt, num } from './i18n';

// Tableau de la simulation (API-13) : moyenne, MGP, crédits, mention, proposition ; cas limites surlignés.
const props = defineProps<{ sim: Simulation; canGrant?: boolean }>();
const emit = defineEmits<{ (e: 'grant', s: SimStudent): void }>();
const onlyBorderline = ref(false);
const rows = computed(() => (onlyBorderline.value ? props.sim.students.filter((s) => s.borderline.length > 0) : props.sim.students));
const proposals = computed(() => Object.entries(props.sim.summary.by_proposal ?? {}));
</script>

<template>
  <div class="hdl-sim">
    <p class="muted">{{ dt('hdl.prep.rules', { strategy: sim.rules.strategy, version: sim.rules.version }) }} · {{ dt('hdl.prep.summary', { students: sim.summary.students, borderline: sim.summary.borderline }) }}</p>
    <p v-if="sim.unlocked" class="notice notice--warning">{{ dt('hdl.prep.unlocked') }}</p>
    <div v-if="proposals.length" class="hdl-counts">
      <div v-for="[label, count] in proposals" :key="label" class="hdl-count"><span class="muted">{{ label }}</span><strong>{{ count }}</strong></div>
    </div>
    <label class="check"><input v-model="onlyBorderline" type="checkbox" data-test="only-borderline" /> {{ dt('hdl.prep.borderlineOnly') }}</label>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ dt('hdl.student') }}</th><th scope="col" class="num">{{ dt('hdl.prep.average') }}</th><th scope="col" class="num">{{ dt('hdl.prep.gpa') }}</th>
            <th scope="col" class="num">{{ dt('hdl.prep.credits') }}</th><th scope="col">{{ dt('hdl.prep.mention') }}</th><th scope="col">{{ dt('hdl.prep.proposal') }}</th>
            <th scope="col" class="num">{{ dt('hdl.prep.rank') }}</th><th v-if="canGrant" scope="col">{{ dt('hdl.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in rows" :key="s.person_id" :class="{ 'hdl-borderline': s.borderline.length > 0 }" :data-test="`sim-${s.person_id}`">
            <th scope="row">{{ s.name ?? s.person_id }}<span v-if="s.matricule" class="hdl-sub">{{ s.matricule }}</span></th>
            <td class="num" data-test="average">
              {{ num(s.average) }}
              <span v-if="s.jury_points" class="hdl-sub">+{{ num(s.jury_points) }} {{ dt('hdl.prep.points').toLowerCase() }}</span>
            </td>
            <td class="num" data-test="gpa">{{ num(s.gpa) }}</td>
            <td class="num" data-test="credits">{{ s.credits_earned }} / {{ s.credits_total }}</td>
            <td>{{ s.mention ?? '—' }}</td>
            <td>
              <span class="tag tag--neutral">{{ s.proposal ?? '—' }}</span>
              <span v-for="(b, i) in s.borderline" :key="i" class="hdl-sub" data-test="borderline">
                <strong>{{ dt('hdl.prep.borderline') }}</strong>{{ b.unit ? ` · ${b.unit}` : '' }} : {{ dt('hdl.prep.borderlineGap', { gap: num(b.gap), threshold: num(b.threshold) }) }}
              </span>
            </td>
            <td class="num">{{ s.rank ?? '—' }}</td>
            <td v-if="canGrant"><button type="button" class="hi-linkbtn" @click="emit('grant', s)">{{ dt('hdl.prep.give') }}</button></td>
          </tr>
          <tr v-if="rows.length === 0"><td :colspan="canGrant ? 8 : 7" class="muted">{{ dt('hdl.none') }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
