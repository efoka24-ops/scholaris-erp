"use client";

import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/use-auth";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import { resourceClient } from "@/lib/api-client";
import { Building2, Users, Calendar } from "lucide-react";

interface TenantData {
  id: string;
  name: string;
  type: string;
  status: string;
}

interface UsersStats {
  total: number;
  active: number;
  inactive: number;
  suspended: number;
}

interface AcademicYear {
  id: string;
  name: string;
  startDate: string;
  endDate: string;
  status: string;
}

export default function DashboardPage() {
  const { user, isLoading: authLoading } = useAuth();
  const [tenant, setTenant] = useState<TenantData | null>(null);
  const [usersStats, setUsersStats] = useState<UsersStats | null>(null);
  const [academicYear, setAcademicYear] = useState<AcademicYear | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (!user?.tenantId) return;

    const fetchDashboardData = async () => {
      try {
        setIsLoading(true);

        // Fetch tenant info
        const tenantResponse = await resourceClient.get<TenantData>(`/tenants/${user.tenantId}`);
        setTenant(tenantResponse.data);

        // Fetch users stats
        const usersResponse = await resourceClient.get<UsersStats>("/users/stats");
        setUsersStats(usersResponse.data);

        // Fetch active academic year
        const academicYearResponse = await resourceClient.get<AcademicYear[]>("/academic-years");
        const activeYears = academicYearResponse.data.filter(y => y.status === "ACTIVE");
        if (activeYears.length > 0) {
          setAcademicYear(activeYears[0]);
        }
      } catch (error) {
        console.error("Error fetching dashboard data:", error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchDashboardData();
  }, [user?.tenantId]);

  if (authLoading || isLoading) {
    return <LoadingSpinner label="Chargement du tableau de bord…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Tableau de bord</h1>
        <p className="text-sm text-muted-foreground">
          Connecté en tant que {user?.email} — {user?.permissions.length ?? 0} permission(s) résolue(s)
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {/* Widget Établissement */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Établissement</CardTitle>
            <Building2 className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {tenant ? (
              <>
                <div className="text-2xl font-bold">{tenant.name}</div>
                <p className="text-xs text-muted-foreground mt-1">
                  {tenant.type === "public" ? "Public" : "Privé"} •{" "}
                  {tenant.status === "active" ? "Actif" : "Inactif"}
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Chargement...</p>
            )}
          </CardContent>
        </Card>

        {/* Widget Utilisateurs */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Utilisateurs</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {usersStats ? (
              <>
                <div className="text-2xl font-bold">{usersStats.total}</div>
                <p className="text-xs text-muted-foreground mt-1">
                  {usersStats.active} actifs • {usersStats.inactive} inactifs
                  {usersStats.suspended > 0 && ` • ${usersStats.suspended} suspendus`}
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Chargement...</p>
            )}
          </CardContent>
        </Card>

        {/* Widget Année académique */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Année académique</CardTitle>
            <Calendar className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {academicYear ? (
              <>
                <div className="text-2xl font-bold">{academicYear.name}</div>
                <p className="text-xs text-muted-foreground mt-1">
                  {new Date(academicYear.startDate).toLocaleDateString("fr-FR")} →{" "}
                  {new Date(academicYear.endDate).toLocaleDateString("fr-FR")}
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Aucune année active</p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
