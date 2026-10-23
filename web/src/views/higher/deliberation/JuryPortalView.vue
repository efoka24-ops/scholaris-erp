<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, type Jury } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import TwoFactorNotice from './TwoFactorNotice.vue';
import { DP } from './access';
import { dk, dt } from './i18n';
import { useJurySession } from './useJurySession';

// Portail du membre de jury (R21) : jurys où siéger, PV à signer (API-14, double authentification récente).
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const session = useJurySession();
const items = ref<Jury[]>([]);
const canSign = computed(() => ctx.can(...DP.sign) && !higher.readOnly);
const toSign = (j: Jury): boolean => !!j.deliberation_ref && !j.my_signature && j.status !== 'published';

async function load(): Promise<void> {
  const res = await list.run(() => deliberation.portal());
  if (res) items.value = res;
}

async function sign(j: Jury): Promise<void> {
  if (await session.sign(j)) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups');
  await load();
});
</script>

<template>
  <DelFrame :title="dt('hdl.portal.title')" :lede="dt('hdl.portal.lede')" :selector="false">
    <ErrorNotice :error="list.error.value" />
    <TwoFactorNotice v-if="session.needsTfa.value" />
    <ErrorNotice :error="session.error.value" />
    <p v-if="session.done.value === 'signed'" class="notice notice--success" role="status">{{ dt('hdl.jury.signDone') }}</p>
    <ul class="hi-list" role="list">
      <li v-for="j in items" :key="j.id" class="card" :data-test="`portal-${j.id}`">
        <p><strong>{{ higher.groupName(j.structure_id) }} · {{ j.period_label || j.period_id }}</strong> · {{ dk('hdl.session.', j.exam_session) }}</p>
        <p>
          <span class="tag" :class="`tag--${toneOf(j.status) ?? 'neutral'}`">{{ dk('hdl.state.', j.status) }}</span>
          {{ dt('hdl.portal.myRole') }} : {{ dk('hdl.jury.role.', j.my_role) }} · {{ j.my_signature ? dt('hdl.jury.signed') : dt('hdl.jury.notSigned') }}
        </p>
        <p v-if="toSign(j)" class="muted">{{ dt('hdl.portal.toSign') }}</p>
        <button v-if="canSign && toSign(j)" type="button" class="btn btn--ink" :disabled="session.busy.value" data-test="portal-sign" @click="sign(j)">{{ dt('hdl.jury.sign') }}</button>
      </li>
    </ul>
    <p v-if="items.length === 0 && !list.busy.value" class="muted">{{ dt('hdl.none') }}</p>
  </DelFrame>
</template>
