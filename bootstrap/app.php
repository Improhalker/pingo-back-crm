<?php

use App\Http\Middleware\EnsurePlatformAdministrator;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(RequestId::class);
        $middleware->alias(['organization' => ResolveOrganization::class, 'platform' => EnsurePlatformAdministrator::class]);
        // Platform authorization runs before route model binding, so non-masters cannot probe which company IDs exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsurePlatformAdministrator::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('api/*') || request()->is('sanctum/*')) {
                Log::error('Technical request failure', [
                    'error_class' => get_class($exception),
                    'request_id' => request()->attributes->get('request_id'),
                ]);

                // Avoid duplicating SQL bindings, payloads or cookie data in default exception logs.
                return false;
            }

            return null;
        });
        // Role denials from Policies (ORGANIZATION_ROLE_FORBIDDEN) keep their explanatory message and code.
        // Laravel turns AuthorizationException into AccessDeniedHttpException before these callbacks run.
        $exceptions->render(function (AccessDeniedHttpException $denied, Request $request) {
            $exception = $denied->getPrevious();
            $code = $exception instanceof AuthorizationException ? $exception->getCode() : null;
            if ($request->is('api/*') && is_string($code) && str_starts_with($code, 'ORGANIZATION_')) {
                return response()->json(['message' => $denied->getMessage(), 'code' => $code], 403);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response) {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                $codes = [401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 409 => 'CONFLICT', 419 => 'CSRF_EXPIRED', 422 => 'VALIDATION_ERROR', 429 => 'RATE_LIMITED', 503 => 'UNAVAILABLE'];
                $messages = [401 => 'Entre para continuar.', 403 => 'Você não tem acesso a esta organização.', 404 => 'Recurso indisponível.', 409 => 'A operação conflita com dados existentes.', 419 => 'Sua sessão expirou. Entre novamente.', 422 => 'Confira os campos informados.', 429 => 'Muitas tentativas. Aguarde um minuto.', 503 => 'Serviço temporariamente indisponível.'];
                $original = $response instanceof JsonResponse ? $response->getData(true) : [];

                // Specific 403/409 answers (internal Kanban, platform, organization roles/status, idempotency) keep their own code and message.
                $code = (string) ($original['code'] ?? '');
                if (in_array($status, [403, 409], true) && preg_match('/^(INTERNAL_KANBAN|PLATFORM|ORGANIZATION|IDEMPOTENCY|FINANCIAL|SETTLEMENT)_/', $code)) {
                    return response()->json($original + ['errors' => (object) [], 'request_id' => request()->attributes->get('request_id')], $status);
                }

                return response()->json([
                    'message' => $messages[$status] ?? 'Não foi possível concluir a solicitação.',
                    'code' => $codes[$status] ?? 'SERVER_ERROR',
                    'errors' => $status === 422 ? ($original['errors'] ?? []) : (object) [],
                    'request_id' => request()->attributes->get('request_id'),
                ], $status, array_filter(['Retry-After' => $response->headers->get('Retry-After')]));
            }

            return $response;
        });
    })->create();
