"use client";

import { useCallback, useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Building, Plus, Wrench } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

type AssetCategory = "MOBILIER" | "EQUIPEMENT" | "BATIMENT" | "VEHICULE";
type AssetStatus = "ACTIF" | "ENDOMMAGE" | "HORS_SERVICE";

interface Asset {
  id: string;
  name: string;
  category: AssetCategory;
  acquisitionDate: string | null;
  acquisitionValue: number | null;
  status: AssetStatus;
  location: string | null;
  maintenances?: Array<{ id: string; date: string; description: string; cost: number | null }>;
}

const CATEGORY_LABELS: Record<AssetCategory, string> = {
  MOBILIER: "Mobilier",
  EQUIPEMENT: "Équipement",
  BATIMENT: "Bâtiment",
  VEHICULE: "Véhicule",
};

const STATUS_LABELS: Record<AssetStatus, string> = {
  ACTIF: "Actif",
  ENDOMMAGE: "Endommagé",
  HORS_SERVICE: "Hors service",
};

function formatAmount(value: number | null): string {
  if (value === null || value === undefined) return "—";
  return new Intl.NumberFormat("fr-FR").format(value) + " FCFA";
}

export default function AssetsPage() {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [meta, setMeta] = useState<{ page: number; limit: number; total: number; totalPages: number } | undefined>();
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: "", category: "MOBILIER" as AssetCategory, acquisitionDate: "", acquisitionValue: "", location: "" });
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [maintenanceAssetId, setMaintenanceAssetId] = useState<string | null>(null);
  const [maintenanceForm, setMaintenanceForm] = useState({ date: "", description: "", cost: "", technician: "" });
  const [maintenanceError, setMaintenanceError] = useState<string | null>(null);
  const [isSubmittingMaintenance, setIsSubmittingMaintenance] = useState(false);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<{ items: Asset[]; total: number; page: number; limit: number; totalPages: number }>("/assets", {
        params: { page, limit: 20 },
      })
      .then((response) => {
        setAssets(response.data.items);
        setMeta({ page: response.data.page, limit: response.data.limit, total: response.data.total, totalPages: response.data.totalPages });
      })
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger l'inventaire."))
      .finally(() => setIsLoading(false));
  }, [page]);

  useEffect(() => {
    load();
  }, [load]);

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/assets", {
        name: form.name,
        category: form.category,
        location: form.location || undefined,
        acquisitionDate: form.acquisitionDate || undefined,
        acquisitionValue: form.acquisitionValue ? Number(form.acquisitionValue) : undefined,
      });
      setForm({ name: "", category: "MOBILIER", acquisitionDate: "", acquisitionValue: "", location: "" });
      setShowForm(false);
      setPage(1);
      load();
    } catch (requestError: any) {
      setFormError(requestError.response?.data?.message ?? "Impossible d'ajouter ce bien.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleRecordMaintenance(event: React.FormEvent) {
    event.preventDefault();
    if (!maintenanceAssetId) return;
    setMaintenanceError(null);
    setIsSubmittingMaintenance(true);
    try {
      await resourceClient.post(`/assets/${maintenanceAssetId}/maintenance`, {
        date: maintenanceForm.date,
        description: maintenanceForm.description,
        cost: maintenanceForm.cost ? Number(maintenanceForm.cost) : undefined,
        technician: maintenanceForm.technician || undefined,
      });
      setMaintenanceAssetId(null);
      setMaintenanceForm({ date: "", description: "", cost: "", technician: "" });
      load();
    } catch (requestError: any) {
      setMaintenanceError(requestError.response?.data?.message ?? "Impossible d'enregistrer cette intervention.");
    } finally {
      setIsSubmittingMaintenance(false);
    }
  }

  const columns: ColumnDef<Asset>[] = [
    { accessorKey: "name", header: "Bien" },
    {
      id: "category",
      header: "Catégorie",
      cell: ({ row }) => CATEGORY_LABELS[row.original.category],
    },
    {
      id: "status",
      header: "État",
      cell: ({ row }) => STATUS_LABELS[row.original.status],
    },
    {
      id: "acquisitionValue",
      header: "Valeur d'acquisition",
      cell: ({ row }) => formatAmount(row.original.acquisitionValue),
    },
    { id: "location", header: "Localisation", cell: ({ row }) => row.original.location ?? "—" },
    {
      id: "maintenanceCount",
      header: "Interventions",
      cell: ({ row }) => row.original.maintenances?.length ?? 0,
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            setMaintenanceAssetId(row.original.id);
            setMaintenanceError(null);
          }}
        >
          <Wrench className="mr-1 h-3.5 w-3.5" />
          Planifier maintenance
        </Button>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Patrimoine</h1>
          <p className="text-sm text-muted-foreground">Inventaire et maintenance du patrimoine de l'établissement</p>
        </div>
        <Button onClick={() => setShowForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Ajouter un bien
        </Button>
      </div>

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Nouveau bien</CardTitle>
            <CardDescription>Mobilier, équipement, bâtiment ou véhicule</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleCreate} className="flex flex-col gap-3">
              <div className="grid gap-3 md:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-name">Nom</Label>
                  <Input
                    id="asset-name"
                    required
                    value={form.name}
                    onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-category">Catégorie</Label>
                  <select
                    id="asset-category"
                    className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                    value={form.category}
                    onChange={(event) => setForm((current) => ({ ...current, category: event.target.value as AssetCategory }))}
                  >
                    {(Object.keys(CATEGORY_LABELS) as AssetCategory[]).map((category) => (
                      <option key={category} value={category}>
                        {CATEGORY_LABELS[category]}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-date">Date d'acquisition</Label>
                  <Input
                    id="asset-date"
                    type="date"
                    value={form.acquisitionDate}
                    onChange={(event) => setForm((current) => ({ ...current, acquisitionDate: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-value">Valeur d'acquisition (FCFA)</Label>
                  <Input
                    id="asset-value"
                    type="number"
                    min={0}
                    value={form.acquisitionValue}
                    onChange={(event) => setForm((current) => ({ ...current, acquisitionValue: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5 md:col-span-2">
                  <Label htmlFor="asset-location">Localisation</Label>
                  <Input
                    id="asset-location"
                    value={form.location}
                    onChange={(event) => setForm((current) => ({ ...current, location: event.target.value }))}
                  />
                </div>
              </div>
              {formError ? <p className="text-sm font-medium text-destructive">{formError}</p> : null}
              <div className="flex gap-2">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Enregistrement…" : "Ajouter le bien"}
                </Button>
                <Button type="button" variant="outline" onClick={() => setShowForm(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      ) : null}

      {maintenanceAssetId ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Enregistrer une intervention de maintenance</CardTitle>
            <CardDescription>Bien : {assets.find((asset) => asset.id === maintenanceAssetId)?.name ?? maintenanceAssetId}</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleRecordMaintenance} className="flex flex-col gap-3">
              <div className="grid gap-3 md:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="maintenance-date">Date</Label>
                  <Input
                    id="maintenance-date"
                    type="date"
                    required
                    value={maintenanceForm.date}
                    onChange={(event) => setMaintenanceForm((current) => ({ ...current, date: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="maintenance-cost">Coût (FCFA)</Label>
                  <Input
                    id="maintenance-cost"
                    type="number"
                    min={0}
                    value={maintenanceForm.cost}
                    onChange={(event) => setMaintenanceForm((current) => ({ ...current, cost: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5 md:col-span-2">
                  <Label htmlFor="maintenance-description">Description</Label>
                  <Input
                    id="maintenance-description"
                    required
                    value={maintenanceForm.description}
                    onChange={(event) => setMaintenanceForm((current) => ({ ...current, description: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5 md:col-span-2">
                  <Label htmlFor="maintenance-technician">Technicien</Label>
                  <Input
                    id="maintenance-technician"
                    value={maintenanceForm.technician}
                    onChange={(event) => setMaintenanceForm((current) => ({ ...current, technician: event.target.value }))}
                  />
                </div>
              </div>
              {maintenanceError ? <p className="text-sm font-medium text-destructive">{maintenanceError}</p> : null}
              <div className="flex gap-2">
                <Button type="submit" disabled={isSubmittingMaintenance}>
                  {isSubmittingMaintenance ? "Enregistrement…" : "Enregistrer l'intervention"}
                </Button>
                <Button type="button" variant="outline" onClick={() => setMaintenanceAssetId(null)}>
                  Annuler
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      ) : null}

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Building className="h-4 w-4" />
            Inventaire
          </CardTitle>
          <CardDescription>Mobilier, équipements, bâtiments et véhicules</CardDescription>
        </CardHeader>
        <CardContent>
          <DataTable
            columns={columns}
            data={assets}
            meta={meta}
            isLoading={isLoading}
            onPageChange={setPage}
            emptyLabel="Aucun bien enregistré pour le moment"
          />
        </CardContent>
      </Card>
    </div>
  );
}
