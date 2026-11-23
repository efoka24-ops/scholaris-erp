import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useI18n } from '@/i18n';

/**
 * Les actions sensibles exigent une « confirmation récente » : le code de double authentification pour qui en a une,
 * sinon le mot de passe (POST /auth/reconfirm). L'écran de destination (route `twofactor`) choisit tout seul ; ce composable
 * ne sert qu'à adapter les textes des bandeaux « Double authentification requise » pour qui n'a pas de 2FA.
 */
export function useConfirmation() {
  const auth = useAuthStore();
  const { t } = useI18n();
  /** Vrai : la personne confirmera par son mot de passe (elle n'a pas de double authentification). */
  const byPassword = computed(() => !!auth.me && !auth.me.two_factor.enabled);

  return {
    byPassword,
    /** Titre, texte ou bouton du bandeau : la version « mot de passe » si besoin, sinon le texte d'origine. */
    title: (original: string): string => (byPassword.value ? t('acc.confirm.noticeTitle') : original),
    text: (original: string): string => (byPassword.value ? t('acc.confirm.noticeText') : original),
    go: (original: string): string => (byPassword.value ? t('acc.confirm.go') : original),
  };
}
