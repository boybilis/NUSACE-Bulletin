<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function calendar_http_fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'NUSACE Bulletin Calendar/1.0',
        ]);

        $result = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (is_string($result) && $result !== '' && $statusCode >= 200 && $statusCode < 400) {
            return $result;
        }

        throw new RuntimeException($error !== '' ? $error : 'Unable to download the published calendar feed.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: NUSACE Bulletin Calendar/1.0\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    if (!is_string($result) || trim($result) === '') {
        throw new RuntimeException('Unable to download the published calendar feed.');
    }

    return $result;
}

function unfold_ical_lines(string $ics): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $ics);
    $lines = explode("\n", $normalized);
    $unfolded = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        if ($unfolded !== [] && ($line[0] === ' ' || $line[0] === "\t")) {
            $unfolded[count($unfolded) - 1] .= substr($line, 1);
            continue;
        }

        $unfolded[] = $line;
    }

    return $unfolded;
}

function parse_ical_property(string $line): ?array
{
    $parts = explode(':', $line, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$propertyPart, $value] = $parts;
    $segments = explode(';', $propertyPart);
    $name = strtoupper((string) array_shift($segments));
    $parameters = [];

    foreach ($segments as $segment) {
        $parameterParts = explode('=', $segment, 2);
        if (count($parameterParts) !== 2) {
            continue;
        }

        $parameters[strtoupper($parameterParts[0])] = $parameterParts[1];
    }

    return [
        'name' => $name,
        'parameters' => $parameters,
        'value' => trim($value),
    ];
}

function decode_ical_text(string $value): string
{
    return str_replace(
        ['\\n', '\\N', '\\,', '\\;', '\\\\'],
        ["\n", "\n", ',', ';', '\\'],
        $value
    );
}

function parse_ical_datetime(string $value, array $parameters = []): ?DateTimeImmutable
{
    $timezone = new DateTimeZone('Asia/Manila');

    if (($parameters['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Ymd', substr($value, 0, 8), $timezone);
        return $date ?: null;
    }

    if (str_ends_with($value, 'Z')) {
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $date ? $date->setTimezone($timezone) : null;
    }

    if (isset($parameters['TZID']) && $parameters['TZID'] !== '') {
        try {
            $timezone = new DateTimeZone($parameters['TZID']);
        } catch (Throwable $exception) {
            $timezone = new DateTimeZone('Asia/Manila');
        }
    }

    $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $timezone);
    if ($date instanceof DateTimeImmutable) {
        return $date->setTimezone(new DateTimeZone('Asia/Manila'));
    }

    return null;
}

function build_calendar_event(array $properties): ?array
{
    $start = parse_ical_datetime((string) ($properties['DTSTART']['value'] ?? ''), (array) ($properties['DTSTART']['parameters'] ?? []));
    if (!$start instanceof DateTimeImmutable) {
        return null;
    }

    $end = parse_ical_datetime((string) ($properties['DTEND']['value'] ?? ''), (array) ($properties['DTEND']['parameters'] ?? []));
    $isAllDay = (($properties['DTSTART']['parameters']['VALUE'] ?? '') === 'DATE')
        || preg_match('/^\d{8}$/', (string) ($properties['DTSTART']['value'] ?? '')) === 1;

    if ($isAllDay && $end instanceof DateTimeImmutable) {
        $end = $end->modify('-1 day');
    }

    $summary = decode_ical_text((string) ($properties['SUMMARY']['value'] ?? 'Untitled event'));
    $description = decode_ical_text((string) ($properties['DESCRIPTION']['value'] ?? ''));
    $location = decode_ical_text((string) ($properties['LOCATION']['value'] ?? ''));
    $url = trim((string) ($properties['URL']['value'] ?? ''));
    $uid = trim((string) ($properties['UID']['value'] ?? ''));

    return [
        'id' => $uid !== '' ? $uid : sha1($summary . '|' . $start->format(DATE_ATOM)),
        'title' => $summary !== '' ? $summary : 'Untitled event',
        'description' => $description,
        'location' => $location,
        'url' => $url,
        'is_all_day' => $isAllDay,
        'starts_at' => $start->format(DATE_ATOM),
        'ends_at' => $end?->format(DATE_ATOM),
        'month_key' => $start->format('Y-m'),
        'sort_key' => $start->format('YmdHis'),
    ];
}

function parse_ical_events(string $ics): array
{
    $lines = unfold_ical_lines($ics);
    $events = [];
    $currentEvent = null;

    foreach ($lines as $line) {
        if (trim($line) === 'BEGIN:VEVENT') {
            $currentEvent = [];
            continue;
        }

        if (trim($line) === 'END:VEVENT') {
            if (is_array($currentEvent)) {
                $event = build_calendar_event($currentEvent);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            $currentEvent = null;
            continue;
        }

        if (!is_array($currentEvent)) {
            continue;
        }

        $property = parse_ical_property($line);
        if ($property === null) {
            continue;
        }

        $name = $property['name'];
        if (!isset($currentEvent[$name])) {
            $currentEvent[$name] = $property;
        }
    }

    usort($events, static fn (array $left, array $right): int => strcmp($left['sort_key'], $right['sort_key']));
    return $events;
}

function calendar_month_key(?string $value = null): string
{
    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
        return $value;
    }

    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m');
}

function available_calendar_months(array $events): array
{
    $months = [];

    foreach ($events as $event) {
        $monthKey = (string) ($event['month_key'] ?? '');
        if ($monthKey === '') {
            continue;
        }

        $months[$monthKey] = [
            'value' => $monthKey,
            'label' => (DateTimeImmutable::createFromFormat('!Y-m', $monthKey, new DateTimeZone('Asia/Manila')) ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('F Y'),
        ];
    }

    krsort($months);

    return array_values($months);
}

function filter_calendar_events_by_month(array $events, string $monthKey): array
{
    $filtered = array_values(array_filter($events, static fn (array $event): bool => (string) ($event['month_key'] ?? '') === $monthKey));

    return array_slice($filtered, 0, 50);
}

try {
    $selectedMonth = calendar_month_key(isset($_GET['month']) ? (string) $_GET['month'] : null);
    $ics = calendar_http_fetch(published_calendar_ics_url());
    $allEvents = parse_ical_events($ics);
    $availableMonths = available_calendar_months($allEvents);

    $hasSelectedMonth = false;
    foreach ($availableMonths as $month) {
        if (($month['value'] ?? '') === $selectedMonth) {
            $hasSelectedMonth = true;
            break;
        }
    }

    if (!$hasSelectedMonth && $availableMonths !== []) {
        $selectedMonth = (string) $availableMonths[0]['value'];
    }

    $events = filter_calendar_events_by_month($allEvents, $selectedMonth);

    echo json_encode([
        'ok' => true,
        'calendarHtmlUrl' => published_calendar_html_url(),
        'calendarIcsUrl' => published_calendar_ics_url(),
        'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
        'selectedMonth' => $selectedMonth,
        'availableMonths' => $availableMonths,
        'events' => $events,
    ], JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Unable to load the published calendar right now.',
        'details' => $exception->getMessage(),
        'calendarHtmlUrl' => published_calendar_html_url(),
        'calendarIcsUrl' => published_calendar_ics_url(),
    ], JSON_UNESCAPED_SLASHES);
}
