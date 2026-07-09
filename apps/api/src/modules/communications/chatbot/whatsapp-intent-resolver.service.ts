import { Injectable, Logger } from "@nestjs/common";

/**
 * Chatbot WhatsApp à base de règles (§23.3 du guide) — PAS un LLM : reconnaissance de
 * quelques intentions fixes par mot-clé. Chaque résolveur d'intention est volontairement
 * un stub explicite tant que les modules métier correspondants (Finance, Notes, Calendrier)
 * n'existent pas — le point de branchement futur est indiqué en commentaire sur chacun.
 */
@Injectable()
export class WhatsappIntentResolverService {
  private readonly logger = new Logger(WhatsappIntentResolverService.name);

  /** Point d'entrée : reçoit le texte brut du message WhatsApp entrant et retourne la réponse à renvoyer. */
  resolveIntent(rawMessage: string): string {
    const normalized = rawMessage.trim().toLowerCase();

    if (normalized.includes("solde")) {
      return this.resolveBalanceIntent();
    }
    if (normalized.includes("notes")) {
      const matiereMatch = normalized.match(/notes\s+(.+)/);
      return this.resolveGradesIntent(matiereMatch?.[1]?.trim());
    }
    if (normalized.includes("calendrier")) {
      return this.resolveCalendarIntent();
    }

    this.logger.log(`Intention non reconnue pour le message : "${rawMessage}"`);
    return "Je n'ai pas compris votre demande. Tapez « solde », « notes [matière] » ou « calendrier ».";
  }

  /**
   * Intention "solde" — retournera le solde du compte une fois le Module Finance (§ non
   * encore construit) disponible. Branchement futur : injecter FinanceService et appeler
   * financeService.getBalance(userId) ici, en résolvant l'utilisateur à partir du numéro
   * WhatsApp entrant (wa_id) via une table de correspondance à créer.
   */
  resolveBalanceIntent(): string {
    return "Cette fonctionnalité (consultation du solde) sera disponible une fois le module Finance déployé.";
  }

  /**
   * Intention "notes [matière]" — retournera les notes/moyennes une fois le Module Notes
   * (moteur de calcul, § non encore construit) disponible. Branchement futur : injecter
   * un NotesService et appeler notesService.getGrades(userId, matiere).
   */
  resolveGradesIntent(matiere?: string): string {
    return matiere
      ? `Cette fonctionnalité (consultation des notes en ${matiere}) sera disponible une fois le module Notes déployé.`
      : "Cette fonctionnalité (consultation des notes) sera disponible une fois le module Notes déployé.";
  }

  /**
   * Intention "calendrier" — retournera les événements/échéances une fois un module de
   * planification d'établissement disponible. Branchement futur : injecter le service
   * correspondant (calendrier académique, événements) et interroger les prochaines échéances.
   */
  resolveCalendarIntent(): string {
    return "Cette fonctionnalité (consultation du calendrier) sera disponible prochainement.";
  }
}
