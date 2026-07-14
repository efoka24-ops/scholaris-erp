"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { cn } from "@scholaris/ui";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import { RELATIONSHIP_LABELS, type AcademicYear, type ParentRelationship } from "@/types/students";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const STEPS = ["Identité", "Parents", "Scolarité antérieure", "Confirmation"] as const;

interface IdentityState {
  firstName: string;
  lastName: string;
  dateOfBirth: string;
  placeOfBirth: string;
  gender: "MALE" | "FEMALE";
  nationality: string;
  bloodGroup: string;
  allergies: string;
  emergencyContact: string;
}

interface ParentState {
  firstName: string;
  lastName: string;
  phone: string;
  whatsapp: string;
  email: string;
  profession: string;
  address: string;
  relationship: ParentRelationship;
}

interface SchoolingState {
  classroomId: string;
  academicYearId: string;
  regime: "EXTERNAL" | "HALF_BOARD" | "BOARDING";
  isRepeater: boolean;
  previousSchool: string;
  previousAverage: string;
}

const EMPTY_PARENT: ParentState = {
  firstName: "",
  lastName: "",
  phone: "",
  whatsapp: "",
  email: "",
  profession: "",
  address: "",
  relationship: "FATHER",
};

export default function NewStudentPage() {
  const router = useRouter();
  const [step, setStep] = useState(0);
  const [identity, setIdentity] = useState<IdentityState>({
    firstName: "",
    lastName: "",
    dateOfBirth: "",
    placeOfBirth: "",
    gender: "MALE",
    nationality: "Camerounaise",
    bloodGroup: "",
    allergies: "",
    emergencyContact: "",
  });
  const [parents, setParents] = useState<ParentState[]>([{ ...EMPTY_PARENT }]);
  const [schooling, setSchooling] = useState<SchoolingState>({
    classroomId: "",
    academicYearId: "",
    regime: "EXTERNAL",
    isRepeater: false,
    previousSchool: "",
    previousAverage: "",
  });
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [duplicateWarning, setDuplicateWarning] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  function validateStep(): string | null {
    if (step === 0) {
      if (!identity.lastName.trim()) return "Le nom est requis.";
      if (!identity.firstName.trim()) return "Le prénom est requis.";
      if (!identity.dateOfBirth) return "La date de naissance est requise.";
    }
    if (step === 1) {
      for (const parent of parents) {
        if (!parent.lastName.trim() || !parent.firstName.trim() || !parent.phone.trim()) {
          return "Chaque parent doit avoir un nom, un prénom et un téléphone.";
        }
      }
      if (parents.length === 0) return "Renseignez au moins un parent ou tuteur.";
    }
    return null;
  }

  function next() {
    const validationError = validateStep();
    if (validationError) {
      setError(validationError);
      return;
    }
    setError(null);
    setStep((current) => Math.min(current + 1, STEPS.length - 1));
  }

  async function submit(force: boolean) {
    setIsSubmitting(true);
    setError(null);
    setDuplicateWarning(null);
    try {
      const payload = {
        firstName: identity.firstName.trim(),
        lastName: identity.lastName.trim(),
        dateOfBirth: identity.dateOfBirth,
        gender: identity.gender,
        ...(identity.placeOfBirth ? { placeOfBirth: identity.placeOfBirth } : {}),
        ...(identity.nationality ? { nationality: identity.nationality } : {}),
        ...(identity.bloodGroup ? { bloodGroup: identity.bloodGroup } : {}),
        ...(identity.allergies ? { allergies: identity.allergies } : {}),
        ...(identity.emergencyContact ? { emergencyContact: identity.emergencyContact } : {}),
        parents: parents.map((parent) => ({
          firstName: parent.firstName.trim(),
          lastName: parent.lastName.trim(),
          phone: parent.phone.trim(),
          relationship: parent.relationship,
          ...(parent.whatsapp ? { whatsapp: parent.whatsapp } : {}),
          ...(parent.email ? { email: parent.email } : {}),
          ...(parent.profession ? { profession: parent.profession } : {}),
          ...(parent.address ? { address: parent.address } : {}),
        })),
        ...(force ? { force: true } : {}),
      };
      const { data: student } = await resourceClient.post<{ id: string }>("/students", payload);

      if (schooling.classroomId && schooling.academicYearId) {
        try {
          await resourceClient.post("/enrollments", {
            studentId: student.id,
            classroomId: schooling.classroomId,
            academicYearId: schooling.academicYearId,
            type: "NEW",
            regime: schooling.regime,
            isRepeater: schooling.isRepeater,
            ...(schooling.previousSchool ? { previousSchool: schooling.previousSchool } : {}),
            ...(schooling.previousAverage ? { previousAverage: Number(schooling.previousAverage) } : {}),
          });
        } catch (enrollError: any) {
          setError(
            `Élève créé, mais inscription impossible : ${
              enrollError.response?.data?.message ?? "erreur inconnue"
            }. Vous pourrez l'inscrire depuis son dossier.`,
          );
          router.push(`/students/${student.id}`);
          return;
        }
      }
      router.push(`/students/${student.id}`);
    } catch (submitError: any) {
      if (submitError.response?.status === 409 && submitError.response?.data?.duplicates) {
        const duplicates = submitError.response.data.duplicates as Array<{ matricule: string }>;
        setDuplicateWarning(
          `Doublon potentiel détecté : ${duplicates.map((d) => d.matricule).join(", ")}. ` +
            "Vérifiez qu'il ne s'agit pas du même élève, ou forcez la création.",
        );
      } else {
        setError(submitError.response?.data?.message ?? "Impossible de créer l'élève.");
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Nouvel élève</h1>
        <p className="text-sm text-muted-foreground">Inscription en {STEPS.length} étapes</p>
      </div>

      <ol className="flex gap-2">
        {STEPS.map((label, index) => (
          <li
            key={label}
            className={cn(
              "flex items-center gap-2 rounded-md px-3 py-1.5 text-sm",
              index === step ? "bg-primary text-primary-foreground" : "bg-secondary text-muted-foreground",
            )}
          >
            <span className="font-semibold">{index + 1}</span> {label}
          </li>
        ))}
      </ol>

      {step === 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Identité de l'élève</CardTitle>
            <CardDescription>État civil et informations médicales</CardDescription>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Nom *</Label>
              <Input value={identity.lastName} onChange={(e) => setIdentity({ ...identity, lastName: e.target.value })} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Prénom *</Label>
              <Input value={identity.firstName} onChange={(e) => setIdentity({ ...identity, firstName: e.target.value })} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Date de naissance *</Label>
              <Input
                type="date"
                value={identity.dateOfBirth}
                onChange={(e) => setIdentity({ ...identity, dateOfBirth: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Lieu de naissance</Label>
              <Input
                value={identity.placeOfBirth}
                onChange={(e) => setIdentity({ ...identity, placeOfBirth: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Sexe *</Label>
              <select
                className={fieldClass}
                value={identity.gender}
                onChange={(e) => setIdentity({ ...identity, gender: e.target.value as "MALE" | "FEMALE" })}
              >
                <option value="MALE">Masculin</option>
                <option value="FEMALE">Féminin</option>
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Nationalité</Label>
              <Input
                value={identity.nationality}
                onChange={(e) => setIdentity({ ...identity, nationality: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Groupe sanguin</Label>
              <Input
                placeholder="O+, AB-…"
                value={identity.bloodGroup}
                onChange={(e) => setIdentity({ ...identity, bloodGroup: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Allergies</Label>
              <Input value={identity.allergies} onChange={(e) => setIdentity({ ...identity, allergies: e.target.value })} />
            </div>
            <div className="col-span-2 flex flex-col gap-1.5">
              <Label>Contact d'urgence</Label>
              <Input
                placeholder="+2376… (lien avec l'élève)"
                value={identity.emergencyContact}
                onChange={(e) => setIdentity({ ...identity, emergencyContact: e.target.value })}
              />
            </div>
          </CardContent>
        </Card>
      ) : null}

      {step === 1 ? (
        <div className="flex flex-col gap-4">
          {parents.map((parent, index) => (
            <Card key={index}>
              <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">Parent / tuteur {index + 1}</CardTitle>
                {parents.length > 1 ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setParents(parents.filter((_, i) => i !== index))}
                  >
                    Retirer
                  </Button>
                ) : null}
              </CardHeader>
              <CardContent className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label>Lien de parenté *</Label>
                  <select
                    className={fieldClass}
                    value={parent.relationship}
                    onChange={(e) => {
                      const next = [...parents];
                      next[index] = { ...parent, relationship: e.target.value as ParentRelationship };
                      setParents(next);
                    }}
                  >
                    {(Object.keys(RELATIONSHIP_LABELS) as ParentRelationship[]).map((relationship) => (
                      <option key={relationship} value={relationship}>
                        {RELATIONSHIP_LABELS[relationship]}
                      </option>
                    ))}
                  </select>
                </div>
                <div />
                {(
                  [
                    ["lastName", "Nom *"],
                    ["firstName", "Prénom *"],
                    ["phone", "Téléphone *"],
                    ["whatsapp", "WhatsApp"],
                    ["email", "Email"],
                    ["profession", "Profession"],
                  ] as const
                ).map(([field, label]) => (
                  <div key={field} className="flex flex-col gap-1.5">
                    <Label>{label}</Label>
                    <Input
                      value={parent[field]}
                      onChange={(e) => {
                        const next = [...parents];
                        next[index] = { ...parent, [field]: e.target.value };
                        setParents(next);
                      }}
                    />
                  </div>
                ))}
                <div className="col-span-2 flex flex-col gap-1.5">
                  <Label>Adresse</Label>
                  <Input
                    value={parent.address}
                    onChange={(e) => {
                      const next = [...parents];
                      next[index] = { ...parent, address: e.target.value };
                      setParents(next);
                    }}
                  />
                </div>
              </CardContent>
            </Card>
          ))}
          <Button variant="outline" onClick={() => setParents([...parents, { ...EMPTY_PARENT, relationship: "MOTHER" }])}>
            Ajouter un parent / tuteur
          </Button>
        </div>
      ) : null}

      {step === 2 ? (
        <Card>
          <CardHeader>
            <CardTitle>Scolarité antérieure et affectation</CardTitle>
            <CardDescription>
              Classe et année facultatives à cette étape : l'élève peut être inscrit plus tard depuis son dossier.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Classe</Label>
              <select
                className={fieldClass}
                value={schooling.classroomId}
                onChange={(e) => setSchooling({ ...schooling, classroomId: e.target.value })}
              >
                <option value="">Aucune pour l'instant</option>
                {classrooms.map((classroom) => (
                  <option key={classroom.id} value={classroom.id}>
                    {classroom.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Année académique</Label>
              <select
                className={fieldClass}
                value={schooling.academicYearId}
                onChange={(e) => setSchooling({ ...schooling, academicYearId: e.target.value })}
              >
                <option value="">Sélectionner…</option>
                {years.map((year) => (
                  <option key={year.id} value={year.id}>
                    {year.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Régime</Label>
              <select
                className={fieldClass}
                value={schooling.regime}
                onChange={(e) =>
                  setSchooling({ ...schooling, regime: e.target.value as SchoolingState["regime"] })
                }
              >
                <option value="EXTERNAL">Externe</option>
                <option value="HALF_BOARD">Demi-pensionnaire</option>
                <option value="BOARDING">Interne</option>
              </select>
            </div>
            <div className="flex items-end gap-2 pb-2">
              <input
                id="isRepeater"
                type="checkbox"
                className="h-4 w-4"
                checked={schooling.isRepeater}
                onChange={(e) => setSchooling({ ...schooling, isRepeater: e.target.checked })}
              />
              <Label htmlFor="isRepeater">Redoublant</Label>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>École précédente</Label>
              <Input
                value={schooling.previousSchool}
                onChange={(e) => setSchooling({ ...schooling, previousSchool: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Moyenne précédente (/20)</Label>
              <Input
                type="number"
                step="0.01"
                min={0}
                max={20}
                value={schooling.previousAverage}
                onChange={(e) => setSchooling({ ...schooling, previousAverage: e.target.value })}
              />
            </div>
          </CardContent>
        </Card>
      ) : null}

      {step === 3 ? (
        <Card>
          <CardHeader>
            <CardTitle>Confirmation</CardTitle>
            <CardDescription>Vérifiez les informations avant de créer le dossier.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4 text-sm">
            <div>
              <h3 className="mb-1 font-medium">Élève</h3>
              <p>
                {identity.lastName} {identity.firstName} — né(e) le{" "}
                {identity.dateOfBirth ? new Date(identity.dateOfBirth).toLocaleDateString("fr-FR") : "—"}
                {identity.placeOfBirth ? ` à ${identity.placeOfBirth}` : ""} —{" "}
                {identity.gender === "MALE" ? "Masculin" : "Féminin"} — {identity.nationality || "—"}
              </p>
            </div>
            <div>
              <h3 className="mb-1 font-medium">Parents</h3>
              <ul className="list-inside list-disc">
                {parents.map((parent, index) => (
                  <li key={index}>
                    {RELATIONSHIP_LABELS[parent.relationship]} : {parent.lastName} {parent.firstName} ({parent.phone})
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <h3 className="mb-1 font-medium">Affectation</h3>
              <p>
                {schooling.classroomId
                  ? `${classrooms.find((c) => c.id === schooling.classroomId)?.name ?? ""} — ${
                      years.find((y) => y.id === schooling.academicYearId)?.label ?? "année non choisie"
                    }`
                  : "Aucune inscription immédiate"}
              </p>
            </div>
            {duplicateWarning ? (
              <div className="rounded-md border border-amber-500 bg-amber-500/10 p-3">
                <p className="font-medium">{duplicateWarning}</p>
                <Button
                  className="mt-2"
                  variant="outline"
                  size="sm"
                  disabled={isSubmitting}
                  onClick={() => submit(true)}
                >
                  Forcer la création
                </Button>
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <div className="flex justify-between">
        <Button variant="outline" disabled={step === 0 || isSubmitting} onClick={() => setStep(step - 1)}>
          Précédent
        </Button>
        {step < STEPS.length - 1 ? (
          <Button onClick={next}>Suivant</Button>
        ) : (
          <Button disabled={isSubmitting} onClick={() => submit(false)}>
            {isSubmitting ? "Création…" : "Créer le dossier"}
          </Button>
        )}
      </div>
    </div>
  );
}
