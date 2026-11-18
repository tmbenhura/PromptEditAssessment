<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminReportRequest;
use App\Services\Reporting\FinancialReportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __construct(
        protected FinancialReportService $reportService,
    ) {}

    public function __invoke(AdminReportRequest $request): Response
    {
        $startDate = $request->startDate();
        $endDate = $request->endDate();

        return Inertia::render('Admin/Reports', [
            ...$this->reportService->generate($startDate, $endDate),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
