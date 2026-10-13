<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError, http } from '@/api/http';
import { formatDate } from '@/lib/format';
import { useI18n } from '@/i18n';

// Page publique de vérification d'un document (maquette Verification.html) : sans connexion, sans donnée personnelle de plus
// que le titulaire masqué. Trois états : authentique, révoqué, introuvable.
interface Verified {
  status: 'valid' | 'revoked';
  type: string;
  type_label: string;
  establishment: string;
  holder: string;
  year: number;
  number: string;
  issued_on: string;
  fingerprint: string;
  revoke_reason?: string;
  replaced_by_number?: string | null;
}

const props = defineProps<{ token?: string }>();
const { t } = useI18n();
const router = useRouter();

const typed = ref(props.token ?? '');
const loading = ref(false);
const result = ref<Verified | null>(null);
const notFound = ref(false);
const failure = ref<string | null>(null);

const known = computed(() => result.value !== null);

async function check(token: string): Promise<void> {
  const clean = token.trim();
  result.value = null;
  notFound.value = false;
  failure.value = null;
  if (clean === '') return;
  loading.value = true;
  try {
    const res = await http.get<{ data: Verified }>(`/public/verify/${encodeURIComponent(clean)}`, undefined, { establishmentId: false });
    result.value = res.data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true;
    else if (e instanceof ApiError && e.status === 429) failure.value = t('verify.tooMany');
    else failure.value = t('verify.error');
  } finally {
    loading.value = false;
  }
}

async function submit(): Promise<void> {
  const clean = typed.value.trim();
  if (clean === '') return;
  await router.replace({ name: 'verify', params: { token: clean } });
}

watch(
  () => props.token,
  (token) => {
    typed.value = token ?? '';
    void check(token ?? '');
  },
);
onMounted(() => void check(props.token ?? ''));
</script>

<template>
  <div class="verify">
    <header class="verify__top">
      <span class="logo-sq logo-sq--s" aria-hidden="true">S</span>
      <span class="verify__brand">SCHOLARIS</span>
      <span class="verify__tag">{{ t('verify.tag') }}</span>
    </header>

    <main class="verify__main" aria-live="polite">
      <form class="verify__form" novalidate @submit.prevent="submit">
        <label for="vf-token">{{ t('verify.tokenLabel') }}</label>
        <div class="verify__row">
          <input id="vf-token" v-model="typed" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" :placeholder="t('verify.tokenPlaceholder')" />
          <button type="submit" class="btn btn--primary" :disabled="loading || typed.trim() === ''">{{ t('verify.check') }}</button>
        </div>
      </form>

      <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

      <section v-if="result && result.status === 'valid'" class="state state--ok" role="status">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
        <div>
          <div class="state__title">{{ t('verify.valid') }}</div>
          <div class="state__sub">{{ t('verify.validSub') }}</div>
        </div>
      </section>

      <section v-else-if="result && result.status === 'revoked'" class="state state--revoked" role="status">
        <div>
          <div class="state__title">{{ t('verify.revoked') }}</div>
          <div class="state__sub">
            {{ t('verify.revokedReason', { reason: result.revoke_reason ?? '' }) }}
            <template v-if="result.replaced_by_number"> {{ t('verify.replacedBy', { number: result.replaced_by_number }) }}</template>
          </div>
        </div>
      </section>

      <section v-else-if="notFound" class="state state--unknown" role="alert">
        <div>
          <div class="state__title">{{ t('verify.unknown') }}</div>
          <div class="state__sub">{{ t('verify.unknownSub') }}</div>
        </div>
      </section>

      <p v-else-if="failure" class="bad" role="alert">{{ failure }}</p>

      <dl v-if="known && result" class="kv">
        <div><dt>{{ t('verify.document') }}</dt><dd>{{ result.type_label }}</dd></div>
        <div><dt>{{ t('verify.establishment') }}</dt><dd>{{ result.establishment }}</dd></div>
        <div><dt>{{ t('verify.holder') }}</dt><dd>{{ result.holder }}</dd></div>
        <div><dt>{{ t('verify.year') }}</dt><dd>{{ result.year }}</dd></div>
        <div><dt>{{ t('verify.number') }}</dt><dd class="mono">{{ result.number }}</dd></div>
        <div><dt>{{ t('verify.issuedOn') }}</dt><dd>{{ formatDate(result.issued_on) }}</dd></div>
        <div><dt>{{ t('verify.fingerprint') }}</dt><dd class="mono">{{ result.fingerprint }}</dd></div>
      </dl>

      <section v-if="known" class="compare">
        <b>{{ t('verify.compareTitle') }}</b>
        <span>{{ t('verify.compareText') }}</span>
      </section>
    </main>

    <footer class="verify__foot">{{ t('verify.footer') }}</footer>
  </div>
</template>

<style scoped>
.verify {
  max-width: 480px;
  margin: 0 auto;
  min-height: 100vh;
  background: var(--bg, #f4f4f1);
  display: flex;
  flex-direction: column;
}
.verify__top {
  background: var(--ink, #000);
  color: #fff;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.verify__brand {
  font-weight: 800;
  letter-spacing: 0.04em;
}
.verify__tag {
  margin-left: auto;
  font-size: 13px;
  color: #bdbdbd;
}
.verify__main {
  flex: 1;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.verify__form label {
  display: block;
  font-weight: 700;
  margin-bottom: 6px;
}
.verify__row {
  display: flex;
  gap: 8px;
}
.verify__row input {
  flex: 1;
  min-width: 0;
  min-height: 48px;
  padding: 0 12px;
  border: 1px solid var(--border-strong, #8a8a8a);
  font: 500 15px var(--font);
}
.state {
  color: #fff;
  padding: 20px;
  display: flex;
  gap: 14px;
  align-items: center;
}
.state--ok {
  background: #0a7a3e;
}
.state--revoked {
  background: #b34700;
}
.state--unknown {
  background: #3d3d3d;
}
.state__title {
  font-size: 22px;
  font-weight: 800;
}
.state__sub {
  font-size: 14px;
  margin-top: 2px;
}
.kv {
  margin: 0;
  background: #fff;
  border: 1px solid var(--border, #dcdcd8);
  padding: 4px 16px 8px;
}
.kv > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--border, #dcdcd8);
}
.kv > div:first-child {
  border-top: 0;
}
.kv dt {
  color: #595959;
}
.kv dd {
  margin: 0;
  font-weight: 700;
  text-align: right;
  overflow-wrap: anywhere;
}
.mono {
  font-family: monospace;
}
.compare {
  background: #fff;
  border: 1px solid var(--border, #dcdcd8);
  padding: 16px;
  font-size: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.bad {
  color: var(--warning, #b34700);
  font-weight: 700;
}
.muted {
  color: var(--text-2, #3d3d3d);
}
.verify__foot {
  padding: 14px 20px;
  font-size: 12px;
  color: #595959;
  border-top: 1px solid var(--border, #dcdcd8);
}
</style>
