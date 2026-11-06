import { Link, usePage } from '@inertiajs/react';
import { MapPin, Phone } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import catalog from '@/routes/shop/catalog';
import type { ShopContext } from '@/types';

/**
 * The storefront footer.
 *
 * It repeats the chosen branch's address on purpose: this is where somebody
 * scrolls to find out where they would collect an order, and the branch shown
 * here is the same one the header picker holds — never a company-wide address
 * that belongs to nobody.
 */
export function ShopFooter() {
    const page = usePage();
    const shop = page.props.shop as ShopContext | undefined;
    const branch = shop?.branch ?? null;
    const categories = shop?.categories ?? [];
    const year = new Date().getFullYear();

    return (
        <footer className="mt-16 border-t bg-muted/30">
            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-3">
                <div className="flex flex-col gap-3">
                    <AppLogo />
                    <p className="max-w-sm text-sm text-muted-foreground">
                        Satu toko online, banyak cabang. Semua produk tersedia
                        di katalog dengan harga yang sama; ketersediaan stok
                        mengikuti cabang yang kamu pilih.
                    </p>
                </div>

                <div className="flex flex-col gap-2">
                    <h2 className="text-sm font-semibold">Kategori</h2>
                    <ul className="flex flex-col gap-1.5">
                        {categories.slice(0, 5).map((category) => (
                            <li key={category.slug}>
                                <Link
                                    href={catalog.category(category.slug)}
                                    className="text-sm text-muted-foreground hover:text-foreground"
                                >
                                    {category.name}
                                </Link>
                            </li>
                        ))}
                        <li>
                            <Link
                                href={catalog.index()}
                                className="text-sm text-muted-foreground hover:text-foreground"
                            >
                                Semua produk
                            </Link>
                        </li>
                    </ul>
                </div>

                {branch && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">
                            Cabang {branch.name}
                        </h2>
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <MapPin
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden
                            />
                            <span>
                                {branch.address_line}
                                {branch.city_name
                                    ? `, ${branch.city_name}`
                                    : ''}
                            </span>
                        </p>
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Phone className="size-4 shrink-0" aria-hidden />
                            <span>
                                {branch.supports_pickup
                                    ? 'Bisa diambil langsung di cabang ini'
                                    : 'Pesanan dari cabang ini dikirim'}
                            </span>
                        </p>
                    </div>
                )}
            </div>

            <div className="border-t">
                <p className="mx-auto max-w-7xl px-4 py-4 text-xs text-muted-foreground">
                    © {year} {page.props.name}. Seluruh harga dalam Rupiah.
                </p>
            </div>
        </footer>
    );
}
