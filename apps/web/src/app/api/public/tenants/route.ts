import { NextRequest, NextResponse } from "next/server";
import { backendClient } from "@/lib/backend-client";

/** Route Handler publique : annuaire des établissements ouverts à la pré-inscription en ligne. */
export async function GET(request: NextRequest) {
  const search = request.nextUrl.searchParams.get("search") ?? undefined;
  try {
    const { data } = await backendClient.get("/public/tenants", { params: { search } });
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Impossible de charger la liste des établissements.";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
