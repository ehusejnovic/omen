import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { GridGrade, GridMode } from '@/types';

const gradeStyles: Record<GridGrade, string> = {
    'A+': 'bg-emerald-600 text-white dark:bg-emerald-500',
    A: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    'B+': 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    B: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    'C+': 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    C: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const modeStyles: Record<GridMode, string> = {
    normal: 'border-violet-300 text-violet-700 dark:border-violet-800 dark:text-violet-300',
    long: 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300',
    short: 'border-rose-300 text-rose-700 dark:border-rose-800 dark:text-rose-300',
};

export const modeLabels: Record<GridMode, string> = {
    normal: 'Normal',
    long: 'Long',
    short: 'Short',
};

export function GradeBadge({
    grade,
    className,
}: {
    grade: GridGrade;
    className?: string;
}) {
    return (
        <Badge
            className={cn(
                'w-9 border-transparent font-semibold tabular-nums',
                gradeStyles[grade],
                className,
            )}
        >
            {grade}
        </Badge>
    );
}

export function ModeBadge({ mode }: { mode: GridMode }) {
    return (
        <Badge variant="outline" className={modeStyles[mode]}>
            {modeLabels[mode]}
        </Badge>
    );
}
