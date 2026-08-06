<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\SetupShalotrackDevice;

class DealerDashboardController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Logged-in User
        |--------------------------------------------------------------------------
        */
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | 2. Find Dealer Linked With This Login
        |--------------------------------------------------------------------------
        */
        $dealer = $user->dealer;

        /*
        |--------------------------------------------------------------------------
        | 3. If Dealer Account Is Not Linked
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Blade එක variables expect කරන නිසා empty/default values
        | ඔක්කොම මෙතනත් යවනවා.
        |
        */
        if (!$dealer) {

            return view('dealer.dashboard', [
                'dealer'                  => null,
                'allocatedDevices'        => collect(),
                'allocatedDeviceCount'    => 0,
                'readyForActivationCount' => 0,
                'transfers'               => collect(),
                'totalStockReceived'      => 0,
                'latestStockReceived'     => 0,
                'recentActivity'          => collect(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. My Allocated Physical Devices
        |--------------------------------------------------------------------------
        |
        | setup_shalotrack_devices table එකේ
        | dealer_id = logged-in dealer id
        |
        | තියෙන actual IMEI devices විතරයි.
        |
        */
        $allocatedDevices = SetupShalotrackDevice::with('deviceType')
            ->where('dealer_id', $dealer->id)
            ->orderByDesc('shdevice_id')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | 5. Allocated Device Count
        |--------------------------------------------------------------------------
        */
        $allocatedDeviceCount = $allocatedDevices->count();


        /*
        |--------------------------------------------------------------------------
        | 6. Ready For Activation
        |--------------------------------------------------------------------------
        |
        | Dealerට allocate වෙලා තියෙන devices අතරින්
        | status = Not Activated
        |
        */
        $readyForActivationCount = $allocatedDevices
            ->filter(function ($device) {

                return strtolower(
                    trim((string) $device->status)
                ) === 'not activated';

            })
            ->count();


        /*
        |--------------------------------------------------------------------------
        | 7. Dealer Stock Transfer History
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Existing StockTransfer logic වෙනස් කරන්නේ නෑ.
        |
        */
        $transfers = StockTransfer::with([
                'stock.deviceType'
            ])
            ->where('dealer_id', $dealer->id)
            ->latest()
            ->get();


        /*
        |--------------------------------------------------------------------------
        | 8. Total Stock Received
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | 15 Dialog
        | 5 Plus
        | 10 Basic
        |
        | Total = 30
        |
        */
        $totalStockReceived = $transfers->sum('quantity');


        /*
        |--------------------------------------------------------------------------
        | 9. Latest Stock Received
        |--------------------------------------------------------------------------
        |
        | Latest StockTransfer record එකේ quantity එක.
        |
        */
        $latestTransfer = $transfers->first();

        $latestStockReceived = $latestTransfer
            ? $latestTransfer->quantity
            : 0;


        /*
        |--------------------------------------------------------------------------
        | 10. Recent Activities
        |--------------------------------------------------------------------------
        |
        | Latest 5 stock transfers dashboard activity feed එකට.
        |
        */
        $recentActivity = $transfers
            ->take(5)
            ->map(function ($transfer) {

                $deviceType = $transfer->stock?->deviceType;

                /*
                 * Device display name
                 *
                 * Example:
                 * SIM with Dialog
                 * V5 with GT06N
                 */

                if ($deviceType) {

                    $category = $deviceType->device_category ?? '';
                    $model    = $deviceType->model ?? '';

                    if ($category && $model) {

                        $deviceName = $category . ' with ' . $model;

                    } elseif ($model) {

                        $deviceName = $model;

                    } elseif ($category) {

                        $deviceName = $category;

                    } else {

                        $deviceName = 'device';
                    }

                } else {

                    $deviceName = 'device';
                }


                /*
                 * Activity text
                 */
                $text =
                    'Received '
                    . $transfer->quantity
                    . ' × '
                    . $deviceName;


                /*
                 * Add remarks if available
                 */
                if (!empty($transfer->remarks)) {

                    $text .= ' — ' . $transfer->remarks;
                }


                return [

                    'date' => $transfer->created_at,

                    'text' => $text,

                ];

            })
            ->values();


        /*
        |--------------------------------------------------------------------------
        | 11. Send Everything To Dealer Dashboard
        |--------------------------------------------------------------------------
        */
        return view('dealer.dashboard', compact(

            'dealer',

            'allocatedDevices',

            'allocatedDeviceCount',

            'readyForActivationCount',

            'transfers',

            'totalStockReceived',

            'latestStockReceived',

            'recentActivity'

        ));
    }
}