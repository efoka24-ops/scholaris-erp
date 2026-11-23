/**
 * Copie un texte : API Clipboard si disponible (contexte sécurisé), sinon repli par sélection d'une zone de texte
 * temporaire et `execCommand('copy')`. Renvoie faux si aucune méthode n'a fonctionné (la personne copie à la main).
 */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    /* refus ou contexte non sécurisé : repli ci-dessous */
  }
  try {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    const ok = typeof document.execCommand === 'function' && document.execCommand('copy');
    document.body.removeChild(area);
    return ok;
  } catch {
    return false;
  }
}
