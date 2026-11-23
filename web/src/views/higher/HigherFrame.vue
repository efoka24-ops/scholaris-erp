<script setup lang="ts">
import { computed, onMounted } from 'vue';
import './higher.css';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { ALL_SCHOOLS, useHigherStore } from './store';
import { P } from './access';
import { useRouter } from 'vue-router';

// Cadre commun des écrans IPES (maquettes Frais.html et Inscription.html) : surtitre, titre, sélecteur « École » (RG-ORG-3),
// bandeau de lecture seule (abonnement suspendu) et sections de la rubrique courante.
export type HigherSection = 'home' | 'academic' | 'curriculum' | 'admissions' | 'enrolment' | 'finance' | 'documents' | 'portal';

const props = withDefaults(defineProps<{ title: string; lede?: string; section?: HigherSection; selector?: boolean }>(), { lede: '', section: 'home', selector: true });
const emit = defineEmits<{ (e: 'school-change', id: string): void }>();
const { t } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();

const SECTIONS: Record<HigherSection, Array<{ name: string; label: MessageKey; perms: readonly string[] }>> = {
  home: [],
  portal: [],
  academic: [
    { name: 'higher-structure', label: 'hi.aca.title', perms: P.settingsRead },
    { name: 'higher-authorizations', label: 'hi.auth.title', perms: P.registersRead },
    { name: 'higher-tutelages', label: 'hi.tut.title', perms: P.registersRead },
    { name: 'higher-regulations', label: 'hi.reg.title', perms: P.registersRead },
    { name: 'higher-calendar', label: 'hi.cal.title', perms: P.registersRead },
  ],
  curriculum: [{ name: 'higher-curricula', label: 'hi.cur.title', perms: P.curriculumRead }],
  admissions: [
    { name: 'higher-campaigns', label: 'hi.adm.title', perms: P.admissionRead },
    { name: 'higher-applications', label: 'hi.app.title', perms: P.admissionRead },
  ],
  enrolment: [{ name: 'higher-enrolments', label: 'hi.ins.title', perms: P.enrolmentRead }],
  finance: [
    { name: 'higher-fee-grids', label: 'hi.fin.gridsTitle', perms: P.billingRead },
    { name: 'higher-accounts', label: 'hi.fin.accountsTitle', perms: P.billingRead },
    { name: 'higher-sponsors', label: 'hi.fin.sponsorsTitle', perms: P.billingRead },
  ],
  documents: [
    { name: 'higher-documents', label: 'hi.doc.title', perms: P.documentRead },
    { name: 'higher-school-profile', label: 'hi.doc.profileTitle', perms: P.documentRead },
  ],
};

const links = computed(() => SECTIONS[props.section].filter((s) => router.hasRoute(s.name) && ctx.can(...s.perms)));

function onSchool(event: Event): void {
  const id = (event.target as HTMLSelectElement).value;
  higher.selectSchool(id);
  emit('school-change', id);
}

onMounted(() => void higher.ensure());
</script>

<template>
  <div class="page hi-page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('hi.eyebrow') }}</p>
        <h1>{{ title }}</h1>
        <p v-if="lede" class="lede">{{ lede }}</p>
      </div>
      <div class="hi-actions">
        <label v-if="selector && higher.showSelector" class="hi-school">
          <span class="label">{{ t('hi.school.label') }}</span>
          <select id="hi-school" :value="higher.selectedSchool" :aria-describedby="'hi-school-hint'" @change="onSchool">
            <option :value="ALL_SCHOOLS">{{ t('hi.school.all') }}</option>
            <option v-for="s in higher.selectableSchools" :key="s.id" :value="s.id">{{ s.code ? `${s.code} · ${s.name}` : s.name }}</option>
          </select>
          <span id="hi-school-hint" class="sr-only">{{ t('hi.school.hint') }}</span>
        </label>
        <slot name="actions" />
      </div>
    </header>

    <div v-if="higher.readOnly" class="notice notice--warning" role="status" data-test="readonly-banner">
      <p><strong>{{ t('hi.readOnly.title') }}</strong></p>
      <p>{{ t('hi.readOnly.text') }}</p>
    </div>

    <nav v-if="links.length > 1" class="hi-subnav" :aria-label="t('hi.subnav')">
      <router-link :to="{ name: 'higher' }" class="hi-subnav__link">← {{ t('nav.higher') }}</router-link>
      <router-link v-for="l in links" :key="l.name" :to="{ name: l.name }" class="hi-subnav__link">{{ t(l.label) }}</router-link>
    </nav>
    <router-link v-else-if="section !== 'home'" :to="{ name: 'higher' }" class="hi-subnav__back">← {{ t('nav.higher') }}</router-link>

    <ErrorNotice v-if="higher.meError && section !== 'portal'" :error="higher.meError" />
    <slot />
  </div>
</template>

<style scoped>
.hi-school {
  display: flex;
  flex-direction: column;
  min-width: 220px;
}
.hi-subnav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 24px;
}
.hi-subnav__link {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  color: var(--ink);
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
}
.hi-subnav__link.router-link-exact-active {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.hi-subnav__back {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  font-weight: 700;
  margin-bottom: 16px;
}
</style>
