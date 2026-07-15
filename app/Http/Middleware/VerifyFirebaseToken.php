<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use App\Models\CustomerAd;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFirebaseToken
{
    /**
     * Handle an incoming request from the Android tracking client.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Grab the token from the "Authorization: Bearer <token>" header
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access Denied. Authorization Bearer token is missing.'
            ], 401);
        }

        try {
            // 2. Access the Firebase Auth component from the container
            $auth = app('firebase.auth');
            
            // 3. Verify the token token (checks signature, expiration, and project match)
            $verifiedIdToken = $auth->verifyIdToken($token);
            
            // 4. Extract the unique Firebase User ID (UID)
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            
            // 5. Look up the customer in your Supabase DB. If they don't exist yet, 
            // create their profile shell using the Firebase profile data.
            $customer = Customer::firstOrCreate(
                ['firebase_uid' => $firebaseUid],
                [
                    'email' => $verifiedIdToken->claims()->get('email'),
                    'fullName' => $verifiedIdToken->claims()->get('name') ?? 'New Driver',
                    'nicNumber' => '', 
                    'phoneNumber' => $verifiedIdToken->claims()->get('phone_number') ?? '',
                ]
            );

            // 6. Bind the validated customer object to the current request lifecycle
            $request->setUserResolver(function () use ($customer) {
                return $customer;
            });

            return $next($request);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Token is invalid or expired.',
                'debug' => $e->getMessage() // You can remove this in production
            ], 401);
        }
    }
}