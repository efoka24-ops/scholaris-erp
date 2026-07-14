import { DEFAULT_CALCULATION_ENGINE_CONFIG, type CalculationEngineConfig } from "@scholaris/shared";
import { CalculationEngineService } from "./calculation-engine.service";

describe("CalculationEngineService", () => {
  let engine: CalculationEngineService;

  beforeEach(() => {
    engine = new CalculationEngineService();
  });

  describe("handleAbsence", () => {
    it("renvoie la valeur telle quelle si l'élève n'est pas absent", () => {
      expect(engine.handleAbsence({ value: 14, isAbsent: false, isJustified: false }, "ZERO")).toBe(14);
    });

    it("règle ZERO : une absence (justifiée ou non) vaut 0", () => {
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: false }, "ZERO")).toBe(0);
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: true }, "ZERO")).toBe(0);
    });

    it("règle NEUTRALIZED : l'évaluation est toujours exclue (null)", () => {
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: false }, "NEUTRALIZED")).toBeNull();
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: true }, "NEUTRALIZED")).toBeNull();
    });

    it("règle POSTPONED : absence justifiée neutralisée, non justifiée = 0", () => {
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: true }, "POSTPONED")).toBeNull();
      expect(engine.handleAbsence({ value: null, isAbsent: true, isJustified: false }, "POSTPONED")).toBe(0);
    });
  });

  describe("calculateWeightedAverage", () => {
    it("calcule Σ(valeur×poids)/Σ(poids)", () => {
      // 12×1 + 15×2 + 18×1 = 12+30+18 = 60 ; poids total = 4 ; 60/4 = 15
      const result = engine.calculateWeightedAverage([
        { value: 12, weight: 1 },
        { value: 15, weight: 2 },
        { value: 18, weight: 1 },
      ]);
      expect(result).toBe(15);
    });

    it("renvoie null si le poids total est nul (aucune évaluation exploitable)", () => {
      expect(engine.calculateWeightedAverage([])).toBeNull();
      expect(engine.calculateWeightedAverage([{ value: 10, weight: 0 }])).toBeNull();
    });
  });

  describe("calculateSubjectAverage / calculateSequential", () => {
    it("cas de référence : notes 12/15/18 avec poids 1/2/1 → moyenne 15", () => {
      const grades = [
        { value: 12, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
        { value: 15, maxValue: 20, weight: 2, isAbsent: false, isJustified: false },
        { value: 18, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
      ];
      expect(engine.calculateSubjectAverage(grades, "ZERO")).toBe(15);
      expect(engine.calculateSequential(grades, DEFAULT_CALCULATION_ENGINE_CONFIG)).toBe(15);
    });

    it("ramène une note hors barème /20 sur 20 avant pondération", () => {
      // 8/10 → 16/20 ; 16/20 → 16/20. Poids égaux (1,1) → moyenne = 16.
      const grades = [
        { value: 8, maxValue: 10, weight: 1, isAbsent: false, isJustified: false },
        { value: 16, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
      ];
      expect(engine.calculateSubjectAverage(grades, "ZERO")).toBe(16);
    });

    it("applique la règle d'absence NEUTRALIZED en excluant la note absente du calcul", () => {
      // Note 1 : 10/20 poids 1 (présent). Note 2 : absent poids 1 → exclue.
      // Moyenne = 10/1 = 10 (pas 5).
      const grades = [
        { value: 10, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
        { value: null, maxValue: 20, weight: 1, isAbsent: true, isJustified: true },
      ];
      expect(engine.calculateSubjectAverage(grades, "NEUTRALIZED")).toBe(10);
    });

    it("applique la règle d'absence ZERO en comptant l'absence comme 0", () => {
      // Note 1 : 10/20 poids 1. Note 2 : absent (=0) poids 1. Moyenne = (10+0)/2 = 5.
      const grades = [
        { value: 10, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
        { value: null, maxValue: 20, weight: 1, isAbsent: true, isJustified: false },
      ];
      expect(engine.calculateSubjectAverage(grades, "ZERO")).toBe(5);
    });

    it("renvoie null si toutes les notes sont exclues", () => {
      const grades = [{ value: null, maxValue: 20, weight: 1, isAbsent: true, isJustified: true }];
      expect(engine.calculateSubjectAverage(grades, "NEUTRALIZED")).toBeNull();
    });
  });

  describe("calculateTrimester", () => {
    it("combine deux moyennes de séquences de poids égal (1,1)", () => {
      // Séquence 1 = 12, Séquence 2 = 16, poids égaux → (12+16)/2 = 14
      const result = engine.calculateTrimester([12, 16], DEFAULT_CALCULATION_ENGINE_CONFIG);
      expect(result).toBe(14);
    });

    it("respecte des pondérations différentes issues de la configuration", () => {
      const config: CalculationEngineConfig = { ...DEFAULT_CALCULATION_ENGINE_CONFIG, sequenceWeights: [1, 2] };
      // (10×1 + 16×2) / 3 = (10+32)/3 = 14
      const result = engine.calculateTrimester([10, 16], config);
      expect(result).toBe(14);
    });

    it("ignore les séquences sans moyenne (null)", () => {
      const result = engine.calculateTrimester([12, null], DEFAULT_CALCULATION_ENGINE_CONFIG);
      expect(result).toBe(12);
    });
  });

  describe("calculateGeneralAverage", () => {
    it("pondère chaque moyenne de matière par son coefficient", () => {
      // Maths 14×coeff4 + Français 12×coeff3 + Anglais 16×coeff2
      // = 56+36+32 = 124 ; Σcoeff = 9 ; 124/9 = 13.777...
      const result = engine.calculateGeneralAverage([
        { average: 14, coefficient: 4 },
        { average: 12, coefficient: 3 },
        { average: 16, coefficient: 2 },
      ]);
      expect(result).toBeCloseTo(13.7778, 4);
    });

    it("exclut les matières sans moyenne (non évaluées)", () => {
      const result = engine.calculateGeneralAverage([
        { average: 10, coefficient: 2 },
        { average: null, coefficient: 5 },
      ]);
      expect(result).toBe(10);
    });
  });

  describe("calculateAnnual", () => {
    it("moyenne annuelle sur 3 trimestres de poids égal", () => {
      // (12+14+16)/3 = 14
      expect(engine.calculateAnnual([12, 14, 16], DEFAULT_CALCULATION_ENGINE_CONFIG)).toBe(14);
    });

    it("respecte des pondérations trimestrielles différentes", () => {
      const config: CalculationEngineConfig = { ...DEFAULT_CALCULATION_ENGINE_CONFIG, trimesterWeights: [1, 1, 2] };
      // (10×1 + 10×1 + 16×2) / 4 = (10+10+32)/4 = 13
      expect(engine.calculateAnnual([10, 10, 16], config)).toBe(13);
    });
  });

  describe("calculateECNote / applyResit / calculateUEAverage", () => {
    it("Note_EC = CC×Pcc + Exam×Pex", () => {
      // CC=12, Exam=16, Pcc=0.4, Pex=0.6 → 12×0.4 + 16×0.6 = 4.8+9.6 = 14.4
      expect(engine.calculateECNote(12, 16, 0.4, 0.6)).toBeCloseTo(14.4, 5);
    });

    it("conserve la meilleure note entre session normale et rattrapage", () => {
      expect(engine.applyResit(9, 12)).toBe(12); // rattrapage meilleur
      expect(engine.applyResit(14, 8)).toBe(14); // session normale meilleure
      expect(engine.applyResit(11, null)).toBe(11); // pas de rattrapage
    });

    it("Moy_UE = Σ(Note_EC×crédits)/Σ(crédits)", () => {
      // EC1 : note 14, 3 crédits ; EC2 : note 10, 2 crédits
      // (14×3 + 10×2)/(3+2) = (42+20)/5 = 62/5 = 12.4
      const result = engine.calculateUEAverage([
        { note: 14, credits: 3 },
        { note: 10, credits: 2 },
      ]);
      expect(result).toBe(12.4);
    });
  });

  describe("calculateLMD", () => {
    const config: CalculationEngineConfig = { ...DEFAULT_CALCULATION_ENGINE_CONFIG, evaluationType: "LMD" };

    it("valide une UE dont la moyenne pondérée par les crédits atteint le seuil", () => {
      // EC1: CC=14 Exam=16 (0.4/0.6) → 14×0.4+16×0.6=5.6+9.6=15.2, 3 crédits
      // EC2: CC=8  Exam=9  (0.4/0.6) → 8×0.4+9×0.6=3.2+5.4=8.6, 2 crédits
      // Moy_UE = (15.2×3 + 8.6×2)/5 = (45.6+17.2)/5 = 62.8/5 = 12.56 ≥ 10 → validée
      const result = engine.calculateLMD(
        [
          {
            ueId: "ue-1",
            elements: [
              { ecId: "ec-1", credits: 3, ccScore: 14, examScore: 16, ccWeight: 0.4, examWeight: 0.6 },
              { ecId: "ec-2", credits: 2, ccScore: 8, examScore: 9, ccWeight: 0.4, examWeight: 0.6 },
            ],
          },
        ],
        config,
        10,
      );
      expect(result.units[0].average).toBeCloseTo(12.56, 2);
      expect(result.units[0].validated).toBe(true);
      expect(result.creditsEarned).toBe(5);
      expect(result.creditsTotal).toBe(5);
    });

    it("rattrapage : conserve la meilleure note pour valider une UE initialement sous le seuil", () => {
      // EC1: CC=6 Exam=8 (0.4/0.6) → 2.4+4.8=7.2 → rattrapage 15 → conserve 15 (meilleure), 4 crédits
      const result = engine.calculateLMD(
        [
          {
            ueId: "ue-1",
            elements: [{ ecId: "ec-1", credits: 4, ccScore: 6, examScore: 8, resitScore: 15, ccWeight: 0.4, examWeight: 0.6 }],
          },
        ],
        config,
        10,
      );
      expect(result.units[0].elements[0].note).toBe(15);
      expect(result.units[0].validated).toBe(true);
    });

    it("sans compensation, une UE sous le seuil n'est pas validée même si la moyenne générale l'atteint", () => {
      const noCompensation: CalculationEngineConfig = { ...config, lmdCompensation: false };
      const result = engine.calculateLMD(
        [
          { ueId: "ue-faible", elements: [{ ecId: "ec-1", credits: 3, ccScore: 6, examScore: 6, ccWeight: 0.5, examWeight: 0.5 }] },
          { ueId: "ue-forte", elements: [{ ecId: "ec-2", credits: 3, ccScore: 18, examScore: 18, ccWeight: 0.5, examWeight: 0.5 }] },
        ],
        noCompensation,
        10,
      );
      const weak = result.units.find((u) => u.ueId === "ue-faible")!;
      expect(weak.average).toBe(6);
      expect(weak.validated).toBe(false);
      expect(result.creditsEarned).toBe(3); // seule ue-forte validée
    });

    it("avec compensation activée, une UE sous le seuil est validée si la moyenne générale l'atteint", () => {
      const withCompensation: CalculationEngineConfig = { ...config, lmdCompensation: true };
      const result = engine.calculateLMD(
        [
          { ueId: "ue-faible", elements: [{ ecId: "ec-1", credits: 3, ccScore: 6, examScore: 6, ccWeight: 0.5, examWeight: 0.5 }] },
          { ueId: "ue-forte", elements: [{ ecId: "ec-2", credits: 3, ccScore: 18, examScore: 18, ccWeight: 0.5, examWeight: 0.5 }] },
        ],
        withCompensation,
        10,
      );
      // Moyenne générale = (6×3 + 18×3)/6 = 72/6 = 12 ≥ 10 → compensation applicable
      expect(result.overallAverage).toBe(12);
      const weak = result.units.find((u) => u.ueId === "ue-faible")!;
      expect(weak.validated).toBe(true);
      expect(result.creditsEarned).toBe(6); // les deux UE validées par compensation
    });
  });

  describe("calculateGPA / scoreToGpaPoints", () => {
    const gpaScale = [
      { grade: "A", minScore: 90, points: 4 },
      { grade: "B", minScore: 80, points: 3 },
      { grade: "C", minScore: 70, points: 2 },
      { grade: "D", minScore: 60, points: 1 },
      { grade: "F", minScore: 0, points: 0 },
    ];

    it("convertit une moyenne /20 en points GPA via l'échelle configurée", () => {
      // 16/20 → 80/100 → seuil B (80) → 3 points
      expect(engine.scoreToGpaPoints(16, gpaScale)).toBe(3);
      // 18/20 → 90/100 → seuil A (90) → 4 points
      expect(engine.scoreToGpaPoints(18, gpaScale)).toBe(4);
      // 8/20 → 40/100 → sous tous les seuils positifs → F (0 point)
      expect(engine.scoreToGpaPoints(8, gpaScale)).toBe(0);
    });

    it("GPA global pondéré par les crédits", () => {
      // UE1 : 18/20 (4 pts) × 6 crédits ; UE2 : 12/20 (60/100 → D, 1 pt) × 4 crédits
      // (4×6 + 1×4)/(6+4) = (24+4)/10 = 2.8
      const result = engine.calculateGPA(
        [
          { averageOn20: 18, credits: 6 },
          { averageOn20: 12, credits: 4 },
        ],
        gpaScale,
      );
      expect(result).toBeCloseTo(2.8, 5);
    });
  });

  describe("calculateRanking", () => {
    it("classement simple sans ex æquo", () => {
      const result = engine.calculateRanking([
        { id: "a", average: 12 },
        { id: "b", average: 16 },
        { id: "c", average: 10 },
      ]);
      expect(result.find((r) => r.id === "b")!.rank).toBe(1);
      expect(result.find((r) => r.id === "a")!.rank).toBe(2);
      expect(result.find((r) => r.id === "c")!.rank).toBe(3);
    });

    it("ex æquo : même rang, le suivant est décalé (1,2,2,4)", () => {
      const result = engine.calculateRanking([
        { id: "a", average: 15 },
        { id: "b", average: 12 },
        { id: "c", average: 15 },
        { id: "d", average: 10 },
      ]);
      const ranks = ["a", "b", "c", "d"].map((id) => result.find((r) => r.id === id)!.rank);
      // a=15 (rang1), c=15 (rang1 ex æquo), b=12 (rang3, décalé), d=10 (rang4)
      expect(result.find((r) => r.id === "a")!.rank).toBe(1);
      expect(result.find((r) => r.id === "c")!.rank).toBe(1);
      expect(result.find((r) => r.id === "b")!.rank).toBe(3);
      expect(result.find((r) => r.id === "d")!.rank).toBe(4);
      expect(ranks).toBeDefined();
    });

    it("les entrées sans moyenne ne sont pas classées (rank null) et sont reléguées en fin de liste", () => {
      const result = engine.calculateRanking([
        { id: "a", average: 10 },
        { id: "b", average: null },
        { id: "c", average: 14 },
      ]);
      expect(result.find((r) => r.id === "b")!.rank).toBeNull();
      expect(result.find((r) => r.id === "c")!.rank).toBe(1);
      expect(result.find((r) => r.id === "a")!.rank).toBe(2);
    });
  });

  describe("applyRounding", () => {
    it("arrondit au centième", () => {
      expect(engine.applyRounding(13.4567, "HUNDREDTH")).toBe(13.46);
    });

    it("arrondit au dixième", () => {
      expect(engine.applyRounding(13.44, "TENTH")).toBe(13.4);
      expect(engine.applyRounding(13.46, "TENTH")).toBe(13.5);
    });

    it("arrondit au demi-point", () => {
      expect(engine.applyRounding(13.2, "HALF_POINT")).toBe(13);
      expect(engine.applyRounding(13.3, "HALF_POINT")).toBe(13.5);
    });

    it("arrondit à l'entier", () => {
      expect(engine.applyRounding(13.5, "INTEGER")).toBe(14);
      expect(engine.applyRounding(13.49, "INTEGER")).toBe(13);
    });

    it("ne modifie pas la valeur si aucun arrondi (NONE)", () => {
      expect(engine.applyRounding(13.456789, "NONE")).toBe(13.456789);
    });
  });

  describe("determineMention", () => {
    it("détermine la mention selon les seuils par défaut", () => {
      expect(engine.determineMention(19, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("EXCELLENT");
      expect(engine.determineMention(17, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("TRES_BIEN");
      expect(engine.determineMention(15, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("BIEN");
      expect(engine.determineMention(13, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("ASSEZ_BIEN");
      expect(engine.determineMention(10, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("PASSABLE");
      expect(engine.determineMention(5, DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds)?.code).toBe("INSUFFISANT");
    });

    it("fonctionne même si les seuils ne sont pas triés en entrée", () => {
      const shuffled = [...DEFAULT_CALCULATION_ENGINE_CONFIG.mentionThresholds].reverse();
      expect(engine.determineMention(16.5, shuffled)?.code).toBe("TRES_BIEN");
    });

    it("renvoie null si aucun seuil n'est configuré", () => {
      expect(engine.determineMention(15, [])).toBeNull();
    });
  });
});
