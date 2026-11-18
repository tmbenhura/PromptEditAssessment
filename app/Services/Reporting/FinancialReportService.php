<?php

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\CostCalculator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

/**
 * Operator report: what users were charged, what Pixelforge charged, and the
 * profit, per UTC day and per user, reconciled against GET /v1/usage.
 *
 * Money is summed with bcmath on exact decimal strings, never floats or SQL SUM().
 * Pixelforge bills a generation on the UTC day it completed, so days here are
 * grouped by completion time too. Generations Pixelforge never accepted are left
 * out: they cost nothing and Pixelforge has no record of them.
 */
class FinancialReportService
{
    private const ZERO = '0.000000000';

    public function __construct(
        protected PixelforgeClient $pixelforgeClient,
        protected CostCalculator $costCalculator,
    ) {}

    /**
     * @param  string  $startDate  Y-m-d, inclusive (UTC)
     * @param  string  $endDate  Y-m-d, inclusive (UTC)
     * @return array{
     *     dailyReport: list<array<string, mixed>>,
     *     userStats: list<array<string, mixed>>,
     *     summary: array<string, mixed>,
     * }
     */
    public function generate(string $startDate, string $endDate): array
    {
        $days = $this->localTotals($startDate, $endDate);
        $pixelforgeCosts = $this->pixelforgeCosts($startDate, $endDate);

        $dailyReport = [];
        $summary = [
            'total_generations' => 0,
            'total_provider_cost_usd' => self::ZERO,
            'total_user_spend_usd' => self::ZERO,
            'total_profit_usd' => self::ZERO,
            'total_pixelforge_cost_usd' => self::ZERO,
            'markup_multiplier' => $this->costCalculator->markupMultiplier(),
            'pixelforge_available' => $pixelforgeCosts !== null,
            'all_days_reconciled' => true,
        ];

        foreach (Carbon::parse($startDate, 'UTC')->daysUntil(Carbon::parse($endDate, 'UTC')) as $date) {
            $key = $date->format('Y-m-d');
            $day = $days[$key] ?? $this->emptyTotals();

            $pixelforgeCost = $pixelforgeCosts[$key] ?? self::ZERO;
            $reconciled = bccomp($day['provider_cost_usd'], $pixelforgeCost, 9) === 0;

            $dailyReport[] = [
                'date' => $key,
                'generations_count' => $day['generations_count'],
                'succeeded_count' => $day['succeeded_count'],
                'failed_count' => $day['failed_count'],
                'provider_cost_usd' => $day['provider_cost_usd'],
                'user_spend_usd' => $day['user_spend_usd'],
                'profit_usd' => $day['profit_usd'],
                'pixelforge_cost_usd' => $pixelforgeCost,
                'reconciled' => $reconciled,
                'difference_usd' => bcsub($day['provider_cost_usd'], $pixelforgeCost, 9),
                'users' => $this->userRows($day['users']),
            ];

            $summary['total_generations'] += $day['generations_count'];
            $summary['total_provider_cost_usd'] = bcadd($summary['total_provider_cost_usd'], $day['provider_cost_usd'], 9);
            $summary['total_user_spend_usd'] = bcadd($summary['total_user_spend_usd'], $day['user_spend_usd'], 9);
            $summary['total_profit_usd'] = bcadd($summary['total_profit_usd'], $day['profit_usd'], 9);
            $summary['total_pixelforge_cost_usd'] = bcadd($summary['total_pixelforge_cost_usd'], $pixelforgeCost, 9);
            $summary['all_days_reconciled'] = $summary['all_days_reconciled'] && $reconciled;
        }

        return [
            // Newest day first.
            'dailyReport' => array_reverse($dailyReport),
            'userStats' => $this->userStats($days),
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, array<string, mixed>> Totals keyed by UTC date
     */
    private function localTotals(string $startDate, string $endDate): array
    {
        $generations = Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereBetween('pixelforge_completed_at', [
                Carbon::parse($startDate, 'UTC')->startOfDay(),
                Carbon::parse($endDate, 'UTC')->endOfDay(),
            ])
            ->get(['id', 'user_id', 'status', 'actual_provider_cost', 'actual_user_cost', 'pixelforge_completed_at']);

        $days = [];

        foreach ($generations as $generation) {
            $key = $generation->pixelforge_completed_at->setTimezone('UTC')->format('Y-m-d');
            $days[$key] ??= $this->emptyTotals();

            $this->add($days[$key], $generation);

            $days[$key]['users'][$generation->user_id] ??= $this->emptyTotals();
            $this->add($days[$key]['users'][$generation->user_id], $generation);
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function add(array &$totals, Generation $generation): void
    {
        $succeeded = $generation->status === GenerationStatus::Succeeded;

        $totals['generations_count']++;
        $totals[$succeeded ? 'succeeded_count' : 'failed_count']++;

        // Failed generations are recorded at zero, but only count what succeeded to be safe.
        if ($succeeded) {
            $totals['provider_cost_usd'] = bcadd($totals['provider_cost_usd'], $generation->actual_provider_cost, 9);
            $totals['user_spend_usd'] = bcadd($totals['user_spend_usd'], $generation->actual_user_cost, 9);
            $totals['profit_usd'] = bcsub($totals['user_spend_usd'], $totals['provider_cost_usd'], 9);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'generations_count' => 0,
            'succeeded_count' => 0,
            'failed_count' => 0,
            'provider_cost_usd' => self::ZERO,
            'user_spend_usd' => self::ZERO,
            'profit_usd' => self::ZERO,
            'users' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $totalsByUser
     * @return list<array<string, mixed>>
     */
    private function userRows(array $totalsByUser): array
    {
        if ($totalsByUser === []) {
            return [];
        }

        $users = User::query()->whereKey(array_keys($totalsByUser))->get(['id', 'name', 'email'])->keyBy('id');

        $rows = [];
        foreach ($totalsByUser as $userId => $totals) {
            $rows[] = [
                'id' => $userId,
                'name' => $users[$userId]->name ?? 'Deleted user',
                'email' => $users[$userId]->email ?? '',
                'generations_count' => $totals['generations_count'],
                'provider_cost_usd' => $totals['provider_cost_usd'],
                'user_spend_usd' => $totals['user_spend_usd'],
                'profit_usd' => $totals['profit_usd'],
            ];
        }

        usort($rows, fn (array $a, array $b) => bccomp($b['user_spend_usd'], $a['user_spend_usd'], 9));

        return $rows;
    }

    /**
     * Every user, with totals across the selected range, biggest spender first.
     *
     * @param  array<string, array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function userStats(array $days): array
    {
        $totals = [];

        foreach ($days as $day) {
            foreach ($day['users'] as $userId => $userTotals) {
                $totals[$userId] ??= $this->emptyTotals();

                $totals[$userId]['generations_count'] += $userTotals['generations_count'];
                $totals[$userId]['provider_cost_usd'] = bcadd($totals[$userId]['provider_cost_usd'], $userTotals['provider_cost_usd'], 9);
                $totals[$userId]['user_spend_usd'] = bcadd($totals[$userId]['user_spend_usd'], $userTotals['user_spend_usd'], 9);
                $totals[$userId]['profit_usd'] = bcsub($totals[$userId]['user_spend_usd'], $totals[$userId]['provider_cost_usd'], 9);
            }
        }

        $stats = User::query()->get(['id', 'name', 'email', 'balance_usd'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'balance_usd' => (string) $user->balance_usd,
                'generations_count' => $totals[$user->id]['generations_count'] ?? 0,
                'provider_cost_usd' => $totals[$user->id]['provider_cost_usd'] ?? self::ZERO,
                'user_spend_usd' => $totals[$user->id]['user_spend_usd'] ?? self::ZERO,
                'profit_usd' => $totals[$user->id]['profit_usd'] ?? self::ZERO,
            ])
            ->all();

        usort($stats, fn (array $a, array $b) => bccomp($b['user_spend_usd'], $a['user_spend_usd'], 9));

        return $stats;
    }

    /**
     * What Pixelforge says it billed per UTC day, or null if it can't be reached.
     *
     * @return array<string, numeric-string>|null
     */
    private function pixelforgeCosts(string $startDate, string $endDate): ?array
    {
        try {
            $usage = $this->pixelforgeClient->getUsage($startDate, $endDate);
        } catch (PixelforgeException|ConnectionException) {
            return null;
        }

        $costs = [];
        foreach ($usage['data'] ?? [] as $entry) {
            $costs[(string) $entry['date']] = (string) ($entry['cost_usd'] ?? self::ZERO);
        }

        return $costs;
    }
}
