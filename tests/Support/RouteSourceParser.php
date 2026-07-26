<?php

declare(strict_types=1);

namespace tests\Support;

final class RouteSourceParser
{
    /** @return list<array{method:string,path:string,handler:string}> */
    public static function parse(string $source): array
    {
        $source = self::withoutComments($source);

        preg_match_all(
            "/Route::(get|head|post|put|patch|delete)\\(\\s*(['\"])(.*?)\\2\\s*,\\s*(['\"])(.*?)\\4/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $routes = array_map(
            static fn (array $match): array => [
                'method' => strtoupper($match[1]),
                'path' => $match[3],
                'handler' => $match[5],
            ],
            $matches
        );

        preg_match_all(
            "/Route::resource\\(\\s*(['\"])(.*?)\\1\\s*,\\s*(['\"])(.*?)\\3/",
            $source,
            $resources,
            PREG_SET_ORDER
        );

        foreach ($resources as $resource) {
            $routes[] = [
                'method' => 'RESOURCE',
                'path' => $resource[2],
                'handler' => $resource[4],
            ];
        }

        usort($routes, static fn (array $a, array $b): int => [$a['path'], $a['method'], $a['handler']] <=> [$b['path'], $b['method'], $b['handler']]);

        return $routes;
    }

    private static function withoutComments(string $source): string
    {
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $result .= is_array($token) ? $token[1] : $token;
        }

        return $result;
    }
}
