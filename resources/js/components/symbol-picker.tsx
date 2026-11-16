import { useId, useMemo, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const maxMatches = 50;

/**
 * Symbols that start with the query first, then those that merely contain it.
 */
function matchSymbols(symbols: string[], query: string): string[] {
    const needle = query.trim().toUpperCase();

    if (needle === '') {
        return symbols.slice(0, maxMatches);
    }

    const startsWith: string[] = [];
    const contains: string[] = [];

    for (const symbol of symbols) {
        if (symbol.startsWith(needle)) {
            startsWith.push(symbol);
        } else if (symbol.includes(needle)) {
            contains.push(symbol);
        }
    }

    return [...startsWith, ...contains].slice(0, maxMatches);
}

/**
 * A text input with a searchable dropdown of Binance futures symbols.
 */
export default function SymbolPicker({
    id,
    value,
    onChange,
    symbols,
}: {
    id: string;
    value: string;
    onChange: (symbol: string) => void;
    symbols: string[];
}) {
    const listId = useId();
    const [open, setOpen] = useState(false);
    const [highlighted, setHighlighted] = useState(0);
    const matches = useMemo(
        () => matchSymbols(symbols, value),
        [symbols, value],
    );
    const showList = open && matches.length > 0;

    function choose(symbol: string) {
        onChange(symbol);
        setOpen(false);
    }

    function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (!showList) {
            if (event.key === 'ArrowDown' && matches.length > 0) {
                event.preventDefault();
                setOpen(true);
                setHighlighted(0);
            }

            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlighted((index) => Math.min(index + 1, matches.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlighted((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(matches[Math.min(highlighted, matches.length - 1)]);
        } else if (event.key === 'Tab') {
            setOpen(false);
        }
    }

    return (
        <div className="relative">
            <Input
                id={id}
                role="combobox"
                aria-expanded={showList}
                aria-controls={listId}
                aria-autocomplete="list"
                value={value}
                onChange={(event) => {
                    onChange(event.target.value.toUpperCase());
                    setOpen(true);
                    setHighlighted(0);
                }}
                onFocus={() => setOpen(true)}
                onClick={() => setOpen(true)}
                onBlur={() => setOpen(false)}
                onKeyDown={handleKeyDown}
                placeholder={
                    symbols.length > 0 ? 'Search, e.g. BTC' : 'BTCUSDT'
                }
                autoComplete="off"
                spellCheck={false}
                required
            />
            {showList && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"
                >
                    {matches.map((symbol, index) => (
                        <li
                            key={symbol}
                            role="option"
                            aria-selected={index === highlighted}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                choose(symbol);
                            }}
                            onMouseEnter={() => setHighlighted(index)}
                            ref={(element) => {
                                if (index === highlighted) {
                                    element?.scrollIntoView({
                                        block: 'nearest',
                                    });
                                }
                            }}
                            className={cn(
                                'cursor-pointer rounded-sm px-2 py-1.5 tabular-nums',
                                index === highlighted &&
                                    'bg-accent text-accent-foreground',
                            )}
                        >
                            {symbol}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
