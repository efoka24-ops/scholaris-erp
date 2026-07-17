"use client";

import { useCallback, useEffect, useState } from "react";
import { Bus, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { Student } from "@/types/students";
import type { PaginatedResult } from "@scholaris/shared";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface TransportVehicle {
  id: string;
  name: string;
  capacity: number;
  status: string;
  routes?: { name: string }[];
}

interface TransportRoute {
  id: string;
  name: string;
  stops: string | null;
  schedule: string | null;
  vehicle?: { name: string } | null;
  subscriptions?: unknown[];
}

export default function TransportPage() {
  const [routes, setRoutes] = useState<TransportRoute[]>([]);
  const [vehicles, setVehicles] = useState<TransportVehicle[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [isLoadingRoutes, setIsLoadingRoutes] = useState(true);
  const [isLoadingVehicles, setIsLoadingVehicles] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showRouteForm, setShowRouteForm] = useState(false);
  const [routeName, setRouteName] = useState("");
  const [routeVehicleId, setRouteVehicleId] = useState("");
  const [routeStops, setRouteStops] = useState("");
  const [routeSchedule, setRouteSchedule] = useState("");
  const [isSubmittingRoute, setIsSubmittingRoute] = useState(false);

  const [showSubscriptionForm, setShowSubscriptionForm] = useState(false);
  const [subscriptionRouteId, setSubscriptionRouteId] = useState("");
  const [subscriptionStudentId, setSubscriptionStudentId] = useState("");
  const [subscriptionStop, setSubscriptionStop] = useState("");
  const [isSubmittingSubscription, setIsSubmittingSubscription] = useState(false);
  const [subscriptionMessage, setSubscriptionMessage] = useState<string | null>(null);

  const loadRoutes = useCallback(() => {
    setIsLoadingRoutes(true);
    resourceClient
      .get<TransportRoute[]>("/transport/routes")
      .then((response) => setRoutes(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les circuits."))
      .finally(() => setIsLoadingRoutes(false));
  }, []);

  const loadVehicles = useCallback(() => {
    setIsLoadingVehicles(true);
    resourceClient
      .get<TransportVehicle[]>("/transport/vehicles")
      .then((response) => setVehicles(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les véhicules."))
      .finally(() => setIsLoadingVehicles(false));
  }, []);

  useEffect(() => {
    loadRoutes();
    loadVehicles();
    resourceClient
      .get<PaginatedResult<Student>>("/students", { params: { limit: 100 } })
      .then((response) => setStudents(response.data.data));
  }, [loadRoutes, loadVehicles]);

  async function handleCreateRoute() {
    setError(null);
    setIsSubmittingRoute(true);
    try {
      await resourceClient.post("/transport/routes", {
        name: routeName,
        ...(routeVehicleId ? { vehicleId: routeVehicleId } : {}),
        ...(routeStops ? { stops: routeStops } : {}),
        ...(routeSchedule ? { schedule: routeSchedule } : {}),
      });
      setRouteName("");
      setRouteVehicleId("");
      setRouteStops("");
      setRouteSchedule("");
      setShowRouteForm(false);
      loadRoutes();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer le circuit.");
    } finally {
      setIsSubmittingRoute(false);
    }
  }

  async function handleSubscribe() {
    setError(null);
    setSubscriptionMessage(null);
    setIsSubmittingSubscription(true);
    try {
      await resourceClient.post("/transport/subscriptions", {
        routeId: subscriptionRouteId,
        studentId: subscriptionStudentId,
        ...(subscriptionStop ? { stopName: subscriptionStop } : {}),
      });
      setSubscriptionMessage("Élève inscrit au circuit.");
      setSubscriptionRouteId("");
      setSubscriptionStudentId("");
      setSubscriptionStop("");
      setShowSubscriptionForm(false);
      loadRoutes();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible d'inscrire l'élève au circuit.");
    } finally {
      setIsSubmittingSubscription(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Transport scolaire</h1>
          <p className="text-sm text-muted-foreground">Gestion des circuits, véhicules et abonnements transport</p>
        </div>
        <Button onClick={() => setShowSubscriptionForm((v) => !v)}>
          <Plus className="mr-2 h-4 w-4" />
          Inscrire un élève
        </Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
      {subscriptionMessage ? <p className="text-sm font-medium text-emerald-600">{subscriptionMessage}</p> : null}

      {showSubscriptionForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Inscrire un élève à un circuit</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap items-end gap-3">
            <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={subscriptionRouteId} onChange={(e) => setSubscriptionRouteId(e.target.value)}>
              <option value="">Circuit…</option>
              {routes.map((r) => (
                <option key={r.id} value={r.id}>{r.name}</option>
              ))}
            </select>
            <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={subscriptionStudentId} onChange={(e) => setSubscriptionStudentId(e.target.value)}>
              <option value="">Élève…</option>
              {students.map((s) => (
                <option key={s.id} value={s.id}>{s.lastName} {s.firstName}</option>
              ))}
            </select>
            <Input className="w-48" placeholder="Arrêt (optionnel)" value={subscriptionStop} onChange={(e) => setSubscriptionStop(e.target.value)} />
            <Button disabled={!subscriptionRouteId || !subscriptionStudentId || isSubmittingSubscription} onClick={handleSubscribe}>
              {isSubmittingSubscription ? "Inscription…" : "Inscrire"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-6 md:grid-cols-3">
        <Card className="md:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Circuits</CardTitle>
              <CardDescription>Routes et arrêts</CardDescription>
            </div>
            <Button size="sm" onClick={() => setShowRouteForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouveau circuit
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {showRouteForm ? (
              <div className="flex flex-col gap-2 rounded-md border border-border p-3">
                <Input placeholder="Nom du circuit" value={routeName} onChange={(e) => setRouteName(e.target.value)} />
                <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={routeVehicleId} onChange={(e) => setRouteVehicleId(e.target.value)}>
                  <option value="">Véhicule (optionnel)…</option>
                  {vehicles.map((v) => (
                    <option key={v.id} value={v.id}>{v.name}</option>
                  ))}
                </select>
                <Input placeholder="Arrêts (optionnel)" value={routeStops} onChange={(e) => setRouteStops(e.target.value)} />
                <Input placeholder="Horaires (optionnel)" value={routeSchedule} onChange={(e) => setRouteSchedule(e.target.value)} />
                <Button size="sm" disabled={!routeName || isSubmittingRoute} onClick={handleCreateRoute}>
                  {isSubmittingRoute ? "Création…" : "Créer le circuit"}
                </Button>
              </div>
            ) : null}

            {isLoadingRoutes ? (
              <LoadingSpinner label="Chargement…" />
            ) : routes.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Bus className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun circuit pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {routes.map((route) => (
                  <li key={route.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <div>
                      <p className="font-medium">{route.name}</p>
                      <p className="text-muted-foreground">{route.stops ?? "Arrêts non renseignés"}</p>
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {route.vehicle?.name ?? "Aucun véhicule"} • {route.subscriptions?.length ?? 0} élève(s)
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Véhicules</CardTitle>
            <CardDescription>Bus et minibus</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingVehicles ? (
              <LoadingSpinner label="Chargement…" />
            ) : vehicles.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Bus className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun véhicule pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {vehicles.map((vehicle) => (
                  <li key={vehicle.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <span>{vehicle.name}</span>
                    <span className="text-xs text-muted-foreground">{vehicle.capacity} places • {vehicle.status}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
