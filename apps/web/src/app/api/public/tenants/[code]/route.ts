import { NextRequest, NextResponse } from "next/server";
import { backendClient } from "@/lib/backend-client";

/** Route Handler publique : infos vitrine minimales d'un établissement par son code. */
export async function GET(_request: NextRequest, { params }: { params: { code: string } }) {
  try {
    const { data } = await backendClient.get(`/public/tenants/${encodeURIComponent(params.code)}`);
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Établissement introuvable";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
