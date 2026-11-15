<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use RuntimeException;
use Sahil\Auth\AuthManager;
use Sahil\Auth\BouncerManager;
use Sahil\Auth\Gate;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Bouncer's route middleware — the `ability` alias.
 *
 *     Link::get('/admin/users', [UserController::class, 'index'])
 *         ->middleware('ability:manage-users');
 *
 *     Link::get('/posts/{post}', [PostController::class, 'update'])
 *         ->middleware('ability:update-post,{post}');
 *
 * Comma-separated parameters are ability names; `{name}` entries are
 * route placeholders the kernel substitutes with the matched parameter
 * (a {@see RouteArgument}) and hands to the ability as a resource
 * argument. Several abilities mean "any of them grants access".
 *
 * Guests get 401, denied users 403. The actual decision always belongs
 * to {@see BouncerManager} — this middleware only transports the
 * request.
 */
final class Authorize implements MiddlewareContract, ParameterizedMiddlewareContract
{
    private readonly Gate|BouncerManager $bouncer;

    public function __construct(
        private readonly AuthManager $auth,
        Gate|BouncerManager $bouncer,
    ) {
        // Gate is accepted as a deprecated bridge so applications generated
        // before Bouncer keep working until they rebind the manager.
        $this->bouncer = $bouncer;
    }

    /**
     * Handle the incoming request.
     *
     * @param string ...$abilities abilities that grant access
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        return $this->authorize($request, $next, array_values($abilities), []);
    }

    /**
     * Handle a route that configured abilities through a middleware spec.
     *
     * @param array<array-key, mixed> $parameters ability names and
     *        {@see RouteArgument} resource arguments
     *
     * @throws RuntimeException When a parameter is neither an ability name nor a RouteArgument
     */
    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response
    {
        $abilities = [];
        $arguments = [];

        foreach ($parameters as $index => $parameter) {
            if ($parameter instanceof RouteArgument) {
                $arguments[] = $parameter->value();

                continue;
            }

            if (!is_string($parameter) || trim($parameter) === '') {
                throw new RuntimeException(sprintf(
                    'Authorize middleware parameter at index %d must be a non-empty ability name or a {route} argument; got %s.',
                    $index,
                    get_debug_type($parameter),
                ));
            }

            $abilities[] = $parameter;
        }

        return $this->authorize($request, $next, $abilities, $arguments);
    }

    /**
     * @param list<string>  $abilities
     * @param list<mixed>   $arguments
     */
    private function authorize(Request $request, Closure $next, array $abilities, array $arguments): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->unauthorized($request);
        }

        // The request's API token bounds — never widens — the check.
        $token = method_exists($user, 'currentTokenAuthorizer')
            ? $user->currentTokenAuthorizer()
            : null;

        $granted = $abilities !== []
            && ($this->bouncer instanceof Gate
                ? $this->bouncer->any($abilities, $user, $arguments)
                : $this->bouncer->any($abilities, $arguments, $user, $token));

        if ($granted) {
            return $next($request);
        }

        return $this->forbidden($request);
    }

    /**
     * Handle unauthorized requests (401).
     */
    private function unauthorized(Request $request): Response
    {
        return Response::json([
            'error' => 'unauthorized',
            'message' => 'Authentication required.',
        ], 401);
    }

    /**
     * Handle forbidden requests (403).
     */
    private function forbidden(Request $request): Response
    {
        return Response::json([
            'error' => 'forbidden',
            'message' => 'You do not have permission to access this resource.',
        ], 403);
    }
}
