/**
 * Formats a USD amount (number or decimal string) with a fixed number of decimals.
 */
export function formatUsd(
    amount: string | number,
    decimals: number = 9,
): string {
    const value = Number(amount);

    return Number.isNaN(value) ? '$0.00' : `$${value.toFixed(decimals)}`;
}
