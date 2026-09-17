<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use App\Services\CustomerLinkService;
use Illuminate\Http\Request;

/**
 * Called BY the C# API at complaint-filing time (the reverse direction
 * of the existing customers-sync/vehicles-sync endpoints, which THIS app
 * calls INTO the API). Protected by the auth.adminsync middleware --
 * the shared secret both systems already agree on.
 */
class DealerLookupController extends Controller
{
    public function lookup(Request $request)
    {
        $email = (string) $request->query('email', '');
        $phone = (string) $request->query('phone', '');

        // Unsaved, in-memory instance -- never persisted. Reuses
        // CustomerLinkService's existing phone/email matching logic
        // exactly as-is rather than duplicating those rules here, since
        // that service is the one, documented source of truth for how
        // this soft-match works.
        $probe = new CustomerAd(['email' => $email, 'phone_number' => $phone]);

        $lead = CustomerLinkService::findDealerLead($probe);

        if ($lead === null || $lead->dealer === null) {
            // Valid, expected outcome -- no dealer matched. Not an error.
            return response()->json(['dealerId' => null, 'dealerName' => null]);
        }

        return response()->json([
            'dealerId' => $lead->dealer->id,
            'dealerName' => $lead->dealer->full_name,
        ]);
    }
}