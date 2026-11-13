// Paiement mobile money (Orange Money, MTN MoMo) via Sungku ou le simulateur : demande, puis attente de la confirmation.
import { http as defaultHttp, type HttpClient } from './http';
import type { Payment, PaymentReference } from './types';
import { ulid } from '@/lib/ulid';

export type MobileChannel = 'orange_money' | 'mtn_momo';

export interface MobilePaymentInput {
  establishmentId: string;
  channel: MobileChannel;
  amountXaf: number;
  payerPhone: string;
  guarantorId: string;
  /** Clé d'idempotence conservée entre deux tentatives d'un même paiement : un double appui ne débite pas deux fois. */
  idempotencyKey?: string;
}

/** Référence de paiement unique de la famille (« FAM-0587 ») : retrouvée, ou émise si elle n'existe pas encore. */
export async function ensurePaymentReference(establishmentId: string, guarantorId: string, client: HttpClient = defaultHttp): Promise<string> {
  const existing = await client.get<{ data: PaymentReference[] }>('/payment-references', { 'filter[guarantor_id]': guarantorId }, { establishmentId });
  const found = existing.data[0];
  if (found) return found.reference;
  const created = await client.post<{ data: PaymentReference }>('/payment-references', { guarantor_id: guarantorId }, { establishmentId });
  return created.data.reference;
}

export async function requestMobilePayment(input: MobilePaymentInput, client: HttpClient = defaultHttp): Promise<Payment> {
  const reference = await ensurePaymentReference(input.establishmentId, input.guarantorId, client);
  const res = await client.post<{ data: Payment }>(
    '/payments',
    { channel: input.channel, amount_xaf: input.amountXaf, family_reference: reference, payer_phone: input.payerPhone },
    { establishmentId: input.establishmentId, idempotencyKey: input.idempotencyKey ?? ulid() },
  );
  return res.data;
}

export interface WaitOptions {
  intervalMs?: number;
  timeoutMs?: number;
  sleep?: (ms: number) => Promise<void>;
  signal?: AbortSignal;
}

/** Interroge le paiement jusqu'à sa confirmation, son échec ou l'expiration du délai (reste alors « en attente »). */
export async function waitForPayment(establishmentId: string, paymentId: string, opts: WaitOptions = {}, client: HttpClient = defaultHttp): Promise<Payment> {
  const interval = opts.intervalMs ?? 3000;
  const timeout = opts.timeoutMs ?? 120_000;
  const sleep = opts.sleep ?? ((ms: number) => new Promise<void>((r) => setTimeout(r, ms)));
  let waited = 0;
  let payment = (await client.get<{ data: Payment }>(`/payments/${paymentId}`, undefined, { establishmentId })).data;
  while (payment.status === 'pending' && waited < timeout && !opts.signal?.aborted) {
    await sleep(interval);
    waited += interval;
    payment = (await client.get<{ data: Payment }>(`/payments/${paymentId}`, undefined, { establishmentId })).data;
  }
  return payment;
}
