"use client";

import { useCallback, useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { CycleNode, LevelNode } from "@/types/structure";
import { Button } from "@/components/ui/button";
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

  const loadTree = useCallback(async () => {
    setIsLoading(true);
    const { data } = await resourceClient.get<CycleNode[]>("/structure/tree");
    setTree(data);
    setIsLoading(false);
  }, []);

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
        <Button size="sm">
          <Plus className="mr-2 h-4 w-4" />
          Nouveau cycle
        </Button>
      </div>

      {tree.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun cycle configuré pour cet établissement.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {tree.map((cycle) => (
            <Card key={cycle.id}>
              <CardHeader>
                <CardTitle>
                  {cycle.name} <span className="text-sm font-normal text-muted-foreground">({cycle.code})</span>
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
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
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
