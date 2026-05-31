<?php

use app\service\AzureChartDateRangeService;

function test_chart_range_treats_missing_gap_as_recent_24_hours(): void
{
    $range = AzureChartDateRangeService::fromGap(null, new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('Asia/Shanghai')));

    assertSameValue(true, $range['is_default'], '缺省 gap 应使用最近 24 小时');
    assertSameValue(null, $range['chart_day'], '缺省 gap 不应显示自然日标题');
    assertSameValue(null, $range['start_time'], '缺省 gap 应交给 AzureApi 默认时间窗口');
    assertSameValue(null, $range['end_time'], '缺省 gap 应交给 AzureApi 默认时间窗口');
}

function test_chart_range_maps_gap_two_to_previous_local_day(): void
{
    $range = AzureChartDateRangeService::fromGap('2', new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('Asia/Shanghai')));

    assertSameValue(false, $range['is_default'], 'gap=2 应使用自然日统计窗口');
    assertSameValue('2026-05-31', $range['chart_day'], 'gap=2 应显示上一日');
    assertSameValue('2026-05-30T16:00:00Z', $range['start_time'], '上一日开始时间应转换为 UTC');
    assertSameValue('2026-05-31T16:00:00Z', $range['end_time'], '上一日结束时间应转换为 UTC');
}

function test_chart_range_treats_zero_gap_as_recent_24_hours(): void
{
    $range = AzureChartDateRangeService::fromGap('0', new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('Asia/Shanghai')));

    assertSameValue(true, $range['is_default'], 'gap=0 不应请求未来自然日');
    assertSameValue(null, $range['chart_day'], 'gap=0 不应显示明天日期');
}
