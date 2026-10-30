<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { tutelage, type Convention } from '@/api/higher-nationalexams';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import YearField from '../YearField.vue';
import { useNationalI18n } from '../national-exams/i18n';
import TutelageFrame from './TutelageFrame.vue';

// Conventions avec les universités de tutelle (EX-TUT-01) par filière et par année, avec alerte d'expiration (NOT-15).
const { tx } = useNationalI18n();
const higher = useHigherStore();
const list = useHigherAction();

const year = ref('');
const items = ref<Convention[]>([]);
const searched = ref(false);
const programs = computed(() => higher.programs.filter((p) => p.requires_tutelage));

async function load(): Promise<void> {
  if (!year.value) return;
  searched.value = false;
  const res = await list.run(async () => {
    // Une filière sans convention répond 404/422 : on l'ignore, seules les autres erreurs remontent.
    const found = await Promise.all(programs.value.map((p) => tutelage.convention(p.id, year.value).catch((e: unknown) => {
      const status = (e as { status?: number }).status;
      if (status === 404 || status === 422) return null;
      throw e;
    })));
    return found.filter((c): c is Convention => c !== null);
  });
  if (res) items.value = res;
  searched.value = true;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  year.value = higher.years[0]?.id ?? '';
  await load();
});
</script>

<template>
  <TutelageFrame :title="tx('htu.title')" :lede="tx('htu.lede')" @school-change="load">
    <form class="hi-form" role="search" @submit.prevent="load">
      <YearField id="tc-year" v-model="year" :label="tx('htu.year')" />
      <button type="submit" class="btn btn--primary" :disabled="list.busy.value || !year">{{ tx('htu.show') }}</button>
    </form>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ tx('htu.program') }}</th><th scope="col">{{ tx('htu.university') }}</th><th scope="col">{{ tx('htu.convention') }}</th><th scope="col">{{ tx('htu.endsOn') }}</th><th scope="col">{{ tx('htu.status') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.tutelage_id" data-test="convention-row" :data-expired="c.expired" :data-expiring="c.expiring">
            <th scope="row">{{ c.program_name }}</th>
            <td>{{ c.university_name }}</td>
            <td>{{ c.convention_reference ?? '—' }}<br /><small class="muted">{{ c.approved_programme_ref ?? '' }}</small></td>
            <td>{{ c.convention_ends_on ?? tx('htu.noEnd') }}<template v-if="c.days_left !== null && !c.expired"><br /><small class="muted">{{ tx('htu.daysLeft', { days: c.days_left }) }}</small></template></td>
            <td>
              <span v-if="c.expired" class="tag tag--danger" role="alert">{{ tx('htu.expired') }}</span>
              <span v-else-if="c.expiring" class="tag tag--warning" role="alert">{{ tx('htu.expiring') }}</span>
              <span v-else class="tag tag--neutral">{{ tx('htu.valid') }}</span>
            </td>
          </tr>
          <tr v-if="searched && items.length === 0"><td colspan="5" class="muted">{{ tx('htu.noConvention') }}</td></tr>
        </tbody>
      </table>
    </div>
  </TutelageFrame>
</template>
