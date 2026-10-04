<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests\Support;

use Revenexx\AppSdk\Router\App;

/**
 * Stands in for the open-runtimes PHP context: `req` with the runtime's own
 * public properties, `res` with its `send()`, and `log()` / `error()`.
 */
final class FakeContext
{
    public object $req;
    public object $res;
    /** @var list<string> */
    public array $logs = [];

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query     the runtime's undecoded `$req->query`
     */
    public function __construct(string $method, string $path, array $headers = [], mixed $body = null, string $queryString = '', array $query = [])
    {
        $this->req = new class () {
            public string $method = 'GET';
            public string $path = '/';
            public string $queryString = '';
            /** @var array<string, string> */
            public array $query = [];
            /** @var array<string, string> */
            public array $headers = [];
            public string $bodyBinary = '';
        };
        $this->req->method = $method;
        $this->req->path = $path;
        $this->req->queryString = $queryString;
        $this->req->query = $query;
        $this->req->headers = array_change_key_case(['x-revenexx-tenant' => 'acme', ...$headers]);
        $this->req->bodyBinary = $body === null ? '' : (\is_string($body) ? $body : (string) json_encode($body));
        $this->res = new class () {
            /** @param array<string, string> $headers */
            public function send(string $body, int $statusCode = 200, array $headers = []): array
            {
                return ['body' => $body, 'statusCode' => $statusCode, 'headers' => $headers];
            }
        };
    }

    public function log(string ...$m): void
    {
        $this->logs[] = implode(' ', $m);
    }

    public function error(string ...$m): void
    {
        $this->logs[] = 'ERROR ' . implode(' ', $m);
    }

    /**
     * Run one request through the App the way the runtime does.
     *
     * @param array{headers?: array<string, string>, body?: mixed, query?: string, params?: array<string, string>, rawQuery?: array<string, string>} $opts
     * @return array{status: int, data: mixed, raw: string, logs: list<string>}
     */
    public static function call(App $app, string $method, string $path, array $opts = []): array
    {
        $headers = $opts['headers'] ?? [];
        if (isset($opts['params'])) {
            $headers['x-capability-params'] = (string) json_encode($opts['params']);
        }
        $ctx = new self($method, $path, $headers, $opts['body'] ?? null, $opts['query'] ?? '', $opts['rawQuery'] ?? []);
        $out = $app->handler()($ctx);

        return ['status' => $out['statusCode'], 'data' => json_decode($out['body'], true), 'raw' => $out['body'], 'logs' => $ctx->logs];
    }
}
