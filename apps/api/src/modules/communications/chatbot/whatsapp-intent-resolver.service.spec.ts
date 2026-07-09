import { WhatsappIntentResolverService } from "./whatsapp-intent-resolver.service";

describe("WhatsappIntentResolverService", () => {
  let service: WhatsappIntentResolverService;

  beforeEach(() => {
    service = new WhatsappIntentResolverService();
  });

  it("reconnaît l'intention 'solde' (insensible à la casse) et retourne le stub Finance", () => {
    expect(service.resolveIntent("Quel est mon SOLDE ?")).toContain("module Finance");
  });

  it("reconnaît l'intention 'notes [matière]' et retourne le stub Notes avec la matière extraite", () => {
    expect(service.resolveIntent("notes mathématiques")).toContain("mathématiques");
  });

  it("reconnaît l'intention 'calendrier'", () => {
    expect(service.resolveIntent("calendrier")).toContain("calendrier");
  });

  it("retourne un message d'aide par défaut pour une intention non reconnue", () => {
    expect(service.resolveIntent("bonjour")).toContain("Je n'ai pas compris");
  });
});
