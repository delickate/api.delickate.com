<?php

namespace App\Support\Collections;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The collection document shared by the API and the desktop app, plus
 * conversion to and from Postman collections (schema v2.0 and v2.1).
 *
 * Item shapes (native format):
 *   folder:  {id, type: "folder", name, items: [...]}
 *   request: {id, type: "request", name, method, url, headers: [kv], body, auth}
 *   kv:      {key, value, enabled}
 *   body:    {mode: none|raw|json|urlencoded|formdata, raw, urlencoded: [kv], formdata: [field]}
 *   field:   {key, value, enabled, type: text|file, src} (src = local file path, file fields only)
 *   auth:    {type: none|inherit|bearer|basic|apikey, bearer: {token},
 *             basic: {username, password}, apikey: {key, value, in: header|query}}
 */
class CollectionFormat
{
    public const FORMAT = 'api-collections';

    public const VERSION = 1;

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public const MAX_DEPTH = 25;

    public const MAX_ITEMS = 5000;

    private int $itemCount = 0;

    /** @var array<string, true> */
    private array $seenIds = [];

    /* ------------------------------------------------------------------
     | Normalising (anything we store goes through here)
     * ------------------------------------------------------------------ */

    public function normalizeItems(mixed $items, int $depth = 0): array
    {
        if ($depth === 0) {
            $this->itemCount = 0;
            $this->seenIds = [];
        }
        if (! is_array($items)) {
            return [];
        }
        if ($depth > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'items' => ['Folders inside a collection can be nested at most '.self::MAX_DEPTH.' levels deep.'],
            ]);
        }

        $result = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (++$this->itemCount > self::MAX_ITEMS) {
                throw ValidationException::withMessages([
                    'items' => ['A collection can hold at most '.self::MAX_ITEMS.' requests and folders.'],
                ]);
            }

            $id = $this->id($item['id'] ?? null);
            $name = $this->text($item['name'] ?? '', 255);

            if (($item['type'] ?? null) === 'folder') {
                $result[] = [
                    'id' => $id,
                    'type' => 'folder',
                    'name' => $name !== '' ? $name : 'New folder',
                    'items' => $this->normalizeItems($item['items'] ?? [], $depth + 1),
                ];

                continue;
            }

            $method = strtoupper($this->text($item['method'] ?? 'GET', 10));
            $result[] = [
                'id' => $id,
                'type' => 'request',
                'name' => $name !== '' ? $name : 'New request',
                'method' => in_array($method, self::METHODS, true) ? $method : 'GET',
                'url' => $this->text($item['url'] ?? '', 4000),
                'headers' => $this->normalizePairs($item['headers'] ?? []),
                'body' => $this->normalizeBody($item['body'] ?? []),
                'auth' => $this->normalizeAuth($item['auth'] ?? [], true),
            ];
        }

        return $result;
    }

    public function normalizeVariables(mixed $variables): array
    {
        return $this->normalizePairs($variables);
    }

    public function normalizeAuth(mixed $auth, bool $allowInherit = false): array
    {
        $auth = is_array($auth) ? $auth : [];
        $types = $allowInherit
            ? ['none', 'inherit', 'bearer', 'basic', 'apikey']
            : ['none', 'bearer', 'basic', 'apikey'];
        $type = in_array($auth['type'] ?? null, $types, true) ? $auth['type'] : ($allowInherit ? 'inherit' : 'none');

        return [
            'type' => $type,
            'bearer' => ['token' => $this->text($auth['bearer']['token'] ?? '', 4000)],
            'basic' => [
                'username' => $this->text($auth['basic']['username'] ?? '', 500),
                'password' => $this->text($auth['basic']['password'] ?? '', 500),
            ],
            'apikey' => [
                'key' => $this->text($auth['apikey']['key'] ?? '', 500),
                'value' => $this->text($auth['apikey']['value'] ?? '', 4000),
                'in' => ($auth['apikey']['in'] ?? 'header') === 'query' ? 'query' : 'header',
            ],
        ];
    }

    private function normalizeBody(mixed $body): array
    {
        $body = is_array($body) ? $body : [];
        $mode = in_array($body['mode'] ?? null, ['none', 'raw', 'json', 'urlencoded', 'formdata'], true)
            ? $body['mode']
            : 'none';

        return [
            'mode' => $mode,
            'raw' => $this->text($body['raw'] ?? '', 1_000_000),
            'urlencoded' => $this->normalizePairs($body['urlencoded'] ?? []),
            'formdata' => $this->normalizePairs($body['formdata'] ?? [], allowFiles: true),
        ];
    }

    private function normalizePairs(mixed $pairs, bool $allowFiles = false): array
    {
        if (! is_array($pairs)) {
            return [];
        }

        $result = [];
        foreach (array_values($pairs) as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $key = $this->text($pair['key'] ?? '', 500);
            $value = $this->text($pair['value'] ?? '', 10_000);
            $isFile = $allowFiles && ($pair['type'] ?? 'text') === 'file';
            $src = $isFile ? $this->text($pair['src'] ?? '', 2_000) : '';
            if ($key === '' && $value === '' && $src === '') {
                continue;
            }
            $entry = [
                'key' => $key,
                'value' => $isFile ? '' : $value,
                'enabled' => ! array_key_exists('enabled', $pair) || (bool) $pair['enabled'],
            ];
            if ($allowFiles) {
                $entry['type'] = $isFile ? 'file' : 'text';
                if ($isFile) {
                    $entry['src'] = $src;
                }
            }
            $result[] = $entry;
        }

        return array_slice($result, 0, 500);
    }

    /* ------------------------------------------------------------------
     | Export
     * ------------------------------------------------------------------ */

    public function toNative(array $collection): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'collection' => [
                'name' => $collection['name'],
                'description' => $collection['description'] ?? '',
                'variables' => $collection['variables'] ?? [],
                'auth' => $collection['auth'] ?? $this->normalizeAuth([]),
                'items' => $collection['items'] ?? [],
            ],
        ];
    }

    public function toPostman(array $collection): array
    {
        $result = [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => $collection['name'],
                'description' => $collection['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $this->itemsToPostman($collection['items'] ?? []),
            'variable' => array_map(fn ($pair) => [
                'key' => $pair['key'],
                'value' => $pair['value'],
                'disabled' => ! $pair['enabled'],
            ], $collection['variables'] ?? []),
        ];

        $auth = $this->authToPostman($collection['auth'] ?? []);
        if ($auth !== null) {
            $result['auth'] = $auth;
        }

        return $result;
    }

    private function itemsToPostman(array $items): array
    {
        return array_map(function (array $item) {
            if ($item['type'] === 'folder') {
                return ['name' => $item['name'], 'item' => $this->itemsToPostman($item['items'])];
            }

            $request = [
                'method' => $item['method'],
                'header' => array_map(fn ($pair) => [
                    'key' => $pair['key'],
                    'value' => $pair['value'],
                    'disabled' => ! $pair['enabled'],
                ], $item['headers']),
                'url' => $this->urlToPostman($item['url']),
            ];

            $body = $this->bodyToPostman($item['body']);
            if ($body !== null) {
                $request['body'] = $body;
            }

            if ($item['auth']['type'] === 'none') {
                $request['auth'] = ['type' => 'noauth'];
            } elseif ($auth = $this->authToPostman($item['auth'])) {
                $request['auth'] = $auth;
            }

            return ['name' => $item['name'], 'request' => $request, 'response' => []];
        }, $items);
    }

    private function urlToPostman(string $raw): array
    {
        $url = ['raw' => $raw];
        $queryStart = strpos($raw, '?');
        if ($queryStart !== false) {
            $query = [];
            foreach (explode('&', substr($raw, $queryStart + 1)) as $part) {
                if ($part === '') {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
                $query[] = ['key' => urldecode($key), 'value' => $value === null ? null : urldecode($value)];
            }
            $url['query'] = $query;
        }

        return $url;
    }

    private function bodyToPostman(array $body): ?array
    {
        $pairs = fn (array $list) => array_map(fn ($pair) => [
            'key' => $pair['key'],
            'value' => $pair['value'],
            'type' => 'text',
            'disabled' => ! $pair['enabled'],
        ], $list);

        return match ($body['mode']) {
            'json' => ['mode' => 'raw', 'raw' => $body['raw'], 'options' => ['raw' => ['language' => 'json']]],
            'raw' => ['mode' => 'raw', 'raw' => $body['raw']],
            'urlencoded' => ['mode' => 'urlencoded', 'urlencoded' => $pairs($body['urlencoded'])],
            'formdata' => ['mode' => 'formdata', 'formdata' => array_map(fn ($pair) => ($pair['type'] ?? 'text') === 'file'
                ? ['key' => $pair['key'], 'type' => 'file', 'src' => $pair['src'] ?? '', 'disabled' => ! $pair['enabled']]
                : ['key' => $pair['key'], 'value' => $pair['value'], 'type' => 'text', 'disabled' => ! $pair['enabled']], $body['formdata'])],
            default => null,
        };
    }

    private function authToPostman(array $auth): ?array
    {
        $entry = fn (string $key, string $value) => ['key' => $key, 'value' => $value, 'type' => 'string'];

        return match ($auth['type'] ?? 'none') {
            'bearer' => ['type' => 'bearer', 'bearer' => [$entry('token', $auth['bearer']['token'])]],
            'basic' => ['type' => 'basic', 'basic' => [
                $entry('username', $auth['basic']['username']),
                $entry('password', $auth['basic']['password']),
            ]],
            'apikey' => ['type' => 'apikey', 'apikey' => [
                $entry('key', $auth['apikey']['key']),
                $entry('value', $auth['apikey']['value']),
                $entry('in', $auth['apikey']['in']),
            ]],
            default => null,
        };
    }

    /* ------------------------------------------------------------------
     | Import (native or Postman), always returns normalised data
     * ------------------------------------------------------------------ */

    /**
     * @return array{name: string, description: string, variables: array, auth: array, items: array, source: string}
     */
    public function import(array $data): array
    {
        if (($data['format'] ?? null) === self::FORMAT && is_array($data['collection'] ?? null)) {
            $collection = $data['collection'];

            return [
                'name' => $this->nameOr($collection['name'] ?? '', 'Imported collection'),
                'description' => $this->text($collection['description'] ?? '', 20_000),
                'variables' => $this->normalizeVariables($collection['variables'] ?? []),
                'auth' => $this->normalizeAuth($collection['auth'] ?? []),
                'items' => $this->normalizeItems($collection['items'] ?? []),
                'source' => 'native',
            ];
        }

        if (is_array($data['info'] ?? null) && is_array($data['item'] ?? null)) {
            return [
                'name' => $this->nameOr($data['info']['name'] ?? '', 'Imported Postman collection'),
                'description' => $this->text($this->description($data['info']['description'] ?? ''), 20_000),
                'variables' => $this->normalizeVariables(array_map(fn ($variable) => [
                    'key' => $this->scalar($variable['key'] ?? ''),
                    'value' => $this->scalar($variable['value'] ?? ''),
                    'enabled' => empty($variable['disabled']),
                ], array_filter((array) ($data['variable'] ?? []), 'is_array'))),
                'auth' => $this->normalizeAuth($this->authFromPostman($data['auth'] ?? null) ?? []),
                'items' => $this->normalizeItems($this->itemsFromPostman($data['item'], null, 0)),
                'source' => 'postman',
            ];
        }

        throw ValidationException::withMessages([
            'data' => ['This file is not an API Collections export or a Postman collection (v2.0 / v2.1).'],
        ]);
    }

    private function itemsFromPostman(array $items, ?array $folderAuth, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // A folder has a nested "item" list; a request has "request".
            if (is_array($item['item'] ?? null)) {
                $auth = array_key_exists('auth', $item) ? $this->authFromPostman($item['auth']) : $folderAuth;
                $result[] = [
                    'type' => 'folder',
                    'name' => $this->scalar($item['name'] ?? ''),
                    'items' => $this->itemsFromPostman($item['item'], $auth, $depth + 1),
                ];

                continue;
            }

            $request = $item['request'] ?? [];
            if (is_string($request)) {
                $request = ['method' => 'GET', 'url' => $request];
            }
            if (! is_array($request)) {
                continue;
            }

            $auth = array_key_exists('auth', $request)
                ? $this->authFromPostman($request['auth'])
                : ($folderAuth ?? ['type' => 'inherit']);

            $result[] = [
                'type' => 'request',
                'name' => $this->scalar($item['name'] ?? ''),
                'method' => $this->scalar($request['method'] ?? 'GET'),
                'url' => $this->urlFromPostman($request['url'] ?? ''),
                'headers' => array_map(fn ($header) => [
                    'key' => $this->scalar($header['key'] ?? ''),
                    'value' => $this->scalar($header['value'] ?? ''),
                    'enabled' => empty($header['disabled']),
                ], array_filter((array) ($request['header'] ?? []), 'is_array')),
                'body' => $this->bodyFromPostman($request['body'] ?? null),
                'auth' => $auth ?? ['type' => 'none'],
            ];
        }

        return $result;
    }

    private function urlFromPostman(mixed $url): string
    {
        if (is_string($url)) {
            return $url;
        }
        if (! is_array($url)) {
            return '';
        }
        if (isset($url['raw']) && is_string($url['raw'])) {
            return $url['raw'];
        }

        $host = $url['host'] ?? '';
        $host = is_array($host) ? implode('.', array_map([$this, 'scalar'], $host)) : $this->scalar($host);
        $path = $url['path'] ?? '';
        $path = is_array($path) ? implode('/', array_map(fn ($segment) => is_array($segment)
            ? $this->scalar($segment['value'] ?? '')
            : $this->scalar($segment), $path)) : $this->scalar($path);

        $raw = (! empty($url['protocol']) ? $this->scalar($url['protocol']).'://' : '').$host;
        if (! empty($url['port'])) {
            $raw .= ':'.$this->scalar($url['port']);
        }
        if ($path !== '') {
            $raw .= '/'.ltrim($path, '/');
        }

        $query = array_filter((array) ($url['query'] ?? []), fn ($pair) => is_array($pair) && empty($pair['disabled']));
        if ($query !== []) {
            $raw .= '?'.implode('&', array_map(fn ($pair) => $this->scalar($pair['key'] ?? '')
                .(isset($pair['value']) ? '='.$this->scalar($pair['value']) : ''), $query));
        }

        return $raw;
    }

    private function bodyFromPostman(mixed $body): array
    {
        if (! is_array($body) || ! empty($body['disabled'])) {
            return ['mode' => 'none'];
        }

        $pairs = fn ($list) => array_map(fn ($pair) => [
            'key' => $this->scalar($pair['key'] ?? ''),
            'value' => ($pair['type'] ?? 'text') === 'file' ? '' : $this->scalar($pair['value'] ?? ''),
            'enabled' => empty($pair['disabled']),
        ], array_filter((array) $list, 'is_array'));

        // Postman stores a file field's path in "src" (a string, or an array of paths).
        $formFields = fn ($list) => array_map(function ($pair) {
            if (($pair['type'] ?? 'text') !== 'file') {
                return ['key' => $this->scalar($pair['key'] ?? ''), 'value' => $this->scalar($pair['value'] ?? ''), 'enabled' => empty($pair['disabled']), 'type' => 'text'];
            }
            $src = $pair['src'] ?? '';
            if (is_array($src)) {
                $src = array_values(array_filter($src, 'is_string'))[0] ?? '';
            }

            return ['key' => $this->scalar($pair['key'] ?? ''), 'value' => '', 'enabled' => empty($pair['disabled']), 'type' => 'file', 'src' => is_string($src) ? $src : ''];
        }, array_filter((array) $list, 'is_array'));

        switch ($body['mode'] ?? null) {
            case 'raw':
                $language = $body['options']['raw']['language'] ?? null;
                $raw = $this->scalar($body['raw'] ?? '');

                return [
                    'mode' => $language === 'json' || ($language === null && $this->looksLikeJson($raw)) ? 'json' : 'raw',
                    'raw' => $raw,
                ];
            case 'urlencoded':
                return ['mode' => 'urlencoded', 'urlencoded' => $pairs($body['urlencoded'] ?? [])];
            case 'formdata':
                return ['mode' => 'formdata', 'formdata' => $formFields($body['formdata'] ?? [])];
            case 'graphql':
                $graphql = (array) ($body['graphql'] ?? []);
                $variables = json_decode((string) ($graphql['variables'] ?? ''), true);

                return ['mode' => 'json', 'raw' => json_encode([
                    'query' => $this->scalar($graphql['query'] ?? ''),
                    'variables' => is_array($variables) ? $variables : new \stdClass(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
            default:
                return ['mode' => 'none'];
        }
    }

    private function authFromPostman(mixed $auth): ?array
    {
        if ($auth === null) {
            return null;
        }
        if (! is_array($auth)) {
            return ['type' => 'none'];
        }

        $type = $auth['type'] ?? 'noauth';
        $values = [];
        $entries = $auth[$type] ?? [];
        if (is_array($entries)) {
            if (array_is_list($entries)) {
                // v2.1: [{key, value}, ...]
                foreach ($entries as $entry) {
                    if (is_array($entry) && isset($entry['key'])) {
                        $values[$this->scalar($entry['key'])] = $this->scalar($entry['value'] ?? '');
                    }
                }
            } else {
                // v2.0: {key: value}
                foreach ($entries as $key => $value) {
                    $values[(string) $key] = $this->scalar($value);
                }
            }
        }

        return match ($type) {
            'bearer' => ['type' => 'bearer', 'bearer' => ['token' => $values['token'] ?? '']],
            'basic' => ['type' => 'basic', 'basic' => [
                'username' => $values['username'] ?? '',
                'password' => $values['password'] ?? '',
            ]],
            'apikey' => ['type' => 'apikey', 'apikey' => [
                'key' => $values['key'] ?? '',
                'value' => $values['value'] ?? '',
                'in' => ($values['in'] ?? 'header') === 'query' ? 'query' : 'header',
            ]],
            // Unsupported schemes (OAuth, digest, ...) are imported without auth.
            default => ['type' => 'none'],
        };
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    private function description(mixed $description): string
    {
        if (is_array($description)) {
            return $this->scalar($description['content'] ?? '');
        }

        return $this->scalar($description);
    }

    private function looksLikeJson(string $raw): bool
    {
        $trimmed = ltrim($raw);

        return $trimmed !== '' && in_array($trimmed[0], ['{', '['], true) && json_decode($raw) !== null;
    }

    private function nameOr(mixed $name, string $fallback): string
    {
        $name = $this->text($name, 160);

        return $name !== '' ? $name : $fallback;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr($this->scalar($value), 0, $max);
    }

    private function id(mixed $id): string
    {
        $id = $this->scalar($id);
        if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id) || isset($this->seenIds[$id])) {
            $id = (string) Str::uuid();
        }
        $this->seenIds[$id] = true;

        return $id;
    }
}
