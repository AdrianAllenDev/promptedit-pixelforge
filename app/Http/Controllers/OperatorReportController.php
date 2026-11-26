<?php

namespace App\Http\Controllers;

use App\Pixelforge\OperatorReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OperatorReportController extends Controller
{
    public function __invoke(Request $request, OperatorReport $report): Response
    {
        $today = CarbonImmutable::today('UTC');
        $request->mergeIfMissing(['start' => $today->subDays(6)->toDateString(), 'end' => $today->toDateString()]);
        $range = $request->validate(['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start']]);
        $start = CarbonImmutable::parse($range['start'], 'UTC');
        $end = CarbonImmutable::parse($range['end'], 'UTC');
        if ($end >= $start->addDays(92)) {
            throw ValidationException::withMessages(['end' => 'Choose up to 92 days, including both dates.']);
        }

        return Inertia::render('OperatorReport', ['range' => $range, 'report' => $report->forRange($start, $end)]);
    }
}
