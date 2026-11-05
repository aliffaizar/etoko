import { Building2, Warehouse as WarehouseIcon } from 'lucide-react';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { StockWorkspaceProps } from '@/types';

export type StockScopePickerProps = Pick<
    StockWorkspaceProps,
    'branch' | 'warehouse' | 'warehouses' | 'branches' | 'may_choose_branch'
> & {
    /** Applies the chosen scope, normally `useTableQuery().setFilters`. */
    onChange: (next: Record<string, string | null>) => void;
};

/**
 * Where an inventory screen is looking: which branch, and which of its
 * warehouses (P1-E4-T3).
 *
 * Deliberately not part of `FilterBar`. A filter narrows a list and can be
 * cleared; stock is always somewhere specific, so there is no "all warehouses"
 * figure to fall back to — adding up two warehouses' shelves would produce a
 * number nobody can go and count. The selectors therefore name a place rather
 * than offering an empty option, and `useTableQuery`'s `scopeKeys` keeps
 * "Reset filter" from moving somebody to a different warehouse.
 *
 * The branch selector only appears for central staff. Branch staff have
 * exactly one branch and are shown it as text, because a selector with one
 * option is a question with no answer to give (BR-3).
 */
export function StockScopePicker({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    onChange,
}: StockScopePickerProps) {
    return (
        <div className="flex flex-col gap-3 rounded-lg border bg-muted/40 p-3 sm:flex-row sm:items-end">
            <div className="grid gap-1.5 sm:w-64">
                <Label
                    htmlFor="stock-scope-branch"
                    className="text-xs text-muted-foreground"
                >
                    Cabang
                </Label>
                {may_choose_branch ? (
                    <Select
                        value={String(branch.id)}
                        onValueChange={(next) =>
                            // A branch change drops the warehouse: the one on
                            // screen belongs to the branch being left.
                            onChange({ branch: next, warehouse: null })
                        }
                    >
                        <SelectTrigger
                            id="stock-scope-branch"
                            aria-label="Cabang"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {branches.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                ) : (
                    <p
                        id="stock-scope-branch"
                        className="flex items-center gap-2 text-sm font-medium"
                    >
                        <Building2
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        {branch.name}
                        <span className="font-mono text-xs font-normal text-muted-foreground">
                            {branch.code}
                        </span>
                    </p>
                )}
            </div>

            <div className="grid gap-1.5 sm:w-64">
                <Label
                    htmlFor="stock-scope-warehouse"
                    className="text-xs text-muted-foreground"
                >
                    Gudang
                </Label>
                {warehouses.length > 1 ? (
                    <Select
                        value={String(warehouse.id)}
                        onValueChange={(next) => onChange({ warehouse: next })}
                    >
                        <SelectTrigger
                            id="stock-scope-warehouse"
                            aria-label="Gudang"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {warehouses.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                ) : (
                    <p
                        id="stock-scope-warehouse"
                        className="flex items-center gap-2 text-sm font-medium"
                    >
                        <WarehouseIcon
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        {warehouse.name}
                        <span className="font-mono text-xs font-normal text-muted-foreground">
                            {warehouse.code}
                        </span>
                    </p>
                )}
            </div>
        </div>
    );
}
