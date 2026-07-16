export type BulletinStatus = "draft" | "published" | "sent";

export const BULLETIN_STATUS_LABELS: Record<BulletinStatus, string> = {
  draft: "Brouillon",
  published: "Publié",
  sent: "Envoyé",
};

export interface Bulletin {
  id: string;
  studentId: string;
  periodId: string;
  classroomId: string;
  status: BulletinStatus;
  verificationCode: string;
  pdfUrl: string | null;
  createdAt: string;
  data?: {
    student?: { firstName: string; lastName: string; matricule: string };
    average?: number;
  };
}

export interface GenerateBatchResult {
  total: number;
  success: number;
  errors: number;
  results: Array<{ studentId: string; matricule: string; status: "success" | "error"; bulletinId?: string; error?: string }>;
}
