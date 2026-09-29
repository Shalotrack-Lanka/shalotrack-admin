<?php

namespace App\Support;

class ErrorClassifier
{
    /**
     * Turns a raw exception into a category + one-line summary a support
     * person can read in 2 seconds, before ever looking at a stack trace.
     * The full technical detail (file/line/exception class) is still logged
     * alongside this — this just tells you WHERE to start looking.
     */
    public static function classify(\Throwable $e): array
    {
        $class = get_class($e);

        return match (true) {
            $e instanceof \Illuminate\Database\QueryException => [
                'category' => 'Database',
                'summary'  => 'A database query failed — check the SQL, table names, or column names in the file/line below.',
            ],

            $e instanceof \Illuminate\Http\Client\ConnectionException => [
                'category' => 'External API — connection failed',
                'summary'  => 'Could not reach the ShaloTrack API at all. Check whether the API server/container is actually running.',
            ],

            $e instanceof \Illuminate\Http\Client\RequestException => [
                'category' => 'External API — bad response',
                'summary'  => 'The ShaloTrack API responded, but with an error status. Check the API logs for the same time — this is not an Admin-side bug.',
            ],

            str_contains($class, 'Firebase') => [
                'category' => 'Authentication',
                'summary'  => 'A Firebase authentication call failed. Check Firebase credentials/config, not application logic.',
            ],

            $e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException => [
                'category' => 'Routing / config',
                'summary'  => 'Code referenced a route name that doesn\'t exist. Check route definitions in routes/web.php — likely a typo in a route name.',
            ],

            $e instanceof \TypeError || $e instanceof \ErrorException => [
                'category' => 'Code bug',
                'summary'  => 'A PHP-level type mismatch or undefined-variable error. Go straight to the file/line below.',
            ],

            $e instanceof \Illuminate\Validation\ValidationException => [
                'category' => 'Validation',
                'summary'  => 'A form submission failed validation rules — this is expected behavior, not a bug, unless the rules themselves are wrong.',
            ],

            default => [
                'category' => 'Unclassified',
                'summary'  => 'Doesn\'t match a known pattern — read the exception class and message below directly.',
            ],
        };
    }
}