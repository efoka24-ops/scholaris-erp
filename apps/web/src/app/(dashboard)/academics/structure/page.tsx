"use client";

import { useCallback, useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { CycleNode, LevelNode } from "@/types/structure";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

// Réorganisation par boutons haut/bas plutôt que par glisser-déposer : plus
// simple à livrer et suffisant pour l'usage réel (peu de cycles/niveaux par
// établissement). Un vrai drag-and-drop pourra remplacer ceci sans changer
// l'API (PATCH /cycles/:id et /levels/:id acceptent déjà `order`).
function LevelRow({ level, onMove }: { level: LevelNode; onMove: (levelId: string, direction: "up" | "down") => void }) {
  return (
    <div className="ml-6 flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
      <span>
        {level.name} <span className="text-muted-foreground">({level.code})</span>
      </span>
      <div className="flex items-center gap-3">
        <span className="text-muted-foreground">{level.classrooms.length} classe(s)</span>
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" onClick={() => onMove(level.id, "up")} aria-label="Monter">
            <ArrowUp className="h-3.5 w-3.5" />
          </Button>
          <Button variant="ghost" size="sm" onClick={() => onMove(level.id, "down")} aria-label="Descendre">
            <ArrowDown className="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>
    </div>
  );
}

export default function StructureTreePage() {
  const [tree, setTree] = useState<CycleNode[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCycleForm, setShowCycleForm] = useState(false);
  const [cycleForm, setCycleForm] = useState({ code: "", name: "" });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Création de niveau : cycle ciblé + formulaire.
  const [levelCycleId, setLevelCycleId] = useState<string | null>(null);
  const [levelForm, setLevelForm] = useState({ code: "", name: "" });
  const [levelSaving, setLevelSaving] = useState(false);
  const [levelError, setLevelError] = useState<string | null>(null);

  const loadTree = useCallback(async () => {
    setIsLoading(true);
    const { data } = await resourceClient.get<CycleNode[]>("/structure/tree");
    setTree(data);
    setIsLoading(false);
  }, []);

  async function createCycle(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (!cycleForm.code.trim() || !cycleForm.name.trim()) {
      setError("Le code et le nom du cycle sont requis.");
      return;
    }
    setSaving(true);
    try {
      await resourceClient.post("/cycles", {
        code: cycleForm.code.trim(),
        name: cycleForm.name.trim(),
        order: tree.length + 1,
      });
      setCycleForm({ code: "", name: "" });
      setShowCycleForm(false);
      await loadTree();
    } catch (err: any) {
      setError(err.response?.data?.message ?? "Impossible de créer le cycle.");
    } finally {
      setSaving(false);
    }
  }

  async function createLevel(e: React.FormEvent, cycle: CycleNode) {
    e.preventDefault();
    setLevelError(null);
    if (!levelForm.code.trim() || !levelForm.name.trim()) {
      setLevelError("Le code et le nom du niveau sont requis.");
      return;
    }
    setLevelSaving(true);
    try {
      await resourceClient.post("/levels", {
        code: levelForm.code.trim(),
        name: levelForm.name.trim(),
        cycleId: cycle.id,
        order: cycle.levels.length + 1,
      });
      setLevelForm({ code: "", name: "" });
      setLevelCycleId(null);
      await loadTree();
    } catch (err: any) {
      setLevelError(err.response?.data?.message ?? "Impossible de créer le niveau.");
    } finally {
      setLevelSaving(false);
    }
  }

  function openLevelForm(cycleId: string) {
    setLevelCycleId((c) => (c === cycleId ? null : cycleId));
    setLevelForm({ code: "", name: "" });
    setLevelError(null);
  }

  useEffect(() => {
    loadTree();
  }, [loadTree]);

  async function moveLevel(levelId: string, direction: "up" | "down") {
    // Échange l'ordre avec le niveau voisin dans la même liste (cycle ou filière).
    const allLevels = tree.flatMap((cycle) => [...cycle.levels, ...cycle.programs.flatMap((program) => program.levels)]);
    const level = allLevels.find((item) => item.id === levelId);
    if (!level) return;
    const siblings = allLevels
      .filter((item) => item.cycleId === level.cycleId && item.programId === level.programId)
      .sort((a, b) => a.order - b.order);
    const index = siblings.findIndex((item) => item.id === levelId);
    const neighborIndex = direction === "up" ? index - 1 : index + 1;
    const neighbor = siblings[neighborIndex];
    if (!neighbor) return;

    await Promise.all([
      resourceClient.patch(`/levels/${level.id}`, { order: neighbor.order }),
      resourceClient.patch(`/levels/${neighbor.id}`, { order: level.order }),
    ]);
    await loadTree();
  }

  if (isLoading) {
    return <LoadingSpinner label="Chargement de la structure pédagogique…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Structure pédagogique</h1>
          <p className="text-sm text-muted-foreground">Cycles → filières/programmes → niveaux → classes</p>
        </div>
        <Button size="sm" onClick={() => { setShowCycleForm((v) => !v); setError(null); }}>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau cycle
        </Button>
      </div>

      {showCycleForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Nouveau cycle</CardTitle>
          </CardHeader>
          <CardContent>
            <form className="flex flex-wrap items-end gap-3" onSubmit={createCycle}>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="cyc-code">Code *</Label>
                <Input
                  id="cyc-code"
                  value={cycleForm.code}
                  onChange={(e) => setCycleForm((f) => ({ ...f, code: e.target.value }))}
                  placeholder="SEC"
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="cyc-name">Nom *</Label>
                <Input
                  id="cyc-name"
                  value={cycleForm.name}
                  onChange={(e) => setCycleForm((f) => ({ ...f, name: e.target.value }))}
                  placeholder="Secondaire premier cycle"
                />
              </div>
              <Button type="submit" disabled={saving}>
                {saving ? "Création…" : "Créer"}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setShowCycleForm(false)}>
                Annuler
              </Button>
            </form>
            {error ? <p className="mt-2 text-sm font-medium text-destructive">{error}</p> : null}
          </CardContent>
        </Card>
      ) : null}

      {tree.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun cycle configuré pour cet établissement.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {tree.map((cycle) => (
            <Card key={cycle.id}>
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle>
                  {cycle.name} <span className="text-sm font-normal text-muted-foreground">({cycle.code})</span>
                </CardTitle>
                <Button variant="outline" size="sm" onClick={() => openLevelForm(cycle.id)}>
                  <Plus className="mr-1 h-3.5 w-3.5" />
                  Niveau
                </Button>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {cycle.levels.length === 0 && cycle.programs.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    Aucun niveau. Cliquez « Niveau » pour en ajouter (ex. 6ème, 5ème…).
                  </p>
                ) : null}
                {cycle.levels.map((level) => (
                  <LevelRow key={level.id} level={level} onMove={moveLevel} />
                ))}
                {cycle.programs.map((program) => (
                  <div key={program.id} className="ml-3 flex flex-col gap-2 border-l border-border pl-3">
                    <span className="text-sm font-medium">
                      {program.name} <span className="text-muted-foreground">({program.code})</span>
                    </span>
                    {program.levels.map((level) => (
                      <LevelRow key={level.id} level={level} onMove={moveLevel} />
                    ))}
                  </div>
                ))}

                {levelCycleId === cycle.id ? (
                  <form className="mt-1 flex flex-wrap items-end gap-3 rounded-md border border-border p-3" onSubmit={(e) => createLevel(e, cycle)}>
                    <div className="flex flex-col gap-1.5">
                      <Label htmlFor={`lvl-code-${cycle.id}`}>Code niveau *</Label>
                      <Input
                        id={`lvl-code-${cycle.id}`}
                        value={levelForm.code}
                        onChange={(e) => setLevelForm((f) => ({ ...f, code: e.target.value }))}
                        placeholder="6EME"
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <Label htmlFor={`lvl-name-${cycle.id}`}>Nom niveau *</Label>
                      <Input
                        id={`lvl-name-${cycle.id}`}
                        value={levelForm.name}
                        onChange={(e) => setLevelForm((f) => ({ ...f, name: e.target.value }))}
                        placeholder="6ème"
                      />
                    </div>
                    <Button type="submit" size="sm" disabled={levelSaving}>
                      {levelSaving ? "Création…" : "Ajouter"}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setLevelCycleId(null)}>
                      Annuler
                    </Button>
                    {levelError ? <p className="w-full text-sm font-medium text-destructive">{levelError}</p> : null}
                  </form>
                ) : null}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
