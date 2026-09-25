<?php

namespace Cloudexus\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, callable $handler): void
    {
        $this->routes['PUT'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes[$method] ?? [] as $routePath => $handler) {
            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $routePath);
            if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
                $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                $handler(...array_values($params));
                return;
            }
        }

        http_response_code(404);
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['status' => 404, 'message' => 'Unknown API endpoint: ' . $method . ' ' . $path]], JSON_UNESCAPED_SLASHES);
            return;
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // The dark colours: chosen outright, or the system's when that is the choice.
        $dark = 'body{background:#0f1220;color:#e3e6f1}h1,a{color:#7580f0}p{color:#9097ad}';
        $mode = Theme::mode();
        echo '<!DOCTYPE html><html lang="' . $esc(Lang::locale()) . '"><head><meta charset="utf-8"><title>404 — Cloudexus</title>'
            . '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;'
            . 'min-height:100vh;margin:0;background:#f2f4fb;color:#1c2333}div{text-align:center}'
            . 'h1{font-size:5rem;margin:0;color:#4f5bd5}p{color:#7c8494}a{color:#4f5bd5;text-decoration:none}'
            . ($mode === 'dark' ? $dark : ($mode === 'system' ? '@media (prefers-color-scheme: dark){' . $dark . '}' : '')) . '</style>'
            . '</head><body><div><h1>404</h1><p>' . $esc(Lang::get('errors.not_found')) . '</p>'
            . '<a href="javascript:history.back()">&laquo; ' . $esc(Lang::get('errors.back_to_previous')) . '</a></div></body></html>';
    }
}
