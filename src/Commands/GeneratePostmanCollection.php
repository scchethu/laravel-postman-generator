<?php

namespace Scchethu\PostmanGenerator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;

class GeneratePostmanCollection extends Command
{
    protected $signature = 'postman:generate {name? : Optional Postman collection filename}';
    protected $description = 'Generate Postman Collection with grouping, auth, env file, body extraction, file upload support, and query/path detection';

    public function handle()
    {
        $routePrefix = config('postman-generator.route_prefix', 'api');
        $routes = collect(Route::getRoutes())
            ->filter(fn($r) => str_starts_with($r->uri(), $routePrefix . '/'));

        $collectionName = $this->argument('name') ?? config('postman-generator.collection_filename', 'LaravelAPI.postman_collection.json');

        $collection = [
            "info" => [
                "name" => config('app.name') . " API Collection",
                "schema" => "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
            ],
            "item" => []
        ];

        foreach ($routes as $route) {

            $methods = $route->methods();
            $method  = $methods[0] ?? 'GET';

            $uri = str_replace($routePrefix . '/', '', $route->uri());

            $folders = explode("/", $uri);
            $name = array_pop($folders);

            $rules = $this->extractRules($route);
            $bodyType = $this->detectBodyType($rules);
            $bodyExample = $this->makeExampleFromRules($rules);
            $queryParams = $this->extractQueryParams($route);
            $pathParams = $this->extractPathParams($route);

            $requiresAuth = $this->routeRequiresAuth($route);

            $requestNode = [
                "name" => $name,
                "request" => [
                    "method" => $method,
                    "header" => $this->prepareHeaders($requiresAuth),
                    "url" => [
                        "raw"  => "{{BASE_URL}}/" . $route->uri(),
                        "host" => ["{{BASE_URL}}"],
                        "path" => explode("/", $route->uri()),
                        "query" => $queryParams
                    ]
                ]
            ];

            if (!empty($pathParams)) {
                $requestNode["request"]["url"]["variable"] = $pathParams;
            }

            if (in_array($method, ["POST", "PUT", "PATCH"])) {

                if ($bodyType === "form-data") {
                    $requestNode["request"]["body"] = [
                        "mode"     => "formdata",
                        "formdata" => $this->convertToFormData($rules)
                    ];
                } else {
                    $requestNode["request"]["header"][] = [
                        "key" => "Content-Type", "value" => "application/json"
                    ];

                    $requestNode["request"]["body"] = [
                        "mode" => "raw",
                        "raw"  => json_encode($bodyExample, JSON_PRETTY_PRINT)
                    ];
                }
            }

            $this->insertIntoTree($collection["item"], $folders, $requestNode);
        }

        $collectionPath = storage_path($collectionName);

        file_put_contents(
            $collectionPath,
            json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->generateEnvironmentFile();

        $this->info("🚀 Postman Collection generated: " . $collectionPath);
    }

    // ============================================================
    // 🔐 AUTH
    // ============================================================
    private function prepareHeaders($requiresAuth)
    {
        $headers = [
            ["key" => "Accept", "value" => "application/json"]
        ];

        if ($requiresAuth) {
            $headers[] = [
                "key"   => "Authorization",
                "value" => "Bearer {{AUTH_TOKEN}}"
            ];
        }

        return $headers;
    }

    private function routeRequiresAuth($route)
    {
        $uri = $route->uri();
        $keywords = config('postman-generator.public_route_keywords', []);

        foreach ($keywords as $word) {
            if (str_contains($uri, $word)) {
                return false;
            }
        }

        return true;
    }

    // ============================================================
    // 🌍 ENVIRONMENT FILE
    // ============================================================
    private function generateEnvironmentFile()
    {
        $envFile = config('postman-generator.environment_filename', 'postman_environment.json');

        $env = [
            "id"    => uniqid(),
            "name"  => config('app.name') . " Environment",
            "values"=> [
                [
                    "key"     => "BASE_URL",
                    "value"   => config("app.url") ?? "http://localhost",
                    "enabled" => true
                ],
                [
                    "key"     => "AUTH_TOKEN",
                    "value"   => "",
                    "enabled" => true
                ],
                [
                    "key"     => "FILE_UPLOAD_BASE_PATH",
                    "value"   => storage_path("app"),
                    "enabled" => true
                ]
            ]
        ];

        $envPath = storage_path($envFile);

        file_put_contents(
            $envPath,
            json_encode($env, JSON_PRETTY_PRINT)
        );

        $this->info("🌍 Postman Environment generated: " . $envPath);
    }

    // ============================================================
    // 📁 FOLDER TREE LOGIC
    // ============================================================
    private function insertIntoTree(array &$tree, array $folders, array $item)
    {
        if (empty($folders)) {
            $tree[] = $item;
            return;
        }

        $folder = array_shift($folders);

        foreach ($tree as &$node) {
            if (($node["name"] ?? null) === $folder) {
                if (!isset($node["item"])) $node["item"] = [];
                return $this->insertIntoTree($node["item"], $folders, $item);
            }
        }

        $tree[] = ["name" => $folder, "item" => []];
        return $this->insertIntoTree($tree[array_key_last($tree)]["item"], $folders, $item);
    }

    // ============================================================
    // 🧠 VALIDATION EXTRACTION SYSTEM
    // ============================================================
    private function extractRules($route)
    {
        $action = $route->getAction();

        if (!isset($action['controller'])) return [];

        $controllerString = $action['controller'];

        if (str_contains($controllerString, '@')) {
            [$controller, $method] = explode('@', $controllerString);
        } else {
            $controller = $controllerString;
            $method     = '__invoke';
        }

        if (!class_exists($controller) || !method_exists($controller, $method)) {
            return [];
        }

        $ref = new ReflectionMethod($controller, $method);

        // 1️⃣ FormRequest support
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type && !$type->isBuiltin()) {
                $cls = new ReflectionClass($type->getName());
                if ($cls->isSubclassOf(\Illuminate\Foundation\Http\FormRequest::class)) {
                    return app($cls->getName())->rules();
                }
            }
        }

        // 2️⃣ Inline validation support
        return $this->extractInlineRules($ref);
    }

    private function extractInlineRules(ReflectionMethod $method)
    {
        $file = file($method->getFileName());
        $code = implode("", array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        if (!preg_match('/Validator::make\s*\(\s*.*?,\s*\[(.*?)\]\s*\)/s', $code, $match)) {
            if (!preg_match('/\$request->validate\s*\(\s*\[(.*?)\]\s*\)/s', $code, $match)) {
                return [];
            }
        }

        $raw   = $match[1];
        $clean = $this->sanitizeRuleArray($raw);

        return $this->parseRuleArray($clean);
    }

    // ============================================================
    // 🧹 SANITIZE COMPLEX RULE ARRAYS
    // ============================================================
    private function sanitizeRuleArray($raw)
    {
        // Remove closures
        $raw = preg_replace('/function\s*\(.*?\)\s*\{.*?\}/s', '"CLOSURE_RULE"', $raw);

        // Convert Rule::unique(...) to simple string
        $raw = preg_replace('/Rule::unique\([^)]*\)(->where\([^)]*\))?/', '"unique_rule"', $raw);

        // Remove variables like $user, $request, $this etc.
        $raw = preg_replace('/\$[A-Za-z_][A-Za-z0-9_>\->\(\)\[\]]*/', '""', $raw);

        // Remove static calls Something::method()
        $raw = preg_replace('/[A-Za-z0-9_\\\\]+::[A-Za-z0-9_]+\([^)]*\)/', '"CALL"', $raw);

        // Normalize quotes
        $raw = str_replace("'", '"', $raw);

        return $raw;
    }

    // ============================================================
    // 🧩 PARSE RULE ARRAYS WITHOUT EVAL
    // ============================================================
    private function parseRuleArray($string)
    {
        $rules = [];

        preg_match_all('/"([^"]+)"\s*=>\s*(.*?)(?=,\s*"|$)/s', $string, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $field = $m[1];
            $value = trim($m[2]);

            // Array rules: ["required","string","max:255"]
            if (str_starts_with($value, '[')) {
                preg_match_all('/"([^"]*)"/', $value, $arr);
                $rules[$field] = array_filter($arr[1]);
                continue;
            }

            // Simple "required|string|max:255"
            $rules[$field] = trim($value, '"');
        }

        return $rules;
    }

    // ============================================================
    // 🧪 BODY EXAMPLE GENERATOR
    // ============================================================
    private function makeExampleFromRules($rules)
    {
        $body = [];

        foreach ($rules as $field => $rule) {

            $ruleList = is_array($rule) ? $rule : explode("|", $rule);

            // Enum: in:male,female
            foreach ($ruleList as $r) {
                if (str_starts_with($r, "in:")) {
                    $choices = explode(",", substr($r, 3));
                    $body[$field] = $choices[0];
                    continue 2;
                }
            }

            if (in_array("integer", $ruleList)) {
                $body[$field] = 1;
            } elseif (in_array("numeric", $ruleList)) {
                $body[$field] = 99.99;
            } elseif (in_array("boolean", $ruleList)) {
                $body[$field] = true;
            } elseif (in_array("email", $ruleList)) {
                $body[$field] = "email@example.com";
            } elseif (in_array("date", $ruleList)) {
                $body[$field] = "2000-01-01";
            } elseif (str_contains($field, "password")) {
                $body[$field] = "123456";
            } else {
                $body[$field] = "sample_" . $field;
            }
        }

        return $body;
    }

    // ============================================================
    // 🧱 FILE UPLOAD SUPPORT
    // ============================================================
    private function detectBodyType(array $rules)
    {
        foreach ($rules as $field => $rule) {
            $list = is_array($rule) ? $rule : explode("|", $rule);

            if (
                in_array("image", $list) ||
                in_array("file", $list) ||
                collect($list)->contains(fn($v) => str_contains($v, "mimes"))
            ) {
                return "form-data";
            }
        }

        return "json";
    }

    private function convertToFormData(array $rules)
    {
        $data = [];

        foreach ($rules as $field => $rule) {
            $list = is_array($rule) ? $rule : explode("|", $rule);

            $isFile =
                in_array("image", $list) ||
                in_array("file", $list) ||
                str_contains($field, "image") ||
                str_contains($field, "file");

            $data[] = [
                "key"   => $field,
                "type"  => $isFile ? "file" : "text",
                "value" => $isFile ? null : "sample_" . $field,
            ];
        }

        return $data;
    }

    // ============================================================
    // 🔍 QUERY & PATH PARAMS
    // ============================================================
    private function extractQueryParams($route)
    {
        if (!str_contains($route->uri(), '?')) {
            return [];
        }

        $queryString = explode('?', $route->uri())[1];
        $params = explode('&', $queryString);

        return collect($params)->map(function ($param) {
            [$key, $val] = array_pad(explode("=", $param), 2, "");
            return ["key" => $key, "value" => $val];
        })->toArray();
    }

    private function extractPathParams($route)
    {
        preg_match_all('/\{(.*?)\}/', $route->uri(), $m);

        return collect($m[1])->map(fn($p) => [
            "key"         => $p,
            "description" => "Path parameter: $p"
        ])->toArray();
    }
}
