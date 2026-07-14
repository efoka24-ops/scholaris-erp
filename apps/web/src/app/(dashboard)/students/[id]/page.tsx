"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { cn } from "@scholaris/ui";
import { resourceClient } from "@/lib/api-client";
import {
  ENROLLMENT_STATUS_LABELS,
  ENROLLMENT_TYPE_LABELS,
  REGIME_LABELS,
  RELATIONSHIP_LABELS,
  STUDENT_STATUS_LABELS,
  type Student,
} from "@/types/students";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

const TABS = [
  { id: "identity", label: "Identité" },
  { id: "parents", label: "Parents" },
  { id: "enrollments", label: "Inscriptions" },
  { id: "grades", label: "Notes" },
  { id: "payments", label: "Paiements" },
] as const;

type TabId = (typeof TABS)[number]["id"];

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs uppercase text-muted-foreground">{label}</span>
      <span className="text-sm">{value || "—"}</span>
    </div>
  );
}

export default function StudentDetailPage() {
  const params = useParams<{ id: string }>();
  const [student, setStudent] = useState<Student | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<TabId>("identity");

  useEffect(() => {
    if (!params?.id) return;
    resourceClient
      .get<Student>(`/students/${params.id}`)
      .then((response) => setStudent(response.data))
      .catch((err) => setError(err.response?.data?.message ?? "Impossible de charger le dossier."));
  }, [params?.id]);

  if (error) {
    return <p className="text-sm font-medium text-destructive">{error}</p>;
  }
  if (!student) {
    return <LoadingSpinner label="Chargement du dossier…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted-foreground">
            <Link href="/students" className="hover:underline">
              Élèves
            </Link>{" "}
            / {student.matricule}
          </p>
          <h1 className="text-2xl font-semibold">
            {student.lastName} {student.firstName}
          </h1>
          <p className="text-sm text-muted-foreground">
            {STUDENT_STATUS_LABELS[student.status]} — {student.enrollments?.[0]?.classroom?.name ?? "Non inscrit"}
          </p>
        </div>
      </div>

      <div className="flex gap-1 border-b border-border">
        {TABS.map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setActiveTab(tab.id)}
            className={cn(
              "border-b-2 border-transparent px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground",
              activeTab === tab.id && "border-primary text-foreground",
            )}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === "identity" ? (
        <Card>
          <CardHeader>
            <CardTitle>Identité</CardTitle>
            <CardDescription>État civil et informations médicales</CardDescription>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-3">
            <Field label="Matricule" value={student.matricule} />
            <Field label="Nom" value={student.lastName} />
            <Field label="Prénom" value={student.firstName} />
            <Field label="Date de naissance" value={new Date(student.dateOfBirth).toLocaleDateString("fr-FR")} />
            <Field label="Lieu de naissance" value={student.placeOfBirth} />
            <Field label="Sexe" value={student.gender === "MALE" ? "Masculin" : "Féminin"} />
            <Field label="Nationalité" value={student.nationality} />
            <Field label="Groupe sanguin" value={student.bloodGroup} />
            <Field label="Allergies" value={student.allergies} />
            <Field label="Handicap" value={student.handicap} />
            <Field label="Contact d'urgence" value={student.emergencyContact} />
            <Field label="Statut" value={STUDENT_STATUS_LABELS[student.status]} />
          </CardContent>
        </Card>
      ) : null}

      {activeTab === "parents" ? (
        <div className="grid gap-4 md:grid-cols-2">
          {(student.parents ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun parent enregistré.</p>
          ) : (
            (student.parents ?? []).map((link) => (
              <Card key={link.parentId}>
                <CardHeader>
                  <CardTitle className="text-base">
                    {link.parent.lastName} {link.parent.firstName}
                  </CardTitle>
                  <CardDescription>{RELATIONSHIP_LABELS[link.relationship]}</CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-2 gap-3">
                  <Field label="Téléphone" value={link.parent.phone} />
                  <Field label="WhatsApp" value={link.parent.whatsapp} />
                  <Field label="Email" value={link.parent.email} />
                  <Field label="Profession" value={link.parent.profession} />
                  <Field label="Adresse" value={link.parent.address} />
                </CardContent>
              </Card>
            ))
          )}
        </div>
      ) : null}

      {activeTab === "enrollments" ? (
        <Card>
          <CardHeader>
            <CardTitle>Historique des inscriptions</CardTitle>
          </CardHeader>
          <CardContent>
            {(student.enrollments ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">Aucune inscription.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left">
                    <tr>
                      <th className="px-4 py-2 font-medium">Année</th>
                      <th className="px-4 py-2 font-medium">Classe</th>
                      <th className="px-4 py-2 font-medium">Type</th>
                      <th className="px-4 py-2 font-medium">Régime</th>
                      <th className="px-4 py-2 font-medium">Redoublant</th>
                      <th className="px-4 py-2 font-medium">Statut</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(student.enrollments ?? []).map((enrollment) => (
                      <tr key={enrollment.id} className="border-t border-border">
                        <td className="px-4 py-2">{enrollment.academicYear?.label ?? "—"}</td>
                        <td className="px-4 py-2">{enrollment.classroom?.name ?? "—"}</td>
                        <td className="px-4 py-2">{ENROLLMENT_TYPE_LABELS[enrollment.type]}</td>
                        <td className="px-4 py-2">{REGIME_LABELS[enrollment.regime]}</td>
                        <td className="px-4 py-2">{enrollment.isRepeater ? "Oui" : "Non"}</td>
                        <td className="px-4 py-2">{ENROLLMENT_STATUS_LABELS[enrollment.status]}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      ) : null}

      {activeTab === "grades" ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Les notes et bulletins arriveront avec le Module 5 (Évaluations et notes).
          </CardContent>
        </Card>
      ) : null}

      {activeTab === "payments" ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Le suivi des paiements arrivera avec le Module 7 (Finances et scolarité).
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
