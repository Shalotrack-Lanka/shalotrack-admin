<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\Sim;
use App\Imports\SimsImport;
use App\Exports\SimImportTemplateExport;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class AddSimController extends Controller
{
    public function index()
    {
        $notActivatedSims = Sim::where('sim_status', 'Not Activated')->latest()->get();

        $activatedSims = Sim::where('sim_status', 'Activated')->latest()->get();

        return view('admin.master_pages.add_sim', compact('notActivatedSims', 'activatedSims'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sim_type'   => 'required|string|max:255',
            'sim_number' => 'required|digits:10|unique:sims,sim_number',
            'imsi'       => 'required|digits:15|unique:sims,imsi',
            'iccid'      => 'required|digits_between:19,20|unique:sims,iccid',
            'sim_status' => 'required|string|in:Activated,Not Activated',
        ], [
            'sim_number.digits' => 'SIM number must be exactly 10 digits.',
            'sim_number.unique' => 'This SIM number is already registered.',
            'imsi.digits'       => 'IMSI must be exactly 15 digits.',
            'imsi.unique'       => 'This IMSI is already registered.',
            'iccid.digits_between' => 'ICCID must be 19 or 20 digits.',
            'iccid.unique'      => 'This ICCID is already registered.',
        ]);

        Sim::create([
            'sim_number'           => $validated['sim_number'],
            'sim_type'             => $validated['sim_type'],
            'imsi'                 => $validated['imsi'],
            'iccid'                => $validated['iccid'],
            'activation_required'  => $request->boolean('activation_required'),
            'sim_status'           => $validated['sim_status'],
        ]);

        return redirect()->back()->with('success', 'SIM Product Registered Successfully!');
    }

    public function updateStatus(Request $request, Sim $sim)
    {
        $validated = $request->validate([
            'sim_status' => 'required|string|in:Activated,Not Activated',
        ]);

        $sim->update([
            'sim_status' => $validated['sim_status'],
        ]);

        return redirect()->back()->with('success', 'SIM Status Updated Successfully!');
    }

    public function exportNotActivated()
    {
        $sims = Sim::where('sim_status', 'Not Activated')->latest()->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.not_activated_sims_pdf', compact('sims'));

        return $pdf->download('not_activated_sims_' . now()->format('Y-m-d_His') . '.pdf');
    }

    public function exportActivated()
    {
        $sims = Sim::where('sim_status', 'Activated')->latest()->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.activated_sims_pdf', compact('sims'));

        return $pdf->download('activated_sims_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * Serves a blank .xlsx with the exact columns SimsImport expects,
     * plus one worked example row. sim_number/imsi/iccid columns are
     * forced to Text format so they don't get corrupted into scientific
     * notation the way the device IMEI template originally did.
     */
    public function downloadImportTemplate()
    {
        return Excel::download(
            new SimImportTemplateExport(),
            'sim_import_template.xlsx'
        );
    }

    /**
     * Bulk version of store() — same uniqueness rules on sim_number,
     * imsi, and iccid. A duplicate or malformed row is skipped and
     * reported, not fatal to the rest of the file.
     */
    public function importSims(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv|max:10240',
        ]);

        $import = new SimsImport();

        Excel::import($import, $request->file('excel_file'));

        return redirect()
            ->back()
            ->with([
                'import_success_count' => count($import->created),
                'import_failures'      => $import->failures(),
            ]);
    }
}