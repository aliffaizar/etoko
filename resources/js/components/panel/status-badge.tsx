import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * Semantic meaning of a status, independent of the entity it belongs to.
 */
export type StatusTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

/**
 * Tones map to theme tokens rather than raw colours so dark mode follows along.
 * `Badge` ships no success/warning variant, so those two are composed here once
 * instead of at every call site.
 */
const TONE_CLASS: Record<StatusTone, string> = {
    neutral: '',
    success:
        'border-transparent bg-emerald-600/10 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300',
    warning:
        'border-transparent bg-amber-500/10 text-amber-700 dark:bg-amber-400/15 dark:text-amber-300',
    danger: '',
    info: '',
};

const TONE_VARIANT: Record<
    StatusTone,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    neutral: 'secondary',
    success: 'outline',
    warning: 'outline',
    danger: 'destructive',
    info: 'default',
};

/**
 * One badge for every status column in the panels (user active, branch status,
 * order status, payment status), so the same state always looks the same.
 */
export function StatusBadge({
    label,
    tone = 'neutral',
    className,
}: {
    label: string;
    tone?: StatusTone;
    className?: string;
}) {
    return (
        <Badge
            variant={TONE_VARIANT[tone]}
            className={cn(TONE_CLASS[tone], className)}
        >
            {label}
        </Badge>
    );
}
