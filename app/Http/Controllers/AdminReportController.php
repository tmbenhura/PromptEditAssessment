<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __construct(
        protected PixelforgeClient $pixelforgeClient,
    ) {}

    public function __invoke(Request $request): Response
    {
        $startDate = (string) $request->query('start_date', now()->subDays(14)->format('Y-m-d'));
        $endDate = (string) $request->query('end_date', now()->format('Y-m-d'));

        // Query local succeeded generations within the date range
        $generations = Generation::query()
            ->whereNotNull('pixelforge_completed_at')
            ->whereBetween('pixelforge_completed_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ])
            ->get();

        // Group local generations by UTC date of completion
        /** @var array<string, array{date: string, generations: int, succeeded: int, failed: int, provider_cost_usd: numeric-string, user_spend_usd: numeric-string, profit_usd: numeric-string}> $localDaily */
        $localDaily = [];
        foreach ($generations as $gen) {
            $date = Carbon::parse($gen->pixelforge_completed_at)->setTimezone('UTC')->format('Y-m-d');
            if (! isset($localDaily[$date])) {
                $localDaily[$date] = [
                    'date' => $date,
                    'generations' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'provider_cost_usd' => '0.000000000',
                    'user_spend_usd' => '0.000000000',
                    'profit_usd' => '0.000000000',
                ];
            }

            $localDaily[$date]['generations']++;
            if ($gen->status === GenerationStatus::Succeeded) {
                $localDaily[$date]['succeeded']++;
            } else {
                $localDaily[$date]['failed']++;
            }

            $providerCost = $gen->actual_provider_cost;
            $userCost = $gen->actual_user_cost;

            $localDaily[$date]['provider_cost_usd'] = bcadd(
                $localDaily[$date]['provider_cost_usd'],
                $providerCost,
                9
            );
            $localDaily[$date]['user_spend_usd'] = bcadd(
                $localDaily[$date]['user_spend_usd'],
                $userCost,
                9
            );
            $profit = bcsub($userCost, $providerCost, 9);
            $localDaily[$date]['profit_usd'] = bcadd($localDaily[$date]['profit_usd'], $profit, 9);
        }

        // Fetch Pixelforge usage report for comparison
        $pixelforgeUsage = [];
        $pixelforgeAvailable = true;
        try {
            $usageResponse = $this->pixelforgeClient->getUsage($startDate, $endDate);
            foreach ($usageResponse['data'] ?? [] as $entry) {
                $pixelforgeUsage[$entry['date']] = $entry;
            }
        } catch (PixelforgeException) {
            $pixelforgeAvailable = false;
        }

        // Build reconciled daily report
        $dailyReport = [];
        $period = Carbon::parse($startDate)->toPeriod(Carbon::parse($endDate));

        /** @var numeric-string $totalLocalProviderCost */
        $totalLocalProviderCost = '0.000000000';
        /** @var numeric-string $totalUserSpend */
        $totalUserSpend = '0.000000000';
        /** @var numeric-string $totalProfit */
        $totalProfit = '0.000000000';
        $totalGenerations = 0;

        foreach ($period as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $local = $localDaily[$dateStr] ?? [
                'date' => $dateStr,
                'generations' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'provider_cost_usd' => '0.000000000',
                'user_spend_usd' => '0.000000000',
                'profit_usd' => '0.000000000',
            ];

            $pfData = $pixelforgeUsage[$dateStr] ?? null;
            /** @var numeric-string $pfCost */
            $pfCost = (string) ($pfData['cost_usd'] ?? '0.000000000');
            $isReconciled = bccomp($local['provider_cost_usd'], $pfCost, 9) === 0;

            $dailyReport[] = [
                'date' => $dateStr,
                'generations_count' => $local['generations'],
                'succeeded_count' => $local['succeeded'],
                'failed_count' => $local['failed'],
                'provider_cost_usd' => $local['provider_cost_usd'],
                'user_spend_usd' => $local['user_spend_usd'],
                'profit_usd' => $local['profit_usd'],
                'pixelforge_cost_usd' => $pfCost,
                'reconciled' => $isReconciled,
                'difference_usd' => bcsub($local['provider_cost_usd'], $pfCost, 9),
            ];

            $totalLocalProviderCost = bcadd($totalLocalProviderCost, $local['provider_cost_usd'], 9);
            $totalUserSpend = bcadd($totalUserSpend, $local['user_spend_usd'], 9);
            $totalProfit = bcadd($totalProfit, $local['profit_usd'], 9);
            $totalGenerations += $local['generations'];
        }

        // Reverse to show newest dates first
        $dailyReport = array_reverse($dailyReport);

        // Per user report
        $userStats = User::query()
            ->withCount('generations')
            ->get()
            ->map(function (User $user) {
                $userGenerations = $user->generations()->where('status', GenerationStatus::Succeeded)->get();
                /** @var numeric-string $providerCost */
                $providerCost = '0.000000000';
                /** @var numeric-string $userSpend */
                $userSpend = '0.000000000';

                foreach ($userGenerations as $gen) {
                    $providerCost = bcadd($providerCost, $gen->actual_provider_cost, 9);
                    $userSpend = bcadd($userSpend, $gen->actual_user_cost, 9);
                }

                $profit = bcsub($userSpend, $providerCost, 9);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'balance_usd' => (string) $user->balance_usd,
                    'generations_count' => $user->generations_count,
                    'provider_cost_usd' => $providerCost,
                    'user_spend_usd' => $userSpend,
                    'profit_usd' => $profit,
                ];
            });

        return Inertia::render('Admin/Reports', [
            'dailyReport' => $dailyReport,
            'userStats' => $userStats,
            'summary' => [
                'total_generations' => $totalGenerations,
                'total_provider_cost_usd' => $totalLocalProviderCost,
                'total_user_spend_usd' => $totalUserSpend,
                'total_profit_usd' => $totalProfit,
                'pixelforge_available' => $pixelforgeAvailable,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
