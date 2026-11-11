<script setup lang="ts">
// Bulletin d'un élève du secondaire (lot E3-A, T904) : aperçu des variables produites pour le modèle (tableau des matières avec
// SEQ/TRIM, récapitulatif, profil de la classe, discipline, travail, décision) et instantané enregistré à l'émission. Rendu en
// texte uniquement (aucun v-html) : chaque tableau JSON est relu puis affiché cellule par cellule.
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { REPORT_TABLES, cellText, parseDocTable, rowCells, secondaryCouncils, type DocTableCell, type ReportCardPreview } from '@/api/secondaryCouncils';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction } from '../useSec';

const { t } = useI18n();
const route = useRoute();
const { error, busy, run } = useAction();
const preview = ref<ReportCardPreview | null>(null);
const loaded = ref(false);
const locale = ref<'fr' | 'en'>('fr');

const councilId = computed(() => (typeof route.query.council === 'string' ? route.query.council : ''));
const personId = computed(() => (typeof route.query.person === 'string' ? route.query.person : ''));
const tables = computed(() => REPORT_TABLES.map((name) => ({ name, table: parseDocTable(preview.value?.variables[name]) })).filter((x) => x.table !== null));
const v = (name: string): string => String(preview.value?.variables[name] ?? '');

function span(cell: string | number | DocTableCell | null, key: 'colspan' | 'rowspan'): number | undefined {
  return cell !== null && typeof cell === 'object' ? cell[key] : undefined;
}

async function load(): Promise<void> {
  preview.value = null;
  loaded.value = false;
  if (councilId.value === '' || personId.value === '') return;
  preview.value = (await run(() => secondaryCouncils.preview(councilId.value, personId.value, locale.value))) ?? null;
  loaded.value = true;
}

watch([councilId, personId, locale], load, { immediate: true });
</script>

<template>
  <div class="page sec-report-card">
    <header class="page-head">
      <h1>{{ t('secb.title') }}</h1>
      <p class="lede">{{ t('secb.intro') }}</p>
      <RouterLink class="link" :to="{ name: 'sec-councils' }">{{ t('secb.back') }}</RouterLink>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="!councilId || !personId" class="card muted">{{ t('secb.missing') }}</p>

    <template v-else-if="preview">
      <section class="card head">
        <label class="field">{{ t('secb.locale') }}
          <select v-model="locale" :disabled="busy"><option value="fr">Français</option><option value="en">English</option></select>
        </label>
        <p><strong>{{ t('secb.student') }} :</strong> {{ v('secondary_last_name') }} {{ v('secondary_first_names') }} · {{ v('secondary_class_name') }} · {{ v('secondary_academic_year') }}</p>
        <p v-if="v('secondary_matricule')"><strong>{{ t('secb.matricule') }} :</strong> {{ v('secondary_matricule') }}</p>
        <p class="muted">{{ preview.snapshot ? t('secb.snapshot', { date: preview.snapshot.built_at }) : t('secb.noSnapshot') }}</p>
      </section>

      <section v-for="item in tables" :key="item.name" class="card">
        <h2>{{ t(`secb.table.${item.name}` as MessageKey) }}</h2>
        <div class="table-wrap">
          <table class="table doc">
            <thead v-if="item.table?.head">
              <tr v-for="(hrow, h) in item.table.head" :key="h">
                <th v-for="(cell, c) in hrow" :key="c" :colspan="span(cell, 'colspan')" :rowspan="span(cell, 'rowspan')">{{ cellText(cell) }}</th>
              </tr>
            </thead>
            <thead v-else-if="item.table?.columns.some((col) => col.label !== '')">
              <tr><th v-for="(col, c) in item.table.columns" :key="c">{{ col.label }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, r) in item.table?.rows ?? []" :key="r" :class="rowCells(row).kind">
                <td v-for="(cell, c) in rowCells(row).cells" :key="c" :colspan="span(cell, 'colspan')" class="cell">{{ cellText(cell) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card">
        <p v-if="v('secondary_council_decision')"><strong>{{ t('secb.decision') }} :</strong> {{ v('secondary_council_decision') }}</p>
        <p v-if="v('secondary_observations')"><strong>{{ t('secb.observations') }} :</strong> {{ v('secondary_observations') }}</p>
      </section>
    </template>
    <p v-else-if="!loaded" class="card muted" role="status">{{ t('secb.loading') }}</p>
    <p v-else-if="!error" class="card muted">{{ t('secb.empty') }}</p>
  </div>
</template>

<style scoped>
.card { margin-top: 16px; }
.field { display: grid; gap: 6px; max-width: 220px; }
.table-wrap { overflow-x: auto; }
.doc th, .doc td { border: 1px solid var(--border, #d8dee8); padding: 4px 6px; text-align: center; }
.doc .cell { white-space: pre-line; }
.doc td:first-child { text-align: left; }
.doc tr.group td { background: var(--surface-muted, #f1f4f8); font-weight: 600; text-align: left; }
.doc tr.total td { background: var(--surface-muted, #eef1f6); font-weight: 600; }
</style>
