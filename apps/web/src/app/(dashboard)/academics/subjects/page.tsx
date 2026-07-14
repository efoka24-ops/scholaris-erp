"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { FileUp, Pencil, Plus, Trash2 } from "lucide-react";
import type { PaginationMeta } from "@scholaris/shared";
import { SUBJECT_CATEGORIES, SUBJECT_CATEGORY_LABELS, type SubjectCategoryValue } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { Subject } from "@/types/subjects";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/shared/data-table";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";

export default function SubjectsPage() {
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | undefined>();
  const [page, setPage] = useState(1);
  const [categoryFilter, setCategoryFilter] = useState<string>("");
  const [isLoading, setIsLoading] = useState(true);
  const [subjectToDelete, setSubjectToDelete] = useState<Subject | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<{ data: Subject[]; meta: PaginationMeta }>("/subjects", {
        params: { page, limit: 20, ...(categoryFilter ? { category: categoryFilter } : {}) },
      })
      .then((response) => {
        setSubjects(response.data.data);
        setMeta(response.data.meta);
      })
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les matières."),
      )
      .finally(() => setIsLoading(false));
  }, [page, categoryFilter]);

  useEffect(() => {
    load();
  }, [load]);

  async function handleDelete() {
    if (!subjectToDelete) return;
    setError(null);
    try {
      await resourceClient.delete(`/subjects/${subjectToDelete.id}`);
      setSubjectToDelete(null);
      load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de supprimer la matière.");
      setSubjectToDelete(null);
    }
  }

  const columns: ColumnDef<Subject>[] = [
    { accessorKey: "code", header: "Code" },
    { accessorKey: "name", header: "Matière" },
    {
      id: "category",
      header: "Catégorie",
      cell: ({ row }) => SUBJECT_CATEGORY_LABELS[row.original.category as SubjectCategoryValue] ?? row.original.category,
    },
    {
      id: "coefficient",
      header: "Coefficient",
      cell: ({ row }) => Number(row.original.coefficient),
    },
    { accessorKey: "weeklyHours", header: "Heures / semaine" },
    {
      id: "eliminatory",
      header: "Éliminatoire",
      cell: ({ row }) =>
        row.original.isEliminatory ? `Oui (seuil ${Number(row.original.eliminatoryThreshold)})` : "Non",
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <div className="flex justify-end gap-1">
          <Button asChild variant="outline" size="sm">
            <Link href={`/academics/subjects/${row.original.id}/edit`}>
              <Pencil className="h-3.5 w-3.5" />
            </Link>
          </Button>
          <Button variant="outline" size="sm" onClick={() => setSubjectToDelete(row.original)}>
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Matières</h1>
          <p className="text-sm text-muted-foreground">Référentiel des disciplines de l'établissement</p>
        </div>
        <div className="flex gap-2">
          <Button asChild variant="outline" size="sm">
            <Link href="/academics/subjects/import">
              <FileUp className="mr-2 h-4 w-4" />
              Importer Excel
            </Link>
          </Button>
          <Button asChild size="sm">
            <Link href="/academics/subjects/new">
              <Plus className="mr-2 h-4 w-4" />
              Nouvelle matière
            </Link>
          </Button>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <label htmlFor="category-filter" className="text-sm text-muted-foreground">
          Filtrer par catégorie
        </label>
        <select
          id="category-filter"
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={categoryFilter}
          onChange={(event) => {
            setPage(1);
            setCategoryFilter(event.target.value);
          }}
        >
          <option value="">Toutes les catégories</option>
          {SUBJECT_CATEGORIES.map((category) => (
            <option key={category} value={category}>
              {SUBJECT_CATEGORY_LABELS[category]}
            </option>
          ))}
        </select>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <DataTable
        columns={columns}
        data={subjects}
        meta={meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucune matière pour ce filtre"
      />

      <ConfirmDialog
        open={Boolean(subjectToDelete)}
        onOpenChange={(open) => {
          if (!open) setSubjectToDelete(null);
        }}
        title="Supprimer la matière"
        description={`Supprimer « ${subjectToDelete?.name ?? ""} » du référentiel ? (suppression logique)`}
        confirmLabel="Supprimer"
        destructive
        onConfirm={handleDelete}
      />
    </div>
  );
}
