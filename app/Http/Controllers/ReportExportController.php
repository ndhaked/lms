<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Reports\Report;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $library, string $report, string $format)
    {
        $definition = ReportRegistry::find($report);
        abort_unless($definition, 404);
        abort_unless($definition->module() === null || \App\Support\Entitlements::hasModule($definition->module()), 403, 'This report is not included in your plan.');

        // Viewing a report and exporting it are separate permissions.
        abort_unless($request->user()->can($definition->permission()) && $request->user()->can($definition->exportPermission()), 403);

        return $this->export($request, $definition, $format);
    }

    /** The student list export: needs students.view + students.export, not the Reports module. */
    public function students(Request $request, string $library, string $format)
    {
        abort_unless($request->user()->can('students.view') && $request->user()->can('students.export'), 403);

        return $this->export($request, ReportRegistry::find('student-registration'), $format, ['from' => '1970-01-01', 'until' => today()->toDateString()]);
    }

    /** The catalogue export: needs books.view + books.export and the Books module. */
    public function catalogue(Request $request, string $library, string $format)
    {
        abort_unless($request->user()->can('books.view') && $request->user()->can('books.export'), 403);

        return $this->export($request, new \App\Reports\BookCatalogueReport, $format, ['from' => '1970-01-01', 'until' => today()->toDateString()]);
    }

    protected function export(Request $request, Report $definition, string $format, array $force = [])
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        abort_unless(LibrarySettings::get('export_enabled'), 403, 'Exports are disabled for this library.');
        abort_unless(Entitlements::hasModule('exports'), 403, 'Data exports are not included in your plan.');

        $filters = array_replace($definition->defaultFilters(), $request->validate([
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
        ]), $force);

        AuditLog::record('report.exported', null, ['report' => $definition->key(), 'format' => $format, 'filters' => array_filter($filters)]);

        $name = sprintf('%s-%s-%s.%s', $definition->key(), Tenancy::slug(), now()->format('Ymd-His'), $format);

        return match ($format) {
            'csv' => ReportExporter::csv($definition, $filters, $name),
            'pdf' => ReportExporter::pdf($definition, $filters, $name),
            default => ReportExporter::xlsx($definition, $filters, $name),
        };
    }
}
