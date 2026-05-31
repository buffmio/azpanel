<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

class AzureChartDateRangeService
{
    public static function fromGap($gap, ?DateTimeImmutable $now = null): array
    {
        $gap = filter_var($gap, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($gap === false) {
            return [
                'is_default' => true,
                'start_time' => null,
                'end_time' => null,
                'chart_day' => null,
            ];
        }

        $localTimezone = $now !== null ? $now->getTimezone() : new DateTimeZone(date_default_timezone_get());
        $utcTimezone = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $localTimezone))->setTimezone($localTimezone);
        $localStart = $now->setTime(0, 0)->modify('-' . ($gap - 1) . ' days');
        $localEnd = $localStart->modify('+1 day');

        return [
            'is_default' => false,
            'start_time' => $localStart->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $localEnd->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z'),
            'chart_day' => $localStart->format('Y-m-d'),
        ];
    }
}
