<?php

namespace Tests\Pgsql\Concerns;

use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Shared helpers for the device / SIM intake suites (manual add, barcode scan, Excel imports).
 *
 * Every row made here is rolled back by DatabaseTransactions. Numbers are made up and cannot clash with real
 * data: IMEIs start 9990, SIM numbers 070, IMSIs 41299, ICCIDs 89949.
 *
 * A test class that uses this trait must not declare properties called intakeSeq, syncMode or syncCalls.
 */
trait BuildsIntakeData
{
    protected int $intakeSeq = 0;

    /** What the fake app server answers when a new device is pushed to it: ok | down | error */
    protected string $syncMode = 'ok';

    /** Every device the portal pushed to the fake app server (the request bodies). */
    protected array $syncCalls = [];

    protected function fakeIntakeApi(): void
    {
        config([
            'services.shalotrack_api.base_url' => 'https://api.test',
            'services.shalotrack_api.sync_key' => 'sync-key-for-tests',
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            // The admin sidebar asks for the complaint count on every page: answer it quietly.
            if (str_contains($url, '/api/internal/complaints/')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'POST' && $url === 'https://api.test/api/internal/setup-devices-sync') {
                $this->syncCalls[] = $request->data();

                return match ($this->syncMode) {
                    'down'  => throw new ConnectionException('api is down'),
                    'error' => Http::response(['message' => 'boom'], 500),
                    default => Http::response(['ok' => true], 200),
                };
            }

            return Http::response([], 404);
        });
    }

    protected function nextIntakeNumber(): int
    {
        return ++$this->intakeSeq;
    }

    /** A 15-digit IMEI with a correct check digit (the scan screen refuses a wrong one). */
    protected function validImei(): string
    {
        $body = '9990' . str_pad((string) $this->nextIntakeNumber(), 10, '0', STR_PAD_LEFT);   // 14 digits

        $sum = 0;
        foreach (array_reverse(str_split($body)) as $i => $char) {
            $digit = (int) $char;
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $body . ((10 - $sum % 10) % 10);
    }

    /** The same IMEI with its last digit changed: the check digit is now wrong. */
    protected function imeiWithBadCheckDigit(string $imei): string
    {
        return substr($imei, 0, 14) . (((int) $imei[14] + 1) % 10);
    }

    /** @return array{sim_number:string, imsi:string, iccid:string} three made-up SIM identifiers, never used before */
    protected function simIdentity(): array
    {
        $n = $this->nextIntakeNumber();

        return [
            'sim_number' => '070' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),          // 10 digits
            'imsi'       => '41299' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),        // 15 digits
            'iccid'      => '89949' . str_pad((string) $n, 14, '0', STR_PAD_LEFT),        // 19 digits
        ];
    }

    protected function makeSim(string $status = 'Activated', array $overrides = []): Sim
    {
        return Sim::create(array_merge($this->simIdentity(), [
            'sim_type'            => 'Dialog',
            'activation_required' => false,
            'sim_status'          => $status,
        ], $overrides));
    }

    protected function makeDeviceType(array $overrides = []): DeviceType
    {
        return DeviceType::create(array_merge([
            'device_category' => 'AUTOTEST-INT-' . $this->nextIntakeNumber(),
            'model'           => 'Basic',
            'protocol'        => 'GT06',
            'features'        => [],
        ], $overrides));
    }

    protected function stockFor(DeviceType $type, int $quantity): Stock
    {
        return Stock::create([
            'device_type_id'          => $type->id,
            'device_category_type'    => $type->device_category . ' ' . $type->model,
            'company_available_stock' => $quantity,
        ]);
    }

    /**
     * A real .xlsx file, as an upload. Strings are written as TEXT cells (as the downloadable template does), so
     * a SIM number that starts with 0 keeps its zero. Numbers are written as real number cells, the way a careless
     * person would type them in Excel.
     *
     * @param array<int,string> $headings first row
     * @param array<int,array<int,mixed>> $rows each row is a list of cell values in heading order (null = empty cell)
     */
    protected function xlsxUpload(array $headings, array $rows, string $fileName = 'import.xlsx'): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        foreach (array_merge([$headings], $rows) as $r => $values) {
            foreach (array_values($values) as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $cell = Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                if (is_string($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'intake') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $fileName, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}