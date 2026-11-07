<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import { TEACHING_SCREENS } from './routes';
import { useHt } from './i18n';

// Cadre des écrans « Enseignement et présences » : HigherFrame (titre, sélecteur « École », lecture seule) et sous-navigation de la
// rubrique. HigherFrame n'a pas encore de section « teaching » : on lui passe une section neutre et on affiche nos propres liens.
const props = withDefaults(defineProps<{ title: string; lede?: string; group?: 'teaching' | 'attendance' }>(), { lede: '', group: 'teaching' });
const emit = defineEmits<{ (e: 'school-change', id: string): void }>();
const { t } = useHt();
const ctx = useContextStore();
const router = useRouter();

const links = computed(() =>
  TEACHING_SCREENS.filter(([path, name, , perms]) => path.startsWith(props.group === 'teaching' ? 'enseignement/' : 'presences/') && router.hasRoute(name) && ctx.can(...perms)),
);
</script>

<template>
  <HigherFrame :title="title" :lede="lede" section="curriculum" @school-change="(id: string) => emit('school-change', id)">
    <template #actions><slot name="actions" /></template>
    <nav v-if="links.length > 1" class="ht-subnav" :aria-label="t(group === 'teaching' ? 'nav.higher.teaching' : 'nav.higher.attendance')">
      <router-link v-for="[, name, , , label] in links" :key="name" :to="{ name }" class="ht-subnav__link">{{ t(label) }}</router-link>
    </nav>
    <slot />
  </HigherFrame>
</template>

<style scoped>
.ht-subnav {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin-bottom: 20px;
  padding-bottom: 4px;
}
.ht-subnav__link {
  display: inline-flex;
  align-items: center;
  flex: none;
  min-height: var(--tap);
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  color: var(--ink);
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
}
.ht-subnav__link.router-link-exact-active {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
</style>
