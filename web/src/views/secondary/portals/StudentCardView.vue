<script setup lang="ts">
// Fiche d'un élève du secondaire dans un portail : inscription, matricule enregistré ou en attente (jamais sa valeur), solde,
// documents avec lien de vérification publique. Composant d'affichage seulement (aucun appel).
import type { StudentCard } from '@/api/secondaryPortals';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';

defineProps<{ card: StudentCard }>();
const { t, locale } = useI18n();
const verifyLink = (token: string): string => `/verification/${encodeURIComponent(token)}`;
</script>

<template>
  <article class="card" :aria-label="card.person.name">
    <h2>{{ card.person.name }}</h2>
    <p>
      <span v-if="card.enrollment.class">{{ card.enrollment.class.name }}</span>
      <span v-if="card.enrollment.level"> · {{ card.enrollment.level.name }}</span>
      · {{ t('sec.portal.year', { year: card.enrollment.academic_year }) }}
    </p>
    <p>
      <span class="tag" :class="card.national_id_registered ? 'tag--success' : 'tag--warning'">
        {{ card.national_id_registered ? t('sec.portal.nationalIdOk') : t('sec.portal.nationalIdPending') }}
      </span>
    </p>
    <p>{{ t('sec.portal.balance') }} : <strong>{{ formatXaf(card.balance_xaf, locale) }}</strong></p>
    <p v-if="card.access === 'restricted'" class="muted">{{ t('sec.portal.restricted') }}</p>
    <template v-if="card.documents">
      <h3>{{ t('sec.portal.documents') }}</h3>
      <p v-if="card.documents.blocked" class="muted">{{ t('sec.portal.documentsBlocked') }}</p>
      <p v-else-if="card.documents.items.length === 0" class="muted">{{ t('sec.portal.noDocument') }}</p>
      <ul v-else class="rows">
        <li v-for="d in card.documents.items" :key="d.id">
          <strong>{{ d.doc }}</strong> · {{ d.number }}
          <a v-if="d.qr" :href="verifyLink(d.verification_token)" target="_blank" rel="noopener">{{ t('sec.docs.verify') }}</a>
        </li>
      </ul>
    </template>
    <slot />
  </article>
</template>

<style scoped>
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
</style>
