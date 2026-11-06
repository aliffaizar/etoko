import { ImageOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import type { ShopProductImage } from '@/types';

type Props = {
    images: ShopProductImage[];
    productName: string;
    /** The chosen option; a photo tagged with it becomes the main image. */
    selectedVariantId: number | null;
};

/**
 * A product's photos (P1-E5-T6).
 *
 * When the shopper picks an option that has a photo of its own, the gallery
 * jumps to it: picking "Merah" and being left looking at the black one is the
 * kind of small lie that costs a return. Options without their own photo leave
 * the gallery where it is rather than resetting it, so browsing options does
 * not keep yanking the image back to the first one.
 */
export function ProductGallery({
    images,
    productName,
    selectedVariantId,
}: Props) {
    const [activeId, setActiveId] = useState<number | null>(
        images[0]?.id ?? null,
    );

    useEffect(() => {
        const tagged = images.find(
            (image) => image.variant_id === selectedVariantId,
        );

        if (tagged) {
            setActiveId(tagged.id);
        }
    }, [images, selectedVariantId]);

    const active = images.find((image) => image.id === activeId) ?? images[0];

    if (!active) {
        return (
            <div className="flex aspect-square items-center justify-center rounded-xl border bg-muted text-muted-foreground">
                <ImageOff className="size-10" aria-hidden />
                <span className="sr-only">
                    Belum ada foto untuk {productName}
                </span>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-hidden rounded-xl border bg-muted">
                <img
                    src={active.url}
                    alt={active.alt}
                    className="aspect-square w-full object-cover"
                />
            </div>

            {images.length > 1 && (
                <ul className="grid grid-cols-5 gap-2">
                    {images.map((image) => (
                        <li key={image.id}>
                            <button
                                type="button"
                                onClick={() => setActiveId(image.id)}
                                aria-current={image.id === active.id}
                                className={cn(
                                    'block w-full overflow-hidden rounded-lg border-2 transition-colors',
                                    image.id === active.id
                                        ? 'border-primary'
                                        : 'border-transparent hover:border-muted-foreground/40',
                                )}
                            >
                                <img
                                    src={image.url}
                                    alt=""
                                    loading="lazy"
                                    className="aspect-square w-full object-cover"
                                />
                                <span className="sr-only">
                                    Lihat foto {image.alt}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
