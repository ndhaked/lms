<?php

namespace App\Support\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds the OpenAPI 3 document for the mobile API (v1) straight from the code, so it never drifts:
 *  - every route named api.v1.* becomes an operation (path, method, auth, path parameters);
 *  - the controller method's doc comment becomes the summary and description;
 *  - the permission (authorizePermission) and plan module (requireModule) it checks are listed;
 *  - the fields in its $request->validate([...]) rules (and in helper methods it calls) become the request body
 *    (query parameters for GET), with types, required flags, enums and file uploads.
 */
class OpenApiGenerator
{
    protected const TAGS = [
        'AuthController' => 'Sign in and account', 'ProfileController' => 'Sign in and account', 'AppInfoController' => 'App',
        'DeviceController' => 'Push notifications', 'NotificationController' => 'Push notifications',
        'LibraryController' => 'Library and theme', 'DashboardController' => 'Dashboard and insights',
        'StudentController' => 'Students', 'SeatController' => 'Halls and seats', 'MembershipController' => 'Plans and memberships',
        'PaymentController' => 'Payments and dues', 'AttendanceController' => 'Attendance and scanner', 'BiometricDeviceController' => 'Biometric devices',
        'ExpenseReportController' => 'Expenses and reports', 'BillingController' => 'Subscription and credits', 'MessageController' => 'SMS and WhatsApp',
        'SupportController' => 'Support and more', 'BookController' => 'Books', 'CirculationController' => 'Lending and fines',
        'StockTakeController' => 'Stock-taking', 'StaffSettingsController' => 'Staff and settings', 'StudentSelfController' => 'Student app (my)',
    ];

    /** Example values shown in "Try it out" (the demo data's logins and typical values). */
    protected const EXAMPLES = [
        'email' => 'owner@example.com', 'password' => 'password', 'device_name' => 'Swagger test', 'platform' => 'android', 'push_provider' => 'expo',
        'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]', 'push_token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]', 'provider' => 'expo', 'name' => 'Rohit Kumar', 'phone' => '9876543210',
        'shift' => 'morning', 'method' => 'cash', 'paid_on' => '2026-10-30', 'starts_on' => '2026-10-30', 'amount' => 500,
        'reason' => 'Entered by mistake', 'status' => 'active', 'cycle' => 'monthly', 'channel' => 'sms', 'lang' => 'en', 'source' => 'login',
        'current_password' => 'password', 'password_confirmation' => 'newpassword1', 'subject' => 'Need help', 'body' => 'How do I add seats?',
    ];

    /** @var array<string, string> class => source */
    protected array $sources = [];

    /** @var array<string, array<string, string>> file => short class name => full class name (use statements) */
    protected array $uses = [];

    protected ?string $currentFile = null;

    public function build(): array
    {
        $paths = [];
        $tags = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with((string) $route->getName(), 'api.v1.')) {
                continue;
            }
            [$class, $method] = array_pad(explode('@', (string) $route->getActionName()), 2, null);
            if (! $class || ! $method || ! method_exists($class, $method)) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');
            $short = class_basename($class);
            $tag = self::TAGS[$short] ?? Str::headline(Str::before($short, 'Controller'));
            $tags[$tag] = true;

            foreach ($route->methods() as $verb) {
                if ($verb === 'HEAD') {
                    continue;
                }
                $paths[$uri][strtolower($verb)] = $this->operation($route, $class, $method, $verb, $tag);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => (config('app.name') ?: 'LMS').' mobile API',
                'version' => 'v1',
                'description' => "API for the iOS and Android app (owners, staff and students).\n\n"
                    ."**How to try it:** call `POST /api/v1/auth/login`, copy the `token`, press **Authorize** and paste it. "
                    ."Library endpoints need the library `slug` from the login response.\n\n"
                    ."Errors are JSON: 401 signed out, 402 subscription needs attention, 403 no permission, 404 not found, 409 possible duplicate, 422 form error or business rule, 429 too many requests.\n\n"
                    .'Full guide: `docs/mobile-api.md` in the code.',
            ],
            'servers' => [['url' => url('/'), 'description' => 'This server']],
            // the order of TAGS: sign-in first, the student app last
            'tags' => collect(array_unique(array_values(self::TAGS)))->merge(array_keys($tags))->unique()->filter(fn ($t) => isset($tags[$t]))->map(fn ($t) => ['name' => $t])->values()->all(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The token from POST /api/v1/auth/login']],
                'schemas' => [
                    'Message' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                    'ValidationError' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                ],
            ],
        ];
    }

    protected function operation(Route $route, string $class, string $method, string $verb, string $tag): array
    {
        $ref = new ReflectionMethod($class, $method);
        $this->currentFile = $ref->getDeclaringClass()->getFileName();
        $doc = $this->docText($ref->getDocComment() ?: '');
        $body = $this->methodSource($ref);
        $helpers = $this->helperSources($class, $body);
        $all = $body.$helpers;

        $perm = $this->firstMatch('/authorizePermission\(\'([^\']+)\'\)/', $body) ?? $this->firstMatch('/authorizePermission\(\'([^\']+)\'\)/', $helpers);
        $module = $this->firstMatch('/requireModule\(\'([^\']+)\'\)/', $all);
        $authed = in_array('auth:sanctum', $route->gatherMiddleware(), true);

        $summary = $doc !== '' ? Str::of($doc)->before("\n")->before('. ')->trim()->rtrim('.')->limit(110, '…')->toString() : Str::headline($method);
        $notes = array_filter([
            $doc !== '' && str_contains($doc, "\n") || Str::length($doc) > 110 ? $doc : null,
            $perm ? '**Permission:** `'.$perm.'`' : null,
            $module ? '**Plan module:** `'.$module.'`' : null,
        ]);

        $op = [
            'tags' => [$tag],
            'summary' => $summary,
            'operationId' => Str::camel(str_replace('.', ' ', Str::after((string) $route->getName(), 'api.v1.'))).ucfirst(strtolower($verb)),
            'description' => implode("\n\n", $notes),
            'security' => $authed ? [['bearer' => []]] : [],
            'parameters' => [],
            'responses' => $this->responses($route, $authed, $all),
        ];

        foreach ($route->parameterNames() as $p) {
            $op['parameters'][] = ['name' => $p, 'in' => 'path', 'required' => true,
                'schema' => $p === 'library' ? ['type' => 'string', 'example' => 'demo-library'] : (in_array($p, ['report', 'format'], true) ? ['type' => 'string'] : ['type' => 'integer']),
                'description' => $p === 'library' ? 'Library slug from the login response' : null];
        }

        $fields = $this->fields($all);
        if ($verb === 'GET' || $verb === 'DELETE' && $fields === []) {
            foreach ($fields as $name => $f) {
                $op['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => $f['required'], 'schema' => $f['schema']];
            }
            foreach ($this->queryReads($all) as $q) {
                if (! isset($fields[$q])) {
                    $op['parameters'][] = ['name' => $q, 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
                }
            }
            if (str_contains($all, '->paged(') || str_contains($all, 'perPage()')) {
                $op['parameters'][] = ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]];
                $op['parameters'][] = ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 50]];
            }
        } elseif ($fields !== []) {
            $multipart = collect($fields)->contains(fn ($f) => ($f['schema']['format'] ?? '') === 'binary' || (($f['schema']['items']['format'] ?? '') === 'binary'));
            $schema = ['type' => 'object', 'properties' => collect($fields)->map(fn ($f) => $f['schema'])->all()];
            $required = array_keys(array_filter($fields, fn ($f) => $f['required']));
            if ($required) {
                $schema['required'] = $required;
            }
            $op['requestBody'] = ['required' => $required !== [], 'content' => [$multipart ? 'multipart/form-data' : 'application/json' => ['schema' => $schema]]];
        }

        if (str_contains($all, "header('Idempotency-Key')")) {
            $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Unique per attempt; a retry returns the first payment.'];
        }
        $op['parameters'] = array_values(array_map(fn ($p) => array_filter($p, fn ($v) => $v !== null), $op['parameters']));
        if ($op['parameters'] === []) {
            unset($op['parameters']);
        }

        return $op;
    }

    protected function responses(Route $route, bool $authed, string $src): array
    {
        $file = str_contains($src, 'Pdf::download') || str_contains($src, '->response(') || str_contains($src, 'ReportExporter::') || str_contains($src, 'EbookFileController') || str_contains($src, 'BookCoverController') || str_contains($src, 'ReceiptController');
        $ok = $file
            ? ['description' => 'The file (PDF, image, spreadsheet or e-book)', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]
            : ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        $r = [str_contains($src, ', 201)') ? '201' : '200' => $ok];
        if ($authed) {
            $r['401'] = ['description' => 'Not signed in, or the token expired', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]];
            $r['403'] = ['description' => 'No permission, module not in plan, or library read-only', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]];
        }
        if (str_contains($route->uri(), '{')) {
            $r['404'] = ['description' => 'Not found (or another library\'s record)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]];
        }
        if (str_contains($src, 'validate(') || str_contains($src, 'BusinessRuleException') || str_contains($src, 'app(')) {
            $r['422'] = ['description' => 'Form error or business rule', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]];
        }

        return $r;
    }

    // ---------- reading the controller code ----------

    protected function source(string $class): string
    {
        return $this->sources[$class] ??= (string) file_get_contents((new ReflectionClass($class))->getFileName());
    }

    protected function methodSource(ReflectionMethod $m): string
    {
        $lines = explode("\n", $this->source($m->getDeclaringClass()->getName()));

        return implode("\n", array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    /** Source of the protected helpers the method calls ($this->validated(), $this->cashData() ...), one level deep. */
    protected function helperSources(string $class, string $body): string
    {
        preg_match_all('/\$this->([a-zA-Z]+)\(/', $body, $m);
        $out = '';
        foreach (array_unique($m[1]) as $name) {
            if (in_array($name, ['authorizePermission', 'can', 'requireModule', 'writable', 'ok', 'message', 'paged', 'perPage', 'library'], true)) {
                continue;
            }
            if (method_exists($class, $name)) {
                $rm = new ReflectionMethod($class, $name);
                if ($rm->getDeclaringClass()->getName() === $class && ! $rm->isPublic()) {
                    $out .= "\n".$this->methodSource($rm);
                }
            }
        }

        return $out;
    }

    protected function docText(string $doc): string
    {
        $lines = array_map(fn ($l) => trim(preg_replace('#^\s*/?\*+/?#', '', $l)), explode("\n", $doc));
        $lines = array_filter($lines, fn ($l) => $l !== '' && ! str_starts_with($l, '@'));

        return trim(implode("\n", $lines));
    }

    protected function firstMatch(string $re, string $src): ?string
    {
        return preg_match($re, $src, $m) ? $m[1] : null;
    }

    /** Fields from validate([...]) arrays: name => {required, schema}. */
    protected function fields(string $src): array
    {
        $fields = [];
        // validate([...]) blocks, and rule arrays built first ($rules = [...]) then passed to validate($rules)
        preg_match_all('/validate\(\[|\$rules\s*=\s*\[/', $src, $starts, PREG_OFFSET_CAPTURE);
        foreach ($starts[0] as [$match, $pos]) {
            $block = $this->bracket($src, $pos + strlen($match) - 1);
            preg_match_all("/'([A-Za-z0-9_.*]+)'\s*=>\s*(\[[^\n]*\]|'[^']*')/", $block, $m, PREG_SET_ORDER);
            foreach ($m as [$all, $name, $rules]) {
                $this->addField($fields, $name, $rules);
                $leaf = preg_replace('/^.*\.\*?\.?/', '', $name) ?: $name;
                if (($enum = $this->ruleInEnum($rules)) !== null) {
                    $this->setOnField($fields, $name, 'enum', $enum);
                }
                if (isset(self::EXAMPLES[$leaf])) {
                    $this->setOnField($fields, $name, 'example', self::EXAMPLES[$leaf]);
                }
            }
        }

        return $fields;
    }

    protected function addField(array &$fields, string $name, string $rules): void
    {
        $schema = $this->schemaFor($rules);
        $required = (bool) preg_match("/'required'/", $rules) || str_starts_with($rules, "'required");

        if (str_contains($name, '.*.')) {   // returns.*.item
            [$parent, $child] = explode('.*.', $name, 2);
            $fields[$parent] ??= ['required' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]];
            $fields[$parent]['schema']['type'] = 'array';
            $fields[$parent]['schema']['items']['type'] = 'object';
            $fields[$parent]['schema']['items']['properties'][$child] = $schema;

            return;
        }
        if (str_ends_with($name, '.*')) {   // barcodes.* / proofs.*
            $parent = substr($name, 0, -2);
            $fields[$parent] ??= ['required' => false, 'schema' => ['type' => 'array']];
            $fields[$parent]['schema']['type'] = 'array';
            $fields[$parent]['schema']['items'] = $schema;

            return;
        }
        $previous = $fields[$name]['schema'] ?? [];
        $fields[$name] = ['required' => $required, 'schema' => $previous ? array_replace($schema, array_intersect_key($previous, ['items' => 1])) : $schema];
    }

    protected function schemaFor(string $rules): array
    {
        $s = ['type' => 'string'];
        $has = fn (string $r) => (bool) preg_match("/'".preg_quote($r, '/')."(:[^']*)?'/", $rules);
        if ($has('integer')) {
            $s = ['type' => 'integer'];
        } elseif ($has('numeric')) {
            $s = ['type' => 'number'];
        } elseif ($has('boolean')) {
            $s = ['type' => 'boolean'];
        } elseif ($has('array')) {
            $s = ['type' => 'array', 'items' => ['type' => 'string']];
        } elseif ($has('image') || $has('file')) {
            $s = ['type' => 'string', 'format' => 'binary'];
        } elseif ($has('date_format') && str_contains($rules, 'H:i')) {
            $s = ['type' => 'string', 'example' => '08:00'];
        } elseif ($has('date') || $has('date_format')) {
            $s = ['type' => 'string', 'format' => 'date'];
        } elseif ($has('email')) {
            $s = ['type' => 'string', 'format' => 'email'];
        }
        if (preg_match("/'in:([^']+)'/", $rules, $m)) {
            $s['enum'] = explode(',', $m[1]);
        }
        if (preg_match("/'max:(\d+)'/", $rules, $m) && $s['type'] === 'string' && ! isset($s['format'])) {
            $s['maxLength'] = (int) $m[1];
        }
        if (str_contains($rules, "'nullable'")) {
            $s['nullable'] = true;
        }

        return $s;
    }

    /** Allowed values from Rule::in(Model::CONST) or Rule::in(array_keys(Model::CONST)), resolved through the file's use statements. */
    protected function ruleInEnum(string $rules): ?array
    {
        if (! preg_match('/Rule::in\((array_keys\()?\\\\?([A-Za-z\\\\]+)::([A-Z_]+)\)?\)/', $rules, $m) || ! $this->currentFile) {
            return null;
        }
        $short = $m[2];
        $uses = $this->uses[$this->currentFile] ??= $this->readUses($this->currentFile);
        $class = str_contains($short, '\\') ? $short : ($uses[$short] ?? null);
        if (! $class || ! defined($class.'::'.$m[3])) {
            return null;
        }
        $value = constant($class.'::'.$m[3]);
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_map('strval', $m[1] ? array_keys($value) : (array_is_list($value) ? $value : array_keys($value))));
    }

    protected function readUses(string $file): array
    {
        preg_match_all('/^use\s+([A-Za-z\\\\]+)(?:\s+as\s+(\w+))?;/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $u) {
            $out[$u[2] ?? class_basename($u[1])] = $u[1];
        }

        return $out;
    }

    protected function setOnField(array &$fields, string $name, string $key, mixed $value): void
    {
        if (str_contains($name, '.*.')) {
            [$p, $c] = explode('.*.', $name, 2);
            if (isset($fields[$p]['schema']['items']['properties'][$c])) {
                $fields[$p]['schema']['items']['properties'][$c][$key] = $value;
            }
        } elseif (str_ends_with($name, '.*')) {
            $p = substr($name, 0, -2);
            if (isset($fields[$p]['schema']['items'])) {
                $fields[$p]['schema']['items'][$key] = $value;
            }
        } elseif (isset($fields[$name])) {
            if ($key === 'example' && ($fields[$name]['schema']['format'] ?? '') === 'binary') {
                return;
            }
            $fields[$name]['schema'][$key] = $value;
        }
    }

    /** Text from an opening "[" to its matching "]". */
    protected function bracket(string $src, int $start): string
    {
        $depth = 0;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            if ($src[$i] === '[') {
                $depth++;
            } elseif ($src[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /** Query string values read directly: $request->query('x'), ->boolean('x'), ->integer('x'). */
    protected function queryReads(string $src): array
    {
        preg_match_all("/\\\$request->(?:query|boolean|integer)\('([a-z_]+)'/", $src, $m);

        return array_values(array_unique($m[1]));
    }
}
