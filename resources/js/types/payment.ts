import type { StatusTone } from '@/components/panel/status-badge';
import type { Paginated } from '@/types/data-table';

/** Mirrors `App\Enums\PaymentTransactionStatus` — the state of one attempt. */
export type PaymentState =
    | 'pending'
    | 'success'
    | 'failed'
    | 'expired'
    | 'refunded';

/** Mirrors `App\Enums\PaymentMethodType`. */
export type PaymentMethodType =
    | 'bank_transfer'
    | 'virtual_account'
    | 'qris'
    | 'e_wallet'
    | 'cod'
    | 'cash';

/**
 * How each payment state reads at a glance, in one place.
 *
 * Deliberately separate from `PAYMENT_STATUS_TONE` in `types/order.ts`: that
 * one is the order's standing with respect to money, this one is a single
 * attempt, and the two are allowed to disagree — a failed attempt on an order
 * that is still payable is the normal case.
 */
export const PAYMENT_STATE_TONE: Record<PaymentState, StatusTone> = {
    pending: 'warning',
    success: 'success',
    failed: 'danger',
    expired: 'danger',
    refunded: 'neutral',
};

/** A bank account a manual transfer should be sent to. */
export type PaymentBankAccount = {
    name: string;
    account_number: string;
    account_holder: string;
};

/**
 * A way of paying, as a choice on a screen.
 *
 * `is_eligible` and `ineligible_reason` come as a pair: an option the order
 * cannot use is shown disabled **with its reason**, because "COD tidak
 * tersedia untuk pesanan di atas Rp2.000.000" tells the customer what to do
 * and a missing option tells them nothing.
 */
export type PaymentMethodChoice = {
    id: number;
    code: string;
    name: string;
    type: PaymentMethodType;
    type_label: string;
    provider_code: string | null;
    instructions: string | null;
    logo_url: string | null;
    bank_account: PaymentBankAccount | null;
    fee_flat: number;
    fee_percent: number;
    expects_proof: boolean;
    collected_on_delivery: boolean;
    is_eligible: boolean;
    ineligible_reason: string | null;
    /** What this order would be charged for the method itself. */
    fee_amount: number;
    /** The order total plus that fee — the figure actually asked for. */
    charge_amount: number;
};

/** The methods a checkout offers, quoted for each way of receiving the goods. */
export type CheckoutPaymentMethods = {
    delivery: PaymentMethodChoice[];
    pickup: PaymentMethodChoice[];
};

/** One payment attempt, as the customer's own order page reads it. */
export type CustomerPayment = {
    id: number;
    reference: string;
    status: PaymentState;
    status_label: string;
    method_name: string;
    method_type: PaymentMethodType;
    method_type_label: string;
    amount: number;
    fee_amount: number;
    instructions: string | null;
    bank_account: PaymentBankAccount | null;
    va_number: string | null;
    qr_string: string | null;
    /** The QR picture the gateway rendered (QRIS, GoPay), while the attempt is open. */
    qr_image_url: string | null;
    payment_url: string | null;
    expires_at: string | null;
    has_overrun: boolean;
    accepts_proof: boolean;
    has_proof: boolean;
    proof_url: string | null;
    proof_note: string | null;
    proof_uploaded_at: string | null;
    failure_reason: string | null;
    paid_at: string | null;
    created_at: string | null;
};

/** The order a payment belongs to, as the panel's queue shows it. */
export type PaymentOrderSummary = {
    id: number;
    order_number: string;
    customer_name: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    grand_total: number;
};

/** One row of the panel's payment queue. */
export type PaymentRow = {
    id: number;
    reference: string;
    status: PaymentState;
    status_label: string;
    method_name: string;
    method_type: PaymentMethodType;
    method_type_label: string;
    amount: number;
    fee_amount: number;
    has_proof: boolean;
    proof_uploaded_at: string | null;
    expires_at: string | null;
    has_overrun: boolean;
    paid_at: string | null;
    created_at: string | null;
    order: PaymentOrderSummary | null;
    branch: { id: number; name: string } | null;
};

/** One payment, as the panel's detail screen reads it. */
export type PaymentDetail = PaymentRow & {
    external_id: string | null;
    va_number: string | null;
    qr_string: string | null;
    payment_url: string | null;
    proof_url: string | null;
    proof_note: string | null;
    failure_reason: string | null;
    verified_at: string | null;
    verified_by: string | null;
    raw_payload: Record<string, unknown> | null;
    instructions: string | null;
    bank_account: PaymentBankAccount | null;
    can: {
        settle?: boolean;
        refund?: boolean;
    };
};

export type PaginatedPayments = Paginated<PaymentRow>;
