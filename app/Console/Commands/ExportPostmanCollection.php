<?php

namespace App\Console\Commands;

use App\Support\Api\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Writes a Postman collection (v2.1) and environment for the mobile API, from the same OpenAPI document as Swagger:
 *   php artisan api:postman --base-url=https://staging.example.in
 * Files: docs/postman/LMS-mobile-api.postman_collection.json and LMS-mobile-api.postman_environment.json.
 * Every request uses {{base_url}}, {{token}} and {{library}}; the Sign in request saves token and library automatically.
 */
class ExportPostmanCollection extends Command
{
    protected $signature = 'api:postman {--base-url= : Server address for the environment file (default: APP_URL)}';

    protected $description = 'Export the mobile API as a Postman collection and environment';

    public function handle(OpenApiGenerator $generator): int
    {
        $spec = $generator->build();
        $base = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');
        $folders = [];

        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $folders[$op['tags'][0] ?? 'Other'][] = $this->request($path, strtoupper($method), $op);
            }
        }

        // Login first: every other request needs the token it saves
        foreach ($folders as $name => $items) {
            usort($items, fn ($a, $b) => (int) str_ends_with($b['name'], 'POST auth/login') <=> (int) str_ends_with($a['name'], 'POST auth/login'));
            $folders[$name] = $items;
        }

        $collection = [
            'info' => [
                'name' => (config('app.name') ?: 'LMS').' mobile API v1',
                '_postman_id' => (string) Str::uuid(),
                'description' => "Mobile app API.\n\n1. Choose the environment and set base_url.\n2. Run \"Sign in and account → Login\" (the token and library slug are saved automatically).\n"
                    ."3. Every other request then works. Library requests use {{library}}; change it to use another library.\n\n"
                    ."Errors are JSON: 401 signed out, 402 subscription needs attention, 403 no permission, 404 not found, 409 possible duplicate, 422 form error, 426 app update required, 429 too many requests.\n"
                    .'Full guide: docs/mobile-api.md. Swagger: {{base_url}}/api/docs',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'base_url', 'value' => $base],
                ['key' => 'token', 'value' => ''],
                ['key' => 'library', 'value' => 'demo-library'],
            ],
            // folders in the same order as Swagger (sign-in first, the student app last)
            'item' => collect($spec['tags'])->pluck('name')->filter(fn ($t) => isset($folders[$t]))->map(fn ($t) => ['name' => $t, 'item' => $folders[$t]])->values()->all(),
        ];

        $environment = [
            'id' => (string) Str::uuid(),
            'name' => 'LMS API · '.parse_url($base, PHP_URL_HOST),
            'values' => [
                ['key' => 'base_url', 'value' => $base, 'type' => 'default', 'enabled' => true],
                ['key' => 'token', 'value' => '', 'type' => 'secret', 'enabled' => true],
                ['key' => 'library', 'value' => 'demo-library', 'type' => 'default', 'enabled' => true],
                ['key' => 'app_platform', 'value' => 'android', 'type' => 'default', 'enabled' => true],
                ['key' => 'app_version', 'value' => '1.0.0', 'type' => 'default', 'enabled' => true],
            ],
            '_postman_variable_scope' => 'environment',
        ];

        $dir = base_path('docs/postman');
        @mkdir($dir, 0775, true);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        file_put_contents($dir.'/LMS-mobile-api.postman_collection.json', json_encode($collection, $flags));
        file_put_contents($dir.'/LMS-mobile-api.postman_environment.json', json_encode($environment, $flags));

        $count = collect($folders)->flatten(1)->count();
        $this->info("Postman collection written: {$count} requests in ".count($folders).' folders.');
        $this->line('  docs/postman/LMS-mobile-api.postman_collection.json');
        $this->line('  docs/postman/LMS-mobile-api.postman_environment.json');

        return self::SUCCESS;
    }

    protected function request(string $path, string $method, array $op): array
    {
        $params = collect($op['parameters'] ?? []);
        $urlPath = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $m[1] === 'library' ? '{{library}}' : ':'.$m[1], ltrim($path, '/'));
        $query = $params->where('in', 'query')->map(fn ($p) => ['key' => $p['name'], 'value' => $this->example($p['schema'] ?? []), 'disabled' => ! ($p['required'] ?? false), 'description' => $p['required'] ?? false ? 'required' : 'optional'])->values()->all();
        $variables = $params->where('in', 'path')->reject(fn ($p) => $p['name'] === 'library')->map(fn ($p) => ['key' => $p['name'], 'value' => '1', 'description' => 'id'])->values()->all();

        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
            ['key' => 'X-App-Platform', 'value' => '{{app_platform}}', 'disabled' => true, 'description' => 'Send from the app: android | ios (force update)'],
            ['key' => 'X-App-Version', 'value' => '{{app_version}}', 'disabled' => true, 'description' => 'Send from the app (force update)'],
        ];
        foreach ($params->where('in', 'header') as $h) {
            $headers[] = ['key' => $h['name'], 'value' => '{{$guid}}', 'description' => $h['description'] ?? ''];
        }

        $item = [
            // e.g. "Seat map by shift · GET seats": what it does, then the call itself
            'name' => trim(($op['summary'] ?? '').' · '.$method.' '.(Str::after($path, '/api/v1/libraries/{library}/') !== $path ? Str::after($path, '/api/v1/libraries/{library}/') : Str::after($path, '/api/v1/')), ' ·'),
            'request' => [
                'method' => $method,
                'header' => $headers,
                'url' => array_filter(['raw' => '{{base_url}}/'.$urlPath.($query ? '?'.collect($query)->reject(fn ($q) => $q['disabled'])->map(fn ($q) => $q['key'].'='.$q['value'])->implode('&') : ''),
                    'host' => ['{{base_url}}'], 'path' => explode('/', $urlPath), 'query' => $query ?: null, 'variable' => $variables ?: null]),
                'description' => $op['description'] ?? '',
            ],
            'response' => [],
        ];
        if (($op['security'] ?? null) === []) {
            $item['request']['auth'] = ['type' => 'noauth'];
        }

        if (isset($op['requestBody'])) {
            $type = array_key_first($op['requestBody']['content']);
            $schema = $op['requestBody']['content'][$type]['schema'] ?? [];
            $required = $schema['required'] ?? [];
            if ($type === 'multipart/form-data') {
                $item['request']['body'] = ['mode' => 'formdata', 'formdata' => collect($schema['properties'] ?? [])->map(function ($s, $k) use ($required) {
                    $file = ($s['format'] ?? '') === 'binary' || (($s['items']['format'] ?? '') === 'binary');

                    return array_filter(['key' => $file && ($s['type'] ?? '') === 'array' ? $k.'[]' : $k, 'type' => $file ? 'file' : 'text',
                        'value' => $file ? null : (string) $this->example($s), 'src' => $file ? [] : null, 'disabled' => ! in_array($k, $required, true)], fn ($v) => $v !== null);
                })->values()->all()];
            } else {
                $body = [];
                foreach ($schema['properties'] ?? [] as $k => $s) {
                    if (in_array($k, $required, true) || isset($s['example'])) {
                        $body[$k] = $this->example($s, true);
                    }
                }
                $item['request']['body'] = ['mode' => 'raw', 'raw' => json_encode($body ?: new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'options' => ['raw' => ['language' => 'json']]];
                $item['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            }
        }

        // Sign in: keep the token and the first library for the other requests
        if ($path === '/api/v1/auth/login') {
            $item['request']['body']['raw'] = json_encode(['email' => 'owner@example.com', 'password' => 'password', 'device_name' => 'Postman'], JSON_PRETTY_PRINT);
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
                'const r = pm.response.json();',
                'if (r.token) { pm.collectionVariables.set("token", r.token); pm.environment.set("token", r.token); }',
                'if (r.libraries && r.libraries.length) { pm.collectionVariables.set("library", r.libraries[0].slug); pm.environment.set("library", r.libraries[0].slug); }',
                'pm.test("signed in", () => pm.expect(r.token).to.be.a("string"));',
            ]]]];
        }

        return $item;
    }

    protected function example(array $s, bool $typed = false): mixed
    {
        if (array_key_exists('example', $s)) {
            return $s['example'];
        }
        if (isset($s['enum'][0])) {
            return $s['enum'][0];
        }

        return match ($s['type'] ?? 'string') {
            'integer' => 1,
            'number' => 100,
            'boolean' => $typed ? true : 'true',
            'array' => $typed ? [isset($s['items']['properties']) ? collect($s['items']['properties'])->map(fn ($p) => $this->example($p, true))->all() : $this->example($s['items'] ?? [], true)] : '',
            default => match ($s['format'] ?? '') {
                'date' => today()->toDateString(),
                'email' => 'name@example.com',
                default => '',
            },
        };
    }
}
