"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { ChevronDown, ChevronRight, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { DepartmentOption, TeachingUnit } from "@/types/subjects";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface UnitFormState {
  code: string;
  name: string;
  credits: string;
  semester: string;
  isFundamental: boolean;
  departmentId: string;
}

interface ElementFormState {
  code: string;
  name: string;
  credits: string;
  hoursCm: string;
  hoursTd: string;
  hoursTp: string;
  coefficient: string;
}

const EMPTY_UNIT: UnitFormState = { code: "", name: "", credits: "6", semester: "1", isFundamental: false, departmentId: "" };
const EMPTY_ELEMENT: ElementFormState = { code: "", name: "", credits: "3", hoursCm: "0", hoursTd: "0", hoursTp: "0", coefficient: "1" };

export default function TeachingUnitsPage() {
  const [units, setUnits] = useState<TeachingUnit[]>([]);
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [showUnitForm, setShowUnitForm] = useState(false);
  const [unitForm, setUnitForm] = useState<UnitFormState>(EMPTY_UNIT);
  const [elementFormUnitId, setElementFormUnitId] = useState<string | null>(null);
  const [elementForm, setElementForm] = useState<ElementFormState>(EMPTY_ELEMENT);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<TeachingUnit[]>("/teaching-units")
      .then((response) => setUnits(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les UE."),
      )
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
    resourceClient.get<DepartmentOption[]>("/departments").then((response) => setDepartments(response.data));
  }, [load]);

  const unitsBySemester = useMemo(() => {
    const map = new Map<number, TeachingUnit[]>();
    for (const unit of units) {
      const list = map.get(unit.semester) ?? [];
      list.push(unit);
      map.set(unit.semester, list);
    }
    return Array.from(map.entries()).sort(([a], [b]) => a - b);
  }, [units]);

  function toggleExpanded(unitId: string) {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(unitId)) {
        next.delete(unitId);
      } else {
        next.add(unitId);
      }
      return next;
    });
  }

  function allocatedCredits(unit: TeachingUnit): number {
    return unit.courseElements.reduce((sum, element) => sum + element.credits, 0);
  }

  async function handleCreateUnit() {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/teaching-units", {
        code: unitForm.code,
        name: unitForm.name,
        credits: Number(unitForm.credits),
        semester: Number(unitForm.semester),
        isFundamental: unitForm.isFundamental,
        departmentId: unitForm.departmentId,
      });
      setUnitForm(EMPTY_UNIT);
      setShowUnitForm(false);
      load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer l'UE.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleCreateElement(unitId: string) {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/course-elements", {
        code: elementForm.code,
        name: elementForm.name,
        credits: Number(elementForm.credits),
        hoursCm: Number(elementForm.hoursCm),
        hoursTd: Number(elementForm.hoursTd),
        hoursTp: Number(elementForm.hoursTp),
        coefficient: Number(elementForm.coefficient),
        teachingUnitId: unitId,
      });
      setElementForm(EMPTY_ELEMENT);
      setElementFormUnitId(null);
      setExpanded((current) => new Set(current).add(unitId));
      load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer l'EC.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Unités d'enseignement (UE)</h1>
          <p className="text-sm text-muted-foreground">
            Référentiel LMD : UE et leurs éléments constitutifs (EC) avec crédits et volumes CM/TD/TP
          </p>
        </div>
        <Button size="sm" onClick={() => setShowUnitForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouvelle UE
        </Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {showUnitForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvelle unité d'enseignement</CardTitle>
            <CardDescription>Le code doit être unique dans l'établissement.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
              <Input placeholder="Code (UE-INF-101)" value={unitForm.code} onChange={(e) => setUnitForm({ ...unitForm, code: e.target.value })} />
              <Input placeholder="Nom" value={unitForm.name} onChange={(e) => setUnitForm({ ...unitForm, name: e.target.value })} />
              <Input type="number" min={1} placeholder="Crédits" value={unitForm.credits} onChange={(e) => setUnitForm({ ...unitForm, credits: e.target.value })} />
              <Input type="number" min={1} placeholder="Semestre" value={unitForm.semester} onChange={(e) => setUnitForm({ ...unitForm, semester: e.target.value })} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <select
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={unitForm.departmentId}
                onChange={(e) => setUnitForm({ ...unitForm, departmentId: e.target.value })}
              >
                <option value="">Département…</option>
                {departments.map((department) => (
                  <option key={department.id} value={department.id}>
                    {department.name}
                  </option>
                ))}
              </select>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-border"
                  checked={unitForm.isFundamental}
                  onChange={(e) => setUnitForm({ ...unitForm, isFundamental: e.target.checked })}
                />
                UE fondamentale
              </label>
            </div>
            <Button
              className="self-start"
              disabled={isSubmitting || !unitForm.code || !unitForm.name || !unitForm.departmentId}
              onClick={handleCreateUnit}
            >
              {isSubmitting ? "Création…" : "Créer l'UE"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      {isLoading ? (
        <LoadingSpinner label="Chargement des UE…" />
      ) : units.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune UE définie pour le moment.</p>
      ) : (
        unitsBySemester.map(([semester, semesterUnits]) => (
          <div key={semester} className="flex flex-col gap-2">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
              Semestre {semester}
            </h2>
            {semesterUnits.map((unit) => {
              const isExpanded = expanded.has(unit.id);
              const allocated = allocatedCredits(unit);
              return (
                <div key={unit.id} className="rounded-md border border-border">
                  <button
                    type="button"
                    className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-secondary/40"
                    onClick={() => toggleExpanded(unit.id)}
                  >
                    {isExpanded ? <ChevronDown className="h-4 w-4 shrink-0" /> : <ChevronRight className="h-4 w-4 shrink-0" />}
                    <span className="font-medium">{unit.code}</span>
                    <span className="flex-1">{unit.name}</span>
                    {unit.isFundamental ? (
                      <span className="rounded bg-secondary px-2 py-0.5 text-xs">Fondamentale</span>
                    ) : null}
                    <span className="text-sm text-muted-foreground">{unit.department?.name ?? "—"}</span>
                    <span className="text-sm font-medium">
                      {allocated}/{unit.credits} crédits
                    </span>
                  </button>

                  {isExpanded ? (
                    <div className="border-t border-border px-4 py-3">
                      {unit.courseElements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Aucun EC dans cette UE.</p>
                      ) : (
                        <div className="overflow-x-auto rounded-md border border-border">
                          <table className="w-full text-sm">
                            <thead className="bg-secondary/60 text-left">
                              <tr>
                                <th className="px-3 py-2 font-medium">Code</th>
                                <th className="px-3 py-2 font-medium">EC</th>
                                <th className="px-3 py-2 font-medium">Crédits</th>
                                <th className="px-3 py-2 font-medium">CM</th>
                                <th className="px-3 py-2 font-medium">TD</th>
                                <th className="px-3 py-2 font-medium">TP</th>
                                <th className="px-3 py-2 font-medium">Coefficient</th>
                              </tr>
                            </thead>
                            <tbody>
                              {unit.courseElements.map((element) => (
                                <tr key={element.id} className="border-t border-border">
                                  <td className="px-3 py-2">{element.code}</td>
                                  <td className="px-3 py-2">{element.name}</td>
                                  <td className="px-3 py-2">{element.credits}</td>
                                  <td className="px-3 py-2">{element.hoursCm} h</td>
                                  <td className="px-3 py-2">{element.hoursTd} h</td>
                                  <td className="px-3 py-2">{element.hoursTp} h</td>
                                  <td className="px-3 py-2">{Number(element.coefficient)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}

                      {elementFormUnitId === unit.id ? (
                        <div className="mt-3 flex flex-col gap-3 rounded-md border border-border p-3">
                          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Input placeholder="Code EC" value={elementForm.code} onChange={(e) => setElementForm({ ...elementForm, code: e.target.value })} />
                            <Input placeholder="Nom" value={elementForm.name} onChange={(e) => setElementForm({ ...elementForm, name: e.target.value })} />
                            <Input type="number" min={1} placeholder="Crédits" value={elementForm.credits} onChange={(e) => setElementForm({ ...elementForm, credits: e.target.value })} />
                            <Input type="number" step="0.5" min={0.5} placeholder="Coefficient" value={elementForm.coefficient} onChange={(e) => setElementForm({ ...elementForm, coefficient: e.target.value })} />
                          </div>
                          <div className="grid grid-cols-3 gap-3">
                            <Input type="number" min={0} placeholder="Heures CM" value={elementForm.hoursCm} onChange={(e) => setElementForm({ ...elementForm, hoursCm: e.target.value })} />
                            <Input type="number" min={0} placeholder="Heures TD" value={elementForm.hoursTd} onChange={(e) => setElementForm({ ...elementForm, hoursTd: e.target.value })} />
                            <Input type="number" min={0} placeholder="Heures TP" value={elementForm.hoursTp} onChange={(e) => setElementForm({ ...elementForm, hoursTp: e.target.value })} />
                          </div>
                          <div className="flex gap-2">
                            <Button
                              size="sm"
                              disabled={isSubmitting || !elementForm.code || !elementForm.name}
                              onClick={() => handleCreateElement(unit.id)}
                            >
                              {isSubmitting ? "Création…" : "Créer l'EC"}
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => setElementFormUnitId(null)}>
                              Annuler
                            </Button>
                          </div>
                        </div>
                      ) : (
                        <Button
                          size="sm"
                          variant="outline"
                          className="mt-3"
                          onClick={() => {
                            setElementForm(EMPTY_ELEMENT);
                            setElementFormUnitId(unit.id);
                          }}
                        >
                          <Plus className="mr-2 h-3.5 w-3.5" />
                          Ajouter un EC
                        </Button>
                      )}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
        ))
      )}
    </div>
  );
}
