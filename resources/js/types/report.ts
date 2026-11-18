/** One user's totals, either for a single day or across the selected range. */
export interface UserTotals {
    id: number;
    name: string;
    email: string;
    generations_count: number;
    provider_cost_usd: string;
    user_spend_usd: string;
    profit_usd: string;
}

export interface DailyReportEntry {
    date: string;
    generations_count: number;
    succeeded_count: number;
    failed_count: number;
    provider_cost_usd: string;
    user_spend_usd: string;
    profit_usd: string;
    pixelforge_cost_usd: string;
    reconciled: boolean;
    difference_usd: string;
    users: UserTotals[];
}

export interface UserSpendEntry extends UserTotals {
    balance_usd: string;
}

export interface ReportSummary {
    total_generations: number;
    total_provider_cost_usd: string;
    total_user_spend_usd: string;
    total_profit_usd: string;
    total_pixelforge_cost_usd: string;
    pixelforge_available: boolean;
    all_days_reconciled: boolean;
}

export interface ReportDateRange {
    start_date: string;
    end_date: string;
}
