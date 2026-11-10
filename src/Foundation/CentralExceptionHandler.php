<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use Sahil\Http\ErrorRenderer;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * The application's centralized exception handler.
 *
 * One place decides, for every uncaught throwable, two things:
 *  1. {@see report()}: log the event with useful (but non-sensitive)
 *     context — class, message, file/line. HTTP errors (404s, ...) are
 *     `warning`s; genuine faults are `error`s.
 *  2. {@see render()} the HTTP response through {@see ErrorRenderer}: browsers
 *     get a designed HTML error page, machine clients keep the JSON contract,
 *     `HttpException`s keep their status and headers, and anything else is a
 *     500. In debug mode (never default) the message/class are added. Stack
 *     traces never reach the client.
 *
 * Configuration errors are distinguishable from HTTP errors: they never
 * render — they are boot failures raised as
 * {@see \Sahil\Config\ConfigurationException} before HTTP dispatch.
 */
final class CentralExceptionHandler
{
    /**
     * @param ErrorRenderer|null $errors renderer wired to the application's
     *        views; without one, responses stay JSON (and built-in HTML when a
     *        request is passed and the pages cannot be rendered)
     */
    public function __construct(
        private readonly LoggerContract $logger,
        private readonly ?ErrorRenderer $errors = null,
    ) {
    }

    /**
     * Log the throwable (never arguments or stack frames) for triage.
     */
    public function report(Throwable $throwable): void
    {
        $isHttp = $throwable instanceof HttpException;

        $this->logger->log($isHttp ? 'warning' : 'error', 'Uncaught '.$throwable::class, [
            'exception_class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'status' => $throwable instanceof HttpException ? $throwable->statusCode() : null,
        ]);
    }

    /**
     * Rendering contract for the HTTP kernel.
     *
     * With a request, the answer matches what the client expects (HTML page or
     * JSON); without one, the JSON contract is kept for standalone callers.
     */
    public function render(Throwable $throwable, bool $debug = false, ?Request $request = null): Response
    {
        return ($this->errors ?? new ErrorRenderer())->respond($throwable, $request, $debug);
    }
}
