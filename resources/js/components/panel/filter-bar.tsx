import { Search, X } from 'lucide-react';
import { useEffect, useId, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { FilterField } from '@/types/data-table';

/**
 * Radix refuses an empty `SelectItem` value, so "no filter" carries a sentinel
 * that is translated back to an empty query parameter.
 */
const ALL_VALUE = '__all__';

const DEFAULT_DEBOUNCE_MS = 300;

export type FilterBarProps = {
    fields: FilterField[];
    /** Current query values, normally `useTableQuery().values`. */
    values: Record<string, string>;
    /** Applies the changed keys; the hook resets the page for us. */
    onChange: (next: Record<string, string | null>) => void;
    onReset: () => void;
    isFiltered?: boolean;
    /** Buttons shown at the end of the bar, e.g. "Tambah Pengguna". */
    actions?: ReactNode;
    debounceMs?: number;
    className?: string;
};

/**
 * The panel listing toolbar: a debounced search box, any number of select
 * filters, a reset button and a slot for page actions.
 *
 * Like `DataTable` it is entity-agnostic — a listing describes its filters as
 * data and this renders them — so every panel reuses it.
 */
export function FilterBar({
    fields,
    values,
    onChange,
    onReset,
    isFiltered = false,
    actions,
    debounceMs = DEFAULT_DEBOUNCE_MS,
    className,
}: FilterBarProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 md:flex-row md:items-center md:justify-between',
                className,
            )}
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                {fields.map((field) =>
                    field.type === 'search' ? (
                        <SearchFilter
                            key={field.key}
                            value={values[field.key] ?? ''}
                            label={field.label ?? field.placeholder ?? 'Cari'}
                            placeholder={field.placeholder ?? 'Cari…'}
                            debounceMs={debounceMs}
                            onChange={(value) =>
                                onChange({ [field.key]: value || null })
                            }
                        />
                    ) : (
                        <SelectFilter
                            key={field.key}
                            label={field.label}
                            allLabel={field.allLabel ?? `Semua ${field.label}`}
                            options={field.options}
                            className={field.className}
                            value={values[field.key] ?? ''}
                            onChange={(value) =>
                                onChange({ [field.key]: value || null })
                            }
                        />
                    ),
                )}

                {isFiltered && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                    >
                        <X aria-hidden />
                        Reset filter
                    </Button>
                )}
            </div>

            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

function SearchFilter({
    value,
    label,
    placeholder,
    debounceMs,
    onChange,
}: {
    value: string;
    label: string;
    placeholder: string;
    debounceMs: number;
    onChange: (value: string) => void;
}) {
    const inputId = useId();
    const [draft, setDraft] = useState(value);

    // Follow the URL when it changes elsewhere (reset button, back button).
    useEffect(() => setDraft(value), [value]);

    useEffect(() => {
        if (draft === value) {
            return;
        }

        const timeout = setTimeout(() => onChange(draft), debounceMs);

        return () => clearTimeout(timeout);
        // `onChange` is recreated each render by the query hook, so it is left
        // out on purpose; the draft and the applied value decide when to fire.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounceMs, draft, value]);

    return (
        <div className="relative w-full sm:w-72">
            <Label htmlFor={inputId} className="sr-only">
                {label}
            </Label>
            <Search
                aria-hidden
                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                id={inputId}
                type="search"
                value={draft}
                placeholder={placeholder}
                onChange={(event) => setDraft(event.target.value)}
                className="pl-8"
            />
        </div>
    );
}

function SelectFilter({
    label,
    allLabel,
    options,
    value,
    className,
    onChange,
}: {
    label: string;
    allLabel: string;
    options: Array<{ value: string; label: string }>;
    value: string;
    className?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Select
            value={value === '' ? ALL_VALUE : value}
            onValueChange={(next) => onChange(next === ALL_VALUE ? '' : next)}
        >
            <SelectTrigger
                aria-label={label}
                className={cn('w-full sm:w-48', className)}
            >
                <SelectValue placeholder={allLabel} />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value={ALL_VALUE}>{allLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
