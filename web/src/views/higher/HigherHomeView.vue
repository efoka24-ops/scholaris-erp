<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { useHigherStore } from './store';
import { useRouter } from 'vue-router';

// Accueil de l'application IPES (maquette Dashboard.html : cartes à bordure, liens d'action) : rubriques ouvertes selon les permissions.
const { t } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();

const cards: Array<{ name: string; title: MessageKey; text: MessageKey; perms?: readonly string[]; roles?: string[] }> = [
  { name: 'higher-structure', title: 'nav.higher.academic', text: 'hi.aca.lede', perms: P.settingsRead },
  { name: 'higher-authorizations', title: 'hi.auth.title', text: 'hi.auth.lede', perms: P.registersRead },
  { name: 'higher-regulations', title: 'hi.reg.title', text: 'hi.reg.lede', perms: P.registersRead },
  { name: 'higher-curricula', title: 'nav.higher.curricula', text: 'hi.cur.lede', perms: P.curriculumRead },
  { name: 'higher-campaigns', title: 'nav.higher.admissions', text: 'hi.adm.lede', perms: P.admissionRead },
  { name: 'higher-enrolments', title: 'nav.higher.enrolments', text: 'hi.ins.lede', perms: P.enrolmentRead },
  { name: 'higher-accounts', title: 'nav.higher.finance', text: 'hi.fin.accountsLede', perms: P.billingRead },
  { name: 'higher-documents', title: 'nav.higher.documents', text: 'hi.doc.lede', perms: P.documentRead },
  { name: 'higher-timetable', title: 'nav.higher.teaching', text: 'ht.tt.lede' },
  { name: 'higher-assiduity', title: 'nav.higher.attendance', text: 'ht.ass.ledeShort' },
  { name: 'higher-group-delegates', title: 'hx.del.title', text: 'hx.del.lede' },
  { name: 'higher-timetable-print', title: 'hx.print.title', text: 'hx.print.lede' },
  { name: 'higher-grades', title: 'nav.higherGrades', text: 'hg.lede' },
  { name: 'higher-del-juries', title: 'nav.higher.deliberation', text: 'hdl.jury.lede' },
  { name: 'higher-internships', title: 'hs.c.title', text: 'hs.c.lede' },
  { name: 'higher-defenses', title: 'hs.s.title', text: 'hs.s.lede' },
  { name: 'higher-vacations', title: 'nav.higher.vacations', text: 'hv.list.lede' },
  { name: 'higher-national-exams', title: 'nav.higherNationalExams', text: 'hx.lede' },
  { name: 'higher-tutelage', title: 'nav.higherTutelage', text: 'htu.lede' },
  { name: 'higher-dashboards', title: 'hd.title', text: 'hd.lede' },
  { name: 'higher-help', title: 'nav.higherHelp', text: 'hhp.lede' },
  { name: 'higher-del-portal', title: 'nav.higher.juryPortal', text: 'hdl.portal.lede' },
  { name: 'higher-my-grades', title: 'hg.me.title', text: 'hg.me.lede', roles: ['apprenant'] },
  { name: 'higher-del-my-results', title: 'nav.higher.myResults', text: 'hdl.mine.lede', roles: ['apprenant'] },
  { name: 'higher-my-internship', title: 'hs.m.title', text: 'hs.m.lede', roles: ['apprenant'] },
  { name: 'higher-my-vacations', title: 'hv.mine.title', text: 'hv.mine.lede' },
  { name: 'higher-portal-student', title: 'nav.higher.portalStudent', text: 'hi.por.lede', roles: ['apprenant'] },
  { name: 'higher-portal-guarantor', title: 'nav.higher.portalGuarantor', text: 'hi.por.lede', roles: ['parent'] },
];

const visibleCards = computed(() =>
  cards.filter((c) => router.hasRoute(c.name) && ((c.perms ?? (router.resolve({ name: c.name }).meta.perms as string[] | undefined)) ? ctx.can(...(c.perms ?? (router.resolve({ name: c.name }).meta.perms as string[]))) : true) && (c.roles ? c.roles.includes(ctx.activeRole ?? '') : true)),
);

onMounted(() => void higher.ensure());
</script>

<template>
  <HigherFrame :title="t('hi.home.title')" :lede="t('hi.home.lede')" section="home">
    <section v-if="!higher.visible && (higher.entitlementKnown || higher.me)" class="card card--strong" role="status" data-test="unavailable">
      <h2>{{ t('hi.unavailable.title') }}</h2>
      <p>{{ t('hi.unavailable.text') }}</p>
      <router-link class="btn" :to="{ name: 'applications' }">{{ t('hi.unavailable.cta') }} →</router-link>
    </section>
    <template v-else>
      <p v-if="visibleCards.length === 0" class="notice">{{ t('hi.noRole') }}</p>
      <ul class="grid grid--tiles hi-cards">
        <li v-for="c in visibleCards" :key="c.name">
          <article class="card hi-card">
            <h2>{{ t(c.title) }}</h2>
            <p class="muted">{{ t(c.text) }}</p>
            <router-link class="btn btn--quiet" :to="{ name: c.name }" :aria-label="`${t('hi.home.open')} : ${t(c.title)}`">{{ t('hi.home.open') }} →</router-link>
          </article>
        </li>
      </ul>
    </template>
  </HigherFrame>
</template>

<style scoped>
.hi-cards {
  list-style: none;
  padding: 0;
  margin: 0;
}
.hi-card {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.hi-card p {
  flex: 1;
}
</style>
