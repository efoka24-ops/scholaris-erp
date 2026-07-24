"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/hooks/use-auth";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface BulletinGroup {
  number: number;
  label: string;
}
interface BulletinGroupsConfig {
  groups: BulletinGroup[];
  assignments: Record<string, number>;
  categoryDefaults: Record<string, number>;
}
interface Subject {
  id: string;
  code: string;
  name: string;
  category: string;
}

const CATEGORY_LABELS: Record<string, string> = {
  LITERARY: "Littéraire",
  LANGUAGE: "Langues",
  SCIENTIFIC: "Scientifique",
  TECHNICAL: "Technique",
  SPORTS: "EPS / Sports",
};

const fieldClass = "h-9 rounded-md border border-border bg-background px-2 text-sm";

export default function BulletinGroupsPage() {
  const { user } = useAuth();
  const [config, setConfig] = useState<BulletinGroupsConfig | null>(null);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);

  const load = useCallback(() => {
    if (!user?.tenantId) return;
    setIsLoading(true);
    Promise.all([
      resourceClient.get<BulletinGroupsConfig>(`/tenants/${user.tenantId}/bulletin-groups`).then((r) => r.data),
      resourceClient.get<{ data: Subject[] }>(`/subjects?limit=500`).then((r) => r.data.data).catch(() => []),
    ]).then(([cfg, subs]) => {
      setConfig(cfg);
      setSubjects(subs);
      setIsLoading(false);
    });
  }, [user?.tenantId]);

  useEffect(() => {
    load();
  }, [load]);

  function updateGroupLabel(number: number, label: string) {
    setConfig((c) => (c ? { ...c, groups: c.groups.map((g) => (g.number === number ? { ...g, label } : g)) } : c));
  }
  function addGroup() {
    setConfig((c) => {
      if (!c) return c;
      const nextNo = Math.max(0, ...c.groups.map((g) => g.number)) + 1;
      return { ...c, groups: [...c.groups, { number: nextNo, label: `Groupe ${nextNo}` }] };
    });
  }
  function removeGroup(number: number) {
    setConfig((c) => {
      if (!c || c.groups.length <= 1) return c;
      const assignments = { ...c.assignments };
      for (const k of Object.keys(assignments)) if (assignments[k] === number) delete assignments[k];
      const categoryDefaults = { ...c.categoryDefaults };
      for (const k of Object.keys(categoryDefaults)) if (categoryDefaults[k] === number) categoryDefaults[k] = c.groups[0].number;
      return { ...c, groups: c.groups.filter((g) => g.number !== number), assignments, categoryDefaults };
    });
  }
  function setAssignment(subjectId: string, value: string) {
    setConfig((c) => {
      if (!c) return c;
      const assignments = { ...c.assignments };
      if (value === "") delete assignments[subjectId];
      else assignments[subjectId] = Number(value);
      return { ...c, assignments };
    });
  }
  function setCategoryDefault(category: string, value: string) {
    setConfig((c) => (c ? { ...c, categoryDefaults: { ...c.categoryDefaults, [category]: Number(value) } } : c));
  }

  async function save() {
    if (!user?.tenantId || !config) return;
    setErr(null);
    setMsg(null);
    setIsSaving(true);
    try {
      await resourceClient.put(`/tenants/${user.tenantId}/bulletin-groups`, config);
      setMsg("Configuration des groupes enregistrée. Les prochains bulletins générés l'utiliseront.");
    } catch (e: any) {
      setErr(e.response?.data?.message ?? "Échec de l'enregistrement.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading || !config) return <LoadingSpinner label="Chargement de la configuration…" />;

  const effectiveGroup = (s: Subject): number =>
    config.assignments[s.id] ?? config.categoryDefaults[s.category] ?? config.groups[0].number;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Groupes de matières du bulletin</h1>
        <p className="text-sm text-muted-foreground">
          Définissez les groupes (Groupe 1/2/3…) affichés sur le bulletin MINESEC et l&apos;affectation de chaque matière.
        </p>
      </div>

      {err ? <p className="text-sm font-medium text-destructive">{err}</p> : null}
      {msg ? <p className="text-sm font-medium text-primary">{msg}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle>Groupes</CardTitle>
          <CardDescription>Nom affiché pour chaque groupe et son sous-total sur le bulletin</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {config.groups
            .sort((a, b) => a.number - b.number)
            .map((g) => (
              <div key={g.number} className="flex items-center gap-3">
                <span className="w-16 shrink-0 text-sm font-semibold">Groupe {g.number}</span>
                <Input value={g.label} onChange={(e) => updateGroupLabel(g.number, e.target.value)} />
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={config.groups.length <= 1}
                  onClick={() => removeGroup(g.number)}
                >
                  Retirer
                </Button>
              </div>
            ))}
          <Button variant="outline" size="sm" className="w-fit" onClick={addGroup}>
            + Ajouter un groupe
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Affectation par défaut (par catégorie)</CardTitle>
          <CardDescription>Groupe appliqué aux matières non affectées individuellement</CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {Object.entries(CATEGORY_LABELS).map(([cat, label]) => (
            <div key={cat} className="flex items-center justify-between gap-3">
              <Label>{label}</Label>
              <select
                className={fieldClass}
                value={config.categoryDefaults[cat] ?? config.groups[0].number}
                onChange={(e) => setCategoryDefault(cat, e.target.value)}
              >
                {config.groups.map((g) => (
                  <option key={g.number} value={g.number}>{g.label}</option>
                ))}
              </select>
            </div>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Affectation par matière ({subjects.length})</CardTitle>
          <CardDescription>Prioritaire sur l&apos;affectation par catégorie. « Auto » suit la catégorie.</CardDescription>
        </CardHeader>
        <CardContent>
          {subjects.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucune matière.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Matière</th>
                    <th className="py-2 pr-4 font-medium">Catégorie</th>
                    <th className="py-2 pr-4 font-medium">Groupe</th>
                    <th className="py-2 pr-4 font-medium">Effectif</th>
                  </tr>
                </thead>
                <tbody>
                  {subjects.map((s) => (
                    <tr key={s.id} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-medium">{s.name} <span className="font-mono text-xs text-muted-foreground">({s.code})</span></td>
                      <td className="py-2 pr-4 text-muted-foreground">{CATEGORY_LABELS[s.category] ?? s.category}</td>
                      <td className="py-2 pr-4">
                        <select
                          className={fieldClass}
                          value={config.assignments[s.id] ?? ""}
                          onChange={(e) => setAssignment(s.id, e.target.value)}
                        >
                          <option value="">Auto (catégorie)</option>
                          {config.groups.map((g) => (
                            <option key={g.number} value={g.number}>{g.label}</option>
                          ))}
                        </select>
                      </td>
                      <td className="py-2 pr-4 text-xs text-muted-foreground">→ Groupe {effectiveGroup(s)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Button disabled={isSaving} onClick={save} className="w-fit">
        {isSaving ? "Enregistrement…" : "Enregistrer la configuration"}
      </Button>
    </div>
  );
}
