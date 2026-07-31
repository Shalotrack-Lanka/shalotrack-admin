<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use App\Support\ErrorClassifier;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Dashboard statistics update middleware
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\TraceRequestMiddleware::class);

        // Register the custom Firebase middleware alias
        $middleware->alias([
            'auth.firebase' => \App\Http\Middleware\VerifyFirebaseToken::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Logs every exception with a plain-English category + summary FIRST,
        // then the full technical detail — so a support person scanning
        // storage/logs/app_errors-*.log can tell in 2 seconds whether this is
        // a database problem, the API being down, a code bug, etc, before
        // ever reading a stack trace. The reference ID ties this log entry
        // directly to what the end user sees on the error page.
        $exceptions->reportable(function (\Throwable $e) {
            $referenceId = app('error.reference_id');
            $classification = ErrorClassifier::classify($e);

            Log::channel('app_errors')->error(
                "[{$classification['category']}] " . $e->getMessage(),
                [
                    'reference_id' => $referenceId,
                    'category'     => $classification['category'],
                    'summary'      => $classification['summary'],
                    'exception'    => get_class($e),
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                    'url'          => request()?->fullUrl(),
                    'method'       => request()?->method(),
                    'admin_id'     => auth()->id(),
                    'ip'           => request()?->ip(),
                    'user_agent'   => request()?->userAgent(),
                ]
            );
        });

        // Renders resources/views/errors/{status}.blade.php for every HTTP
        // error and every uncaught exception (mapped to 500) — never the
        // default Laravel/Whoops page, in production or locally.
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                return null; // let API/JSON requests get Laravel's normal JSON error shape
            }

            // Validation failures (wrong login, failed form validation
            // anywhere in the app) must redirect back to the form with
            // inline errors, exactly like Laravel does by default — not
            // get hijacked into this custom error page.
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            $status = 500;

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
            } elseif ($e instanceof RouteNotFoundException) {
                // A broken route reference (e.g. sidebar link to a removed
                // route) is a "this doesn't exist" situation — 404, not 500.
                $status = 404;
            }

            $referenceId = app('error.reference_id');
            $view = view()->exists("errors.$status") ? "errors.$status" : 'errors.generic';
            $classification = ErrorClassifier::classify($e);

            return response()->view($view, [
                'referenceId'       => $referenceId,
                'statusCode'        => $status,
                'technicalCategory' => $classification['category'],
                'technicalSummary'  => $classification['summary'],
                'technicalMessage'  => $e->getMessage(),
                'technicalFile'     => $e->getFile() . ':' . $e->getLine(),
            ], $status);
        });

    })->create();