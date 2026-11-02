import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';

export function StatCard({
    label,
    value,
    hint,
    icon: Icon,
}: {
    label: string;
    value: number | string;
    hint?: string;
    icon?: LucideIcon;
}) {
    return (
        <Card>
            <CardContent className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="truncate text-sm text-muted-foreground">
                        {label}
                    </p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                        {typeof value === 'number'
                            ? formatNumber(value)
                            : value}
                    </p>
                    {hint && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {hint}
                        </p>
                    )}
                </div>
                {Icon && (
                    <span className="rounded-md bg-muted p-2 text-muted-foreground">
                        <Icon className="size-5" />
                    </span>
                )}
            </CardContent>
        </Card>
    );
}
