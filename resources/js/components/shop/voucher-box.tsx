import { Form, Link, router, usePage } from '@inertiajs/react';
import { TicketPercent, X } from 'lucide-react';
import { useState } from 'react';
import CartVoucherController from '@/actions/App/Http/Controllers/Shop/CartVoucherController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import type { BasketVoucher } from '@/types';

/**
 * The voucher field on the cart and checkout screens (P2-E5-T3).
 *
 * An applied voucher shows what it gives — or, when it no longer applies to
 * the basket, the server's sentence saying why — with a way to take it off. A
 * guest is asked to sign in first: every voucher counts against a
 * per-customer allowance.
 */
export function VoucherBox({
    voucher,
    error,
}: {
    voucher: BasketVoucher | null;
    /** A refusal from checkout, on the `voucher` field. */
    error?: string;
}) {
    const auth = usePage().props.auth as { user?: { id: number } } | undefined;
    const [removing, setRemoving] = useState(false);

    if (!auth?.user) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <TicketPercent className="size-4 shrink-0" aria-hidden />
                <span>
                    Punya kode voucher?{' '}
                    <Link href={login()} className="font-medium underline">
                        Masuk
                    </Link>{' '}
                    untuk memakainya.
                </span>
            </p>
        );
    }

    if (voucher) {
        const remove = (): void => {
            router.delete(CartVoucherController.destroy().url, {
                preserveScroll: true,
                onStart: () => setRemoving(true),
                onFinish: () => setRemoving(false),
            });
        };

        return (
            <div
                className={
                    voucher.is_accepted
                        ? 'rounded-md border border-dashed border-primary/50 bg-primary/5 p-3'
                        : 'rounded-md border border-dashed border-destructive/50 bg-destructive/5 p-3'
                }
            >
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="flex items-center gap-1.5 font-mono text-sm font-semibold">
                            <TicketPercent
                                className="size-4 shrink-0"
                                aria-hidden
                            />
                            {voucher.code}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {voucher.benefit}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7 shrink-0"
                        onClick={remove}
                        disabled={removing}
                        aria-label={`Lepas voucher ${voucher.code}`}
                    >
                        <X aria-hidden />
                    </Button>
                </div>
                {!voucher.is_accepted && voucher.rejection && (
                    <p className="mt-2 text-xs text-destructive">
                        {voucher.rejection} Lepas voucher untuk melanjutkan
                        tanpa potongan.
                    </p>
                )}
                <InputError message={error} className="mt-2" />
            </div>
        );
    }

    return (
        <Form
            {...CartVoucherController.store.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Label htmlFor="voucher_code">Kode voucher</Label>
                    <div className="flex gap-2">
                        <Input
                            id="voucher_code"
                            name="voucher_code"
                            maxLength={32}
                            placeholder="Masukkan kode"
                            className="font-mono uppercase"
                            autoComplete="off"
                            required
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Pakai
                        </Button>
                    </div>
                    <InputError message={errors.voucher_code ?? error} />
                </>
            )}
        </Form>
    );
}
