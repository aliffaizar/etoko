import { Banknote, CreditCard, QrCode, Smartphone, Truck } from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PaymentMethodChoice, PaymentMethodType } from '@/types/payment';

/**
 * Choosing how to pay (P2-E1-T3, T7).
 *
 * Used twice — on the checkout screen and on an order that still needs paying
 * — so that "pay a different way" after a failed attempt looks exactly like
 * choosing in the first place.
 *
 * **Ineligible methods stay on screen, disabled, with their reason.** Hiding
 * COD from an order that is over the ceiling tells the customer nothing; the
 * sentence the server wrote tells them to transfer instead. The reason is
 * always the server's, never reconstructed here — one set of rules, one
 * wording.
 */
const METHOD_ICON: Record<PaymentMethodType, typeof Banknote> = {
    bank_transfer: Banknote,
    virtual_account: CreditCard,
    qris: QrCode,
    e_wallet: Smartphone,
    cod: Truck,
    cash: Banknote,
};

export function PaymentMethodPicker({
    methods,
    selectedId,
    onSelect,
    name = 'payment_method_id',
    error,
    disabled = false,
}: {
    methods: PaymentMethodChoice[];
    selectedId: number | null;
    onSelect: (id: number) => void;
    name?: string;
    error?: string;
    disabled?: boolean;
}) {
    if (methods.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Belum ada metode pembayaran yang aktif. Hubungi cabang untuk
                menyelesaikan pembayaran pesanan ini.
            </p>
        );
    }

    return (
        <div>
            <ul className="flex flex-col gap-2">
                {methods.map((method) => {
                    const Icon = METHOD_ICON[method.type];
                    const selected = selectedId === method.id;
                    const unavailable = disabled || !method.is_eligible;

                    return (
                        <li key={method.id}>
                            <label
                                className={cn(
                                    'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                                    unavailable
                                        ? 'cursor-not-allowed opacity-60'
                                        : 'cursor-pointer hover:bg-accent',
                                    selected &&
                                        !unavailable &&
                                        'border-primary bg-primary/5',
                                )}
                            >
                                <input
                                    type="radio"
                                    name={name}
                                    className="mt-1 size-4 shrink-0 accent-primary"
                                    checked={selected}
                                    disabled={unavailable}
                                    onChange={() => onSelect(method.id)}
                                />

                                {method.logo_url ? (
                                    <img
                                        src={method.logo_url}
                                        alt=""
                                        className="mt-0.5 h-5 w-10 shrink-0 object-contain"
                                    />
                                ) : (
                                    <Icon
                                        aria-hidden
                                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                    />
                                )}

                                <span className="min-w-0 flex-1 text-sm">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {method.name}
                                        </span>
                                        {method.collected_on_delivery && (
                                            <StatusBadge
                                                label="Bayar saat terima"
                                                tone="info"
                                            />
                                        )}
                                    </span>

                                    <span className="block text-muted-foreground">
                                        {method.type_label}
                                        {method.fee_amount > 0 &&
                                            ` · biaya ${formatRupiah(method.fee_amount)}`}
                                    </span>

                                    {!method.is_eligible &&
                                        method.ineligible_reason && (
                                            <span className="mt-1 block text-destructive">
                                                {method.ineligible_reason}
                                            </span>
                                        )}
                                </span>

                                {method.fee_amount > 0 &&
                                    method.is_eligible && (
                                        <span className="shrink-0 text-sm font-medium tabular-nums">
                                            {formatRupiah(method.charge_amount)}
                                        </span>
                                    )}
                            </label>
                        </li>
                    );
                })}
            </ul>

            {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
        </div>
    );
}
