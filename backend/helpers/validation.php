<?php
declare(strict_types=1);

/** Parse a timezone-aware ISO 8601 date-time and return it in UTC. */
function parse_utc_datetime(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        if ($date->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)
            && $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            return null;
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

/** Parse an exact YYYY-MM-DD calendar date in UTC. */
function parse_utc_date(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

/** Parse a positive integer identifier without accepting numeric prefixes or decimals. */
function positive_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

/** Check an inclusive UTC report range against its configured maximum length. */
function is_valid_utc_date_range(DateTimeImmutable $from, DateTimeImmutable $to, int $maxDays = 366): bool
{
    return $maxDays > 0
        && $to >= $from
        && (int) $from->diff($to)->format('%a') < $maxDays;
}

/** Validate that a class is in the future, has positive duration, and fits its duration limit. */
function is_valid_class_time_range(
    DateTimeImmutable $startsAt,
    DateTimeImmutable $endsAt,
    DateTimeImmutable $now,
    int $maxDurationSeconds = 14400
): bool {
    $duration = $endsAt->getTimestamp() - $startsAt->getTimestamp();
    return $startsAt > $now && $duration > 0 && $duration <= $maxDurationSeconds;
}

/** Accept positive decimal amounts with at most eight integer and two decimal digits. */
function is_valid_payment_amount(mixed $value): bool
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return false;
    }

    $amount = (string) $value;
    return preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $amount) === 1
        && (float) $amount > 0;
}

/** Accept zero or positive amounts within a DECIMAL(10,2) value range. */
function is_valid_nonnegative_amount(mixed $value): bool
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return false;
    }

    $amount = (string) $value;
    return preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $amount) === 1;
}

/** Prefix potentially executable spreadsheet cells with an apostrophe. */
function safe_csv_text(mixed $value): string
{
    $text = (string) ($value ?? '');
    return preg_match('/^[\x00-\x20]*[=+@\-]/', $text) === 1 ? "'" . $text : $text;
}
