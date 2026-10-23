<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { documents, type DocType, type IssuedDoc } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import PersonPicker from '../PersonPicker.vue';
import { P } from '../access';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { useXt } from '../xi18n';
import IssuedRow from './IssuedRow.vue';

// Documents officiels de l'IPES (DOC) : catalogue, émission (numéro unique et QR), registre, duplicata, annulation motivée et
// vérification publique. Un document bloqué par un impayé ou une émission refusée s'affiche en erreur RFC 9457.
const { t, tv, locale } = useXt();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const load = useHigherAction();
const act = useHigherAction();

const catalog = ref<DocType[]>([]);
const items = ref<IssuedDoc[]>([]);
const issued = ref<IssuedDoc | null>(null);
const filter = reactive({ code: '', status: '' });
const form = reactive({ code: '', person: null as string | null, school: '', locale: '' as '' | 'fr' | 'en', tried: false });
const token = ref('');
const { label } = useHigherNames(() => items.value.map((d) => d.person_id));

const canIssue = computed(() => ctx.can(...P.documentIssue) && !higher.readOnly);
const issuable = computed(() => catalog.value.filter((d) => !d.issued_by_socle));
const schools = computed(() => (higher.schools.length ? higher.schools : higher.selectableSchools));
const typeLabel = (d: DocType): string => (locale.value === 'en' ? d.label_en : d.label_fr);
const codeLabel = (code: string): string => {
  const d = catalog.value.find((x) => x.code === code);
  return d ? `${code} · ${typeLabel(d)}` : code;
};

async function fetchRegister(): Promise<void> {
  const q = { ...higher.scope(), ...(filter.code ? { code: filter.code } : {}), ...(filter.status ? { status: filter.status } : {}) };
  const res = await load.run(() => documents.list(q));
  if (res) items.value = res;
}

async function issue(): Promise<void> {
  form.tried = true;
  if (!form.code || !form.person || !form.school) return;
  const res = await act.run(() => documents.issue({ code: form.code, person_id: form.person as string, school_id: form.school, ...(form.locale ? { locale: form.locale } : {}) }));
  if (!res) return;
  issued.value = res;
  form.tried = false;
  await fetchRegister();
}

function replace(doc: IssuedDoc): void {
  items.value = items.value.map((d) => (d.id === doc.id ? { ...d, ...doc } : d));
}

async function verify(): Promise<void> {
  const v = token.value.trim();
  if (v) await router.push({ name: 'verify', params: { token: v } });
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('schools');
  if (higher.scopedSchoolId) form.school = higher.scopedSchoolId;
  const cat = await load.run(() => documents.catalog());
  if (cat) catalog.value = cat;
  await fetchRegister();
});
</script>

<template>
  <HigherFrame :title="t('hi.doc.title')" :lede="t('hi.doc.lede')" section="documents" @school-change="fetchRegister">
    <ErrorNotice :error="load.error.value" />

    <section class="hi-section" aria-labelledby="dc-cat">
      <h2 id="dc-cat">{{ t('hi.doc.catalog') }}</h2>
      <div class="table-wrap">
        <table data-test="catalog">
          <thead><tr><th scope="col">{{ t('hi.doc.type') }}</th><th scope="col">{{ t('hi.doc.signer') }}</th><th scope="col">{{ t('hi.doc.qr') }}</th><th scope="col">{{ t('hi.doc.blockable') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in catalog" :key="d.code">
              <th scope="row"><span class="hi-mono">{{ d.code }}</span> {{ typeLabel(d) }}<span v-if="d.issued_by_socle" class="muted hi-small"> · {{ t('hi.doc.socle') }}</span></th>
              <td>{{ d.signer_role ?? '—' }}</td>
              <td>{{ d.qr ? '✓' : '—' }}</td>
              <td><span class="tag" :class="d.blockable ? 'tag--warning' : 'tag--success'">{{ d.blockable ? t('hi.doc.blockable') : t('hi.doc.neverBlocked') }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <form v-if="canIssue" class="card card--strong hi-section" novalidate data-test="issue" @submit.prevent="issue">
      <h2>{{ t('hi.doc.issueTitle') }}</h2>
      <div class="hi-form">
        <div class="field">
          <label for="dc-code">{{ t('hi.doc.type') }}</label>
          <select id="dc-code" v-model="form.code" :aria-invalid="form.tried && !form.code"><option value="">—</option><option v-for="d in issuable" :key="d.code" :value="d.code">{{ d.code }} · {{ typeLabel(d) }}</option></select>
          <span v-if="form.tried && !form.code" class="error">{{ t('hx.doc.chooseType') }}</span>
        </div>
        <div class="field">
          <label for="dc-school">{{ t('hx.doc.school') }}</label>
          <select id="dc-school" v-model="form.school" :aria-invalid="form.tried && !form.school"><option value="">—</option><option v-for="s in schools" :key="s.id" :value="s.id">{{ s.code ? `${s.code} · ${s.name}` : s.name }}</option></select>
          <span v-if="form.tried && !form.school" class="error">{{ t('hx.doc.chooseSchool') }}</span>
        </div>
        <div class="field">
          <label for="dc-locale">{{ t('hi.doc.locale') }}</label>
          <select id="dc-locale" v-model="form.locale"><option value="">{{ t('hi.doc.localeDefault') }}</option><option value="fr">Français</option><option value="en">English</option></select>
        </div>
        <div class="hi-wide">
          <PersonPicker id="dc-person" v-model="form.person" :label="t('hi.doc.holder')" />
          <span v-if="form.tried && !form.person" class="error">{{ t('hx.doc.choosePerson') }}</span>
        </div>
      </div>
      <ErrorNotice :error="act.error.value" />
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.doc.issueBtn') }}</button>
      <p v-if="issued" class="notice notice--success hi-section" role="status" data-test="issued">
        {{ t('hx.doc.issued', { number: issued.number }) }} · <router-link :to="{ name: 'verify', params: { token: issued.verification_token } }">{{ t('hi.doc.verifyLink') }}</router-link>
      </p>
    </form>

    <section class="hi-section" aria-labelledby="dc-reg">
      <h2 id="dc-reg">{{ t('hi.doc.register') }}</h2>
      <div class="hi-form">
        <div class="field"><label for="dc-fcode">{{ t('hx.doc.filterCode') }}</label><select id="dc-fcode" v-model="filter.code" @change="fetchRegister"><option value="">{{ t('hx.doc.all') }}</option><option v-for="d in catalog" :key="d.code" :value="d.code">{{ d.code }} · {{ typeLabel(d) }}</option></select></div>
        <div class="field"><label for="dc-fstatus">{{ t('hx.doc.filterStatus') }}</label><select id="dc-fstatus" v-model="filter.status" @change="fetchRegister"><option value="">{{ t('hx.doc.all') }}</option><option v-for="s in ['valid', 'cancelled', 'replaced']" :key="s" :value="s">{{ tv('hi.doc.st.', s) }}</option></select></div>
      </div>
      <p v-if="items.length === 0" class="muted">{{ t('hx.doc.empty') }}</p>
      <ul v-else class="dc-register" data-test="register">
        <IssuedRow v-for="d in items" :key="d.id" :doc="d" :type-label="codeLabel(d.code)" :holder="label(d.person_id)" :tone="toneOf(d.status) ?? 'neutral'" @changed="replace" />
      </ul>
    </section>

    <form class="card hi-section" novalidate data-test="verify" @submit.prevent="verify">
      <h2>{{ t('hx.doc.verifyTitle') }}</h2>
      <p class="muted hi-small">{{ t('hx.doc.verifyText') }}</p>
      <div class="field"><label for="dc-token">{{ t('hx.doc.token') }}</label><input id="dc-token" v-model="token" type="text" autocomplete="off" /></div>
      <button type="submit" class="btn" :disabled="!token.trim()">{{ t('hx.doc.verifyBtn') }}</button>
    </form>
  </HigherFrame>
</template>

<style scoped>
.dc-register {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}
</style>
