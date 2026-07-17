"use client";

import { useCallback, useEffect, useState } from "react";
import { Trophy, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { TeacherOption } from "@/types/subjects";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface Club {
  id: string;
  name: string;
  description: string | null;
  supervisor?: { firstName: string; lastName: string } | null;
  members?: unknown[];
}

interface SchoolEvent {
  id: string;
  name: string;
  description: string | null;
  startDate: string;
  endDate: string;
  location: string | null;
  organizer?: { firstName: string; lastName: string } | null;
}

export default function SchoolLifePage() {
  const [clubs, setClubs] = useState<Club[]>([]);
  const [events, setEvents] = useState<SchoolEvent[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [isLoadingClubs, setIsLoadingClubs] = useState(true);
  const [isLoadingEvents, setIsLoadingEvents] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showClubForm, setShowClubForm] = useState(false);
  const [clubName, setClubName] = useState("");
  const [clubDescription, setClubDescription] = useState("");
  const [clubSupervisorId, setClubSupervisorId] = useState("");
  const [isSubmittingClub, setIsSubmittingClub] = useState(false);

  const [showEventForm, setShowEventForm] = useState(false);
  const [eventName, setEventName] = useState("");
  const [eventDescription, setEventDescription] = useState("");
  const [eventLocation, setEventLocation] = useState("");
  const [eventStart, setEventStart] = useState("");
  const [eventEnd, setEventEnd] = useState("");
  const [isSubmittingEvent, setIsSubmittingEvent] = useState(false);

  const loadClubs = useCallback(() => {
    setIsLoadingClubs(true);
    resourceClient
      .get<Club[]>("/school-life/clubs")
      .then((response) => setClubs(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les clubs."))
      .finally(() => setIsLoadingClubs(false));
  }, []);

  const loadEvents = useCallback(() => {
    setIsLoadingEvents(true);
    resourceClient
      .get<SchoolEvent[]>("/school-life/events")
      .then((response) => setEvents(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les événements."))
      .finally(() => setIsLoadingEvents(false));
  }, []);

  useEffect(() => {
    loadClubs();
    loadEvents();
    resourceClient.get<TeacherOption[]>("/subject-assignments/teachers").then((response) => setTeachers(response.data));
  }, [loadClubs, loadEvents]);

  async function handleCreateClub() {
    setError(null);
    setIsSubmittingClub(true);
    try {
      await resourceClient.post("/school-life/clubs", {
        name: clubName,
        ...(clubDescription ? { description: clubDescription } : {}),
        ...(clubSupervisorId ? { supervisorId: clubSupervisorId } : {}),
      });
      setClubName("");
      setClubDescription("");
      setClubSupervisorId("");
      setShowClubForm(false);
      loadClubs();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer le club.");
    } finally {
      setIsSubmittingClub(false);
    }
  }

  async function handleCreateEvent() {
    setError(null);
    setIsSubmittingEvent(true);
    try {
      await resourceClient.post("/school-life/events", {
        name: eventName,
        ...(eventDescription ? { description: eventDescription } : {}),
        ...(eventLocation ? { location: eventLocation } : {}),
        startDate: eventStart,
        endDate: eventEnd,
      });
      setEventName("");
      setEventDescription("");
      setEventLocation("");
      setEventStart("");
      setEventEnd("");
      setShowEventForm(false);
      loadEvents();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer l'événement.");
    } finally {
      setIsSubmittingEvent(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Clubs & Activités</h1>
          <p className="text-sm text-muted-foreground">Gestion des clubs scolaires, événements et activités parascolaires</p>
        </div>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Clubs scolaires</CardTitle>
              <CardDescription>Liste des clubs et inscriptions des élèves</CardDescription>
            </div>
            <Button size="sm" onClick={() => setShowClubForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouveau club
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {showClubForm ? (
              <div className="flex flex-col gap-2 rounded-md border border-border p-3">
                <Input placeholder="Nom du club" value={clubName} onChange={(e) => setClubName(e.target.value)} />
                <Input placeholder="Description (optionnel)" value={clubDescription} onChange={(e) => setClubDescription(e.target.value)} />
                <select
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                  value={clubSupervisorId}
                  onChange={(e) => setClubSupervisorId(e.target.value)}
                >
                  <option value="">Superviseur (optionnel)…</option>
                  {teachers.map((t) => (
                    <option key={t.id} value={t.id}>{t.firstName} {t.lastName}</option>
                  ))}
                </select>
                <Button size="sm" disabled={!clubName || isSubmittingClub} onClick={handleCreateClub}>
                  {isSubmittingClub ? "Création…" : "Créer le club"}
                </Button>
              </div>
            ) : null}

            {isLoadingClubs ? (
              <LoadingSpinner label="Chargement…" />
            ) : clubs.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Trophy className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun club pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {clubs.map((club) => (
                  <li key={club.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <div>
                      <p className="font-medium">{club.name}</p>
                      {club.description ? <p className="text-muted-foreground">{club.description}</p> : null}
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {club.supervisor ? `${club.supervisor.firstName} ${club.supervisor.lastName}` : "—"}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Événements scolaires</CardTitle>
              <CardDescription>Calendrier des événements et activités</CardDescription>
            </div>
            <Button size="sm" onClick={() => setShowEventForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouvel événement
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {showEventForm ? (
              <div className="flex flex-col gap-2 rounded-md border border-border p-3">
                <Input placeholder="Nom de l'événement" value={eventName} onChange={(e) => setEventName(e.target.value)} />
                <Input placeholder="Description (optionnel)" value={eventDescription} onChange={(e) => setEventDescription(e.target.value)} />
                <Input placeholder="Lieu (optionnel)" value={eventLocation} onChange={(e) => setEventLocation(e.target.value)} />
                <div className="flex gap-2">
                  <input type="date" className="h-10 flex-1 rounded-md border border-border bg-background px-3 text-sm" value={eventStart} onChange={(e) => setEventStart(e.target.value)} />
                  <input type="date" className="h-10 flex-1 rounded-md border border-border bg-background px-3 text-sm" value={eventEnd} onChange={(e) => setEventEnd(e.target.value)} />
                </div>
                <Button size="sm" disabled={!eventName || !eventStart || !eventEnd || isSubmittingEvent} onClick={handleCreateEvent}>
                  {isSubmittingEvent ? "Création…" : "Créer l'événement"}
                </Button>
              </div>
            ) : null}

            {isLoadingEvents ? (
              <LoadingSpinner label="Chargement…" />
            ) : events.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Trophy className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun événement pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {events.map((event) => (
                  <li key={event.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <div>
                      <p className="font-medium">{event.name}</p>
                      <p className="text-muted-foreground">
                        {new Date(event.startDate).toLocaleDateString("fr-FR")} – {new Date(event.endDate).toLocaleDateString("fr-FR")}
                        {event.location ? ` • ${event.location}` : ""}
                      </p>
                    </div>
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
