import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BranchOperatingHourController from '@/actions/App/Http/Controllers/Admin/BranchOperatingHourController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BranchOperatingHour } from '@/types';

type BranchOperatingHoursCardProps = {
    branchId: number;
    hours: BranchOperatingHour[];
    canUpdate: boolean;
};

const DEFAULT_OPENS_AT = '08:00';
const DEFAULT_CLOSES_AT = '17:00';

/**
 * The branch's week, submitted in one go (P1-E2-T4).
 *
 * All seven days always travel together: the server keeps exactly one row per
 * day, so a partial submission is refused. Marking a day closed hides its times
 * and clears them on save.
 */
export function BranchOperatingHoursCard({
    branchId,
    hours,
    canUpdate,
}: BranchOperatingHoursCardProps) {
    const [week, setWeek] = useState<BranchOperatingHour[]>(hours);

    const updateDay = (
        dayOfWeek: number,
        changes: Partial<BranchOperatingHour>,
    ): void => {
        setWeek((current) =>
            current.map((day) =>
                day.day_of_week === dayOfWeek ? { ...day, ...changes } : day,
            ),
        );
    };

    const applyToAllOpenDays = (): void => {
        const monday = week.find((day) => day.day_of_week === 1);

        if (!monday || monday.is_closed) {
            return;
        }

        setWeek((current) =>
            current.map((day) =>
                day.is_closed
                    ? day
                    : {
                          ...day,
                          opens_at: monday.opens_at,
                          closes_at: monday.closes_at,
                      },
            ),
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Jam operasional</CardTitle>
                <CardDescription>
                    Jam ini menentukan apakah cabang terlihat buka di
                    storefront. Tandai hari tutup untuk mengosongkan jamnya.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...BranchOperatingHourController.update.form(branchId)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col divide-y rounded-lg border">
                                {week.map((day, index) => (
                                    <DayRow
                                        key={day.day_of_week}
                                        day={day}
                                        index={index}
                                        disabled={!canUpdate}
                                        errors={errors}
                                        onChange={(changes) =>
                                            updateDay(day.day_of_week, changes)
                                        }
                                    />
                                ))}
                            </div>

                            {canUpdate && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        Simpan Jam Operasional
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={applyToAllOpenDays}
                                    >
                                        Samakan dengan hari Senin
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

function DayRow({
    day,
    index,
    disabled,
    errors,
    onChange,
}: {
    day: BranchOperatingHour;
    index: number;
    disabled: boolean;
    errors: Record<string, string | undefined>;
    onChange: (changes: Partial<BranchOperatingHour>) => void;
}) {
    const prefix = `hours.${index}`;

    return (
        <div className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4">
            <input
                type="hidden"
                name={`${prefix}.day_of_week`}
                value={day.day_of_week}
            />
            <input
                type="hidden"
                name={`${prefix}.is_closed`}
                value={day.is_closed ? '1' : '0'}
            />

            <p className="w-24 shrink-0 text-sm font-medium">{day.day_label}</p>

            {day.is_closed ? (
                <p className="flex-1 text-sm text-muted-foreground">
                    Tutup sepanjang hari
                </p>
            ) : (
                <div className="flex flex-1 flex-wrap items-start gap-3">
                    <div className="grid gap-1">
                        <Label
                            htmlFor={`${prefix}.opens_at`}
                            className="text-xs text-muted-foreground"
                        >
                            Buka
                        </Label>
                        <Input
                            id={`${prefix}.opens_at`}
                            name={`${prefix}.opens_at`}
                            type="time"
                            disabled={disabled}
                            value={day.opens_at ?? DEFAULT_OPENS_AT}
                            onChange={(event) =>
                                onChange({ opens_at: event.target.value })
                            }
                            className="w-32"
                        />
                    </div>

                    <div className="grid gap-1">
                        <Label
                            htmlFor={`${prefix}.closes_at`}
                            className="text-xs text-muted-foreground"
                        >
                            Tutup
                        </Label>
                        <Input
                            id={`${prefix}.closes_at`}
                            name={`${prefix}.closes_at`}
                            type="time"
                            disabled={disabled}
                            value={day.closes_at ?? DEFAULT_CLOSES_AT}
                            onChange={(event) =>
                                onChange({ closes_at: event.target.value })
                            }
                            className="w-32"
                        />
                    </div>

                    <div className="w-full">
                        <InputError message={errors[`${prefix}.opens_at`]} />
                        <InputError message={errors[`${prefix}.closes_at`]} />
                    </div>
                </div>
            )}

            <div className="flex shrink-0 items-center gap-2">
                <Checkbox
                    id={`${prefix}.closed_toggle`}
                    checked={day.is_closed}
                    disabled={disabled}
                    onCheckedChange={(checked) =>
                        onChange({
                            is_closed: checked === true,
                            opens_at: day.opens_at ?? DEFAULT_OPENS_AT,
                            closes_at: day.closes_at ?? DEFAULT_CLOSES_AT,
                        })
                    }
                />
                <Label
                    htmlFor={`${prefix}.closed_toggle`}
                    className="text-sm font-normal"
                >
                    Tutup
                </Label>
            </div>
        </div>
    );
}
