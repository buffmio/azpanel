<?php

declare(strict_types=1);

namespace tests\Support;

final class RouteSourceParser
{
    /** @return list<array{method:string,path:string,handler:string}> */
    public static function parse(string $source): array
    {
        preg_match_all(
            "/Route::(get|head|post|put|patch|delete)\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $routes = array_map(
            static fn (array $match): array => [
                'method' => strtoupper($match[1]),
                'path' => $match[2],
                'handler' => $match[3],
            ],
            $matches
        );

        preg_match_all(
            "/Route::resource\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/",
            $source,
            $resources,
            PREG_SET_ORDER
        );

        foreach ($resources as $resource) {
            $routes[] = [
                'method' => 'RESOURCE',
                'path' => $resource[1],
                'handler' => $resource[2],
            ];
        }

        usort($routes, static fn (array $a, array $b): int => [$a['path'], $a['method'], $a['handler']] <=> [$b['path'], $b['method'], $b['handler']]);

        return $routes;
    }
}
