"use client";

import { useCallback, useEffect, useState } from "react";
import { Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface HealthRecord {
  id: string;
  studentId: string;
  bloodType: string | null;
  allergies: string | null;
  chronicDiseases: string | null;
  medications: string | null;
  vaccinations: string | null;
  emergencyContact: string | null;
  notes: string | null;
  student?: { firstName: string; lastName: string; matricule: string };
}
interface StudentOption {
  id: string;
  firstName: string;
  lastName: string;
  matricule: string;
}

const EMPTY = {
  studentId: "",
  bloodType: "",
  allergies: "",
  chronicDiseases: "",
  medications: "",
  vaccinations: "",
  emergencyContact: "",
  notes: "",
};
const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

export default function HealthPage() {
  const [records, setRecords] = useState<HealthRecord[]>([]);
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ ...EMPTY });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(() => {
    setIsLoading(true);
    Promise.all([
      resourceClient.get<HealthRecord[]>("/health-records").then((r) => r.data).catch(() => []),
      resourceClient.get<{ data: StudentOption[] }>("/students?limit=500").then((r) => r.data.data).catch(() => []),
    ]).then(([recs, studs]) => {
      setRecords(recs);
      setStudents(studs);
      setIsLoading(false);
    });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setMessage(null);
    if (!form.studentId) {
      setError("Sélectionnez un élève.");
      return;
    }
    setSaving(true);
    try {
      const payload = Object.fromEntries(
        Object.entries(form).filter(([k, v]) => k === "studentId" || (v as string).trim() !== ""),
      );
      await resourceClient.post("/health-records", payload);
      setMessage("Dossier médical enregistré.");
      setForm({ ...EMPTY });
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err.response?.data?.message ?? "Impossible d'enregistrer le dossier.");
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement des dossiers médicaux…" />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Santé scolaire</h1>
          <p className="text-sm text-muted-foreground">Dossiers médicaux, allergies, traitements et vaccinations</p>
        </div>
        <Button onClick={() => { setShowForm((v) => !v); setError(null); setMessage(null); }}>
          <Plus className="mr-2 h-4 w-4" />
          {showForm ? "Fermer" : "Nouveau dossier médical"}
        </Button>
      </div>

      {message ? <p className="text-sm font-medium text-primary">{message}</p> : null}

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Dossier médical</CardTitle>
            <CardDescription>Un seul dossier par élève (mis à jour si existant)</CardDescription>
          </CardHeader>
          <CardContent>
            <form className="grid grid-cols-1 gap-4 sm:grid-cols-2" onSubmit={submit}>
              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <Label>Élève *</Label>
                <select className={fieldClass} value={form.studentId} onChange={(e) => setForm((f) => ({ ...f, studentId: e.target.value }))}>
                  <option value="">— Sélectionner —</option>
                  {students.map((s) => (
                    <option key={s.id} value={s.id}>{s.lastName} {s.firstName} ({s.matricule})</option>
                  ))}
                </select>
              </div>
              <Field label="Groupe sanguin" value={form.bloodType} onChange={(v) => setForm((f) => ({ ...f, bloodType: v }))} placeholder="O+" />
              <Field label="Contact d'urgence" value={form.emergencyContact} onChange={(v) => setForm((f) => ({ ...f, emergencyContact: v }))} placeholder="+237…" />
              <Field label="Allergies" value={form.allergies} onChange={(v) => setForm((f) => ({ ...f, allergies: v }))} />
              <Field label="Maladies chroniques" value={form.chronicDiseases} onChange={(v) => setForm((f) => ({ ...f, chronicDiseases: v }))} />
              <Field label="Traitements en cours" value={form.medications} onChange={(v) => setForm((f) => ({ ...f, medications: v }))} />
              <Field label="Vaccinations" value={form.vaccinations} onChange={(v) => setForm((f) => ({ ...f, vaccinations: v }))} />
              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <Label>Notes</Label>
                <Input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
              </div>
              {error ? <p className="text-sm font-medium text-destructive sm:col-span-2">{error}</p> : null}
              <div className="sm:col-span-2">
                <Button type="submit" disabled={saving}>
                  {saving ? "Enregistrement…" : "Enregistrer le dossier"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Dossiers médicaux ({records.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {records.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Aucun dossier médical. Cliquez « Nouveau dossier médical » pour en créer un.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Élève</th>
                    <th className="py-2 pr-4 font-medium">Groupe</th>
                    <th className="py-2 pr-4 font-medium">Allergies</th>
                    <th className="py-2 pr-4 font-medium">Traitements</th>
                    <th className="py-2 pr-4 font-medium">Contact urgence</th>
                  </tr>
                </thead>
                <tbody>
                  {records.map((r) => (
                    <tr key={r.id} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-medium">
                        {r.student ? `${r.student.lastName} ${r.student.firstName}` : r.studentId}
                      </td>
                      <td className="py-2 pr-4">{r.bloodType ?? "—"}</td>
                      <td className="py-2 pr-4 text-muted-foreground">{r.allergies ?? "—"}</td>
                      <td className="py-2 pr-4 text-muted-foreground">{r.medications ?? "—"}</td>
                      <td className="py-2 pr-4">{r.emergencyContact ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function Field({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label>{label}</Label>
      <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
    </div>
  );
}
