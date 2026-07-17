"use client";

import { useCallback, useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Briefcase, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

interface Employee {
  id: string;
  firstName: string;
  lastName: string;
  position: string;
  department: string | null;
  hireDate: string;
  salary: number | null;
  status: string;
  user?: { email: string; firstName: string; lastName: string } | null;
}

interface LeaveRequest {
  id: string;
  employeeId: string;
  startDate: string;
  endDate: string;
  reason: string;
  status: "PENDING" | "APPROVED" | "REJECTED";
  employee?: { firstName: string; lastName: string; user?: { firstName: string; lastName: string } | null };
}

const LEAVE_STATUS_LABELS: Record<LeaveRequest["status"], string> = {
  PENDING: "En attente",
  APPROVED: "Approuvée",
  REJECTED: "Rejetée",
};

function formatAmount(value: number | null): string {
  if (value === null || value === undefined) return "—";
  return new Intl.NumberFormat("fr-FR").format(value) + " FCFA";
}

export default function HRPage() {
  // Employés
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [empMeta, setEmpMeta] = useState<{ page: number; limit: number; total: number; totalPages: number } | undefined>();
  const [empPage, setEmpPage] = useState(1);
  const [isLoadingEmployees, setIsLoadingEmployees] = useState(true);
  const [empError, setEmpError] = useState<string | null>(null);

  const [showEmployeeForm, setShowEmployeeForm] = useState(false);
  const [employeeForm, setEmployeeForm] = useState({ firstName: "", lastName: "", position: "", department: "", hireDate: "", salary: "" });
  const [employeeFormError, setEmployeeFormError] = useState<string | null>(null);
  const [isSubmittingEmployee, setIsSubmittingEmployee] = useState(false);

  // Paie
  const [payrollMonth, setPayrollMonth] = useState(() => new Date().toISOString().slice(0, 7));
  const [payroll, setPayroll] = useState<any[]>([]);
  const [isLoadingPayroll, setIsLoadingPayroll] = useState(false);
  const [payrollMessage, setPayrollMessage] = useState<string | null>(null);
  const [payrollError, setPayrollError] = useState<string | null>(null);
  const [isGeneratingPayroll, setIsGeneratingPayroll] = useState(false);

  // Congés
  const [leaves, setLeaves] = useState<LeaveRequest[]>([]);
  const [isLoadingLeaves, setIsLoadingLeaves] = useState(true);
  const [leaveError, setLeaveError] = useState<string | null>(null);
  const [showLeaveForm, setShowLeaveForm] = useState(false);
  const [leaveForm, setLeaveForm] = useState({ startDate: "", endDate: "", reason: "" });
  const [leaveFormError, setLeaveFormError] = useState<string | null>(null);
  const [isSubmittingLeave, setIsSubmittingLeave] = useState(false);

  const loadEmployees = useCallback(() => {
    setIsLoadingEmployees(true);
    resourceClient
      .get<{ items: Employee[]; total: number; page: number; limit: number; totalPages: number }>("/hr/employees", {
        params: { page: empPage, limit: 20 },
      })
      .then((response) => {
        setEmployees(response.data.items);
        setEmpMeta({ page: response.data.page, limit: response.data.limit, total: response.data.total, totalPages: response.data.totalPages });
      })
      .catch((requestError: any) => setEmpError(requestError.response?.data?.message ?? "Impossible de charger les employés."))
      .finally(() => setIsLoadingEmployees(false));
  }, [empPage]);

  const loadLeaves = useCallback(() => {
    setIsLoadingLeaves(true);
    resourceClient
      .get<LeaveRequest[]>("/hr/leave-requests")
      .then((response) => setLeaves(response.data))
      .catch((requestError: any) => setLeaveError(requestError.response?.data?.message ?? "Impossible de charger les demandes de congé."))
      .finally(() => setIsLoadingLeaves(false));
  }, []);

  const loadPayroll = useCallback((month: string) => {
    setIsLoadingPayroll(true);
    setPayrollError(null);
    resourceClient
      .get(`/hr/payroll/${month}`)
      .then((response) => setPayroll(Array.isArray(response.data) ? response.data : []))
      .catch((requestError: any) => setPayrollError(requestError.response?.data?.message ?? "Impossible de charger les bulletins de paie."))
      .finally(() => setIsLoadingPayroll(false));
  }, []);

  useEffect(() => {
    loadEmployees();
  }, [loadEmployees]);

  useEffect(() => {
    loadLeaves();
  }, [loadLeaves]);

  useEffect(() => {
    loadPayroll(payrollMonth);
  }, [payrollMonth, loadPayroll]);

  async function handleCreateEmployee(event: React.FormEvent) {
    event.preventDefault();
    setEmployeeFormError(null);
    setIsSubmittingEmployee(true);
    try {
      await resourceClient.post("/hr/employees", {
        firstName: employeeForm.firstName,
        lastName: employeeForm.lastName,
        position: employeeForm.position,
        department: employeeForm.department || undefined,
        hireDate: employeeForm.hireDate,
        salary: employeeForm.salary ? Number(employeeForm.salary) : undefined,
      });
      setEmployeeForm({ firstName: "", lastName: "", position: "", department: "", hireDate: "", salary: "" });
      setShowEmployeeForm(false);
      setEmpPage(1);
      loadEmployees();
    } catch (requestError: any) {
      setEmployeeFormError(requestError.response?.data?.message ?? "Impossible de créer cet employé.");
    } finally {
      setIsSubmittingEmployee(false);
    }
  }

  async function handleGeneratePayroll() {
    setIsGeneratingPayroll(true);
    setPayrollMessage(null);
    setPayrollError(null);
    try {
      const { data } = await resourceClient.post("/hr/payroll/generate", { month: payrollMonth });
      setPayrollMessage(data?.message ?? "Génération lancée.");
      loadPayroll(payrollMonth);
    } catch (requestError: any) {
      setPayrollError(requestError.response?.data?.message ?? "Impossible de générer la paie.");
    } finally {
      setIsGeneratingPayroll(false);
    }
  }

  async function handleCreateLeave(event: React.FormEvent) {
    event.preventDefault();
    setLeaveFormError(null);
    setIsSubmittingLeave(true);
    try {
      await resourceClient.post("/hr/leave-requests", leaveForm);
      setLeaveForm({ startDate: "", endDate: "", reason: "" });
      setShowLeaveForm(false);
      loadLeaves();
    } catch (requestError: any) {
      setLeaveFormError(requestError.response?.data?.message ?? "Impossible d'envoyer cette demande de congé.");
    } finally {
      setIsSubmittingLeave(false);
    }
  }

  async function handleApproveLeave(id: string) {
    try {
      await resourceClient.put(`/hr/leave-requests/${id}/approve`);
      loadLeaves();
    } catch (requestError: any) {
      setLeaveError(requestError.response?.data?.message ?? "Impossible d'approuver cette demande.");
    }
  }

  const employeeColumns: ColumnDef<Employee>[] = [
    {
      id: "name",
      header: "Employé",
      cell: ({ row }) => `${row.original.lastName} ${row.original.firstName}`,
    },
    { accessorKey: "position", header: "Poste" },
    { id: "department", header: "Département", cell: ({ row }) => row.original.department ?? "—" },
    {
      id: "hireDate",
      header: "Date d'embauche",
      cell: ({ row }) => new Date(row.original.hireDate).toLocaleDateString("fr-FR"),
    },
    { id: "salary", header: "Salaire", cell: ({ row }) => formatAmount(row.original.salary) },
    { accessorKey: "status", header: "Statut" },
  ];

  const leaveColumns: ColumnDef<LeaveRequest>[] = [
    {
      id: "employee",
      header: "Employé",
      cell: ({ row }) =>
        row.original.employee ? `${row.original.employee.lastName} ${row.original.employee.firstName}` : row.original.employeeId,
    },
    {
      id: "period",
      header: "Période",
      cell: ({ row }) =>
        `${new Date(row.original.startDate).toLocaleDateString("fr-FR")} → ${new Date(row.original.endDate).toLocaleDateString("fr-FR")}`,
    },
    { accessorKey: "reason", header: "Motif" },
    {
      id: "status",
      header: "Statut",
      cell: ({ row }) => LEAVE_STATUS_LABELS[row.original.status],
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) =>
        row.original.status === "PENDING" ? (
          <Button size="sm" variant="outline" onClick={() => handleApproveLeave(row.original.id)}>
            Approuver
          </Button>
        ) : null,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">RH & Paie</h1>
          <p className="text-sm text-muted-foreground">Gestion des employés, bulletins de paie et congés</p>
        </div>
        <Button onClick={() => setShowEmployeeForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouvel employé
        </Button>
      </div>

      {showEmployeeForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Nouvel employé</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleCreateEmployee} className="flex flex-col gap-3">
              <div className="grid gap-3 md:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-firstname">Prénom</Label>
                  <Input
                    id="emp-firstname"
                    required
                    value={employeeForm.firstName}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, firstName: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-lastname">Nom</Label>
                  <Input
                    id="emp-lastname"
                    required
                    value={employeeForm.lastName}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, lastName: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-position">Poste</Label>
                  <Input
                    id="emp-position"
                    required
                    value={employeeForm.position}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, position: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-department">Département</Label>
                  <Input
                    id="emp-department"
                    value={employeeForm.department}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, department: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-hiredate">Date d'embauche</Label>
                  <Input
                    id="emp-hiredate"
                    type="date"
                    required
                    value={employeeForm.hireDate}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, hireDate: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="emp-salary">Salaire (FCFA)</Label>
                  <Input
                    id="emp-salary"
                    type="number"
                    min={0}
                    value={employeeForm.salary}
                    onChange={(event) => setEmployeeForm((current) => ({ ...current, salary: event.target.value }))}
                  />
                </div>
              </div>
              {employeeFormError ? <p className="text-sm font-medium text-destructive">{employeeFormError}</p> : null}
              <div className="flex gap-2">
                <Button type="submit" disabled={isSubmittingEmployee}>
                  {isSubmittingEmployee ? "Enregistrement…" : "Créer l'employé"}
                </Button>
                <Button type="button" variant="outline" onClick={() => setShowEmployeeForm(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Briefcase className="h-4 w-4" />
            Employés
          </CardTitle>
          <CardDescription>Personnel et contrats</CardDescription>
        </CardHeader>
        <CardContent>
          {empError ? <p className="mb-3 text-sm font-medium text-destructive">{empError}</p> : null}
          <DataTable
            columns={employeeColumns}
            data={employees}
            meta={empMeta}
            isLoading={isLoadingEmployees}
            onPageChange={setEmpPage}
            emptyLabel="Aucun employé enregistré pour le moment"
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Paie</CardTitle>
          <CardDescription>Bulletins de salaire</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="payroll-month">Mois</Label>
              <Input
                id="payroll-month"
                type="month"
                value={payrollMonth}
                onChange={(event) => setPayrollMonth(event.target.value)}
              />
            </div>
            <Button disabled={isGeneratingPayroll} onClick={handleGeneratePayroll}>
              {isGeneratingPayroll ? "Génération…" : "Générer la paie du mois"}
            </Button>
          </div>
          {payrollMessage ? <p className="text-sm font-medium text-primary">{payrollMessage}</p> : null}
          {payrollError ? <p className="text-sm font-medium text-destructive">{payrollError}</p> : null}
          {isLoadingPayroll ? (
            <p className="text-sm text-muted-foreground">Chargement des bulletins…</p>
          ) : payroll.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Aucun bulletin de paie pour {payrollMonth}. Le module de calcul des bulletins n'est pas encore implémenté côté serveur.
            </p>
          ) : (
            <pre className="overflow-x-auto rounded-md border border-border bg-secondary/40 p-3 text-xs">
              {JSON.stringify(payroll, null, 2)}
            </pre>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-base">Congés</CardTitle>
              <CardDescription>Demandes et approbations</CardDescription>
            </div>
            <Button variant="outline" size="sm" onClick={() => setShowLeaveForm((value) => !value)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouvelle demande
            </Button>
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {showLeaveForm ? (
            <form onSubmit={handleCreateLeave} className="flex flex-col gap-3 rounded-md border border-border p-3">
              <div className="grid gap-3 md:grid-cols-3">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="leave-start">Début</Label>
                  <Input
                    id="leave-start"
                    type="date"
                    required
                    value={leaveForm.startDate}
                    onChange={(event) => setLeaveForm((current) => ({ ...current, startDate: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="leave-end">Fin</Label>
                  <Input
                    id="leave-end"
                    type="date"
                    required
                    value={leaveForm.endDate}
                    onChange={(event) => setLeaveForm((current) => ({ ...current, endDate: event.target.value }))}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="leave-reason">Motif</Label>
                  <Input
                    id="leave-reason"
                    required
                    value={leaveForm.reason}
                    onChange={(event) => setLeaveForm((current) => ({ ...current, reason: event.target.value }))}
                  />
                </div>
              </div>
              {leaveFormError ? <p className="text-sm font-medium text-destructive">{leaveFormError}</p> : null}
              <div className="flex gap-2">
                <Button type="submit" disabled={isSubmittingLeave}>
                  {isSubmittingLeave ? "Envoi…" : "Envoyer la demande"}
                </Button>
                <Button type="button" variant="outline" onClick={() => setShowLeaveForm(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          ) : null}

          {leaveError ? <p className="text-sm font-medium text-destructive">{leaveError}</p> : null}
          {isLoadingLeaves ? (
            <p className="text-sm text-muted-foreground">Chargement des demandes de congé…</p>
          ) : (
            <DataTable columns={leaveColumns} data={leaves} isLoading={false} emptyLabel="Aucune demande de congé" />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
