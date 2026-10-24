<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Reports\Platform\PlatformReportRegistry;
use App\Reports\ReportExporter;
use Illuminate\Http\Request;

class PlatformReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        $definition = PlatformReportRegistry::find($report);
        abort_unless($definition, 404);
        abort_unless($request->user()->hasPlatformPermission($definition->permission()), 403);

        $filters = array_replace($definition->defaultFilters(), $request->validate([
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
        ]));

        AuditLog::record('platform_report.exported', null, ['report' => $definition->key(), 'format' => $format, 'filters' => array_filter($filters)], null);

        $name = sprintf('platform-%s-%s.%s', $definition->key(), now()->format('Ymd-His'), $format);

        return $format === 'csv'
            ? ReportExporter::csv($definition, $filters, $name)
            : ReportExporter::xlsx($definition, $filters, $name);
    }
}
