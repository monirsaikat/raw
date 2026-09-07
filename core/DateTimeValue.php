<?php

// Immutable date-time used for model date attributes and now(). Prints as
// "Y-m-d H:i:s" (so {$post.created_at} keeps working in templates and JSON)
// and adds the handful of helpers a web app actually needs.

class DateTimeValue extends DateTimeImmutable implements JsonSerializable, Stringable
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function now(?DateTimeZone $timezone = null): static
    {
        return new static('now', $timezone);
    }

    public static function today(?DateTimeZone $timezone = null): static
    {
        return static::now($timezone)->startOfDay();
    }

    // Accepts strings, timestamps, DateTime objects; returns null for blanks.
    public static function parse($value, ?DateTimeZone $timezone = null): ?static
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if ($value instanceof static) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return (new static('@' . $value->getTimestamp()))->setTimezone($value->getTimezone());
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{9,}$/', $value))) {
            return (new static('@' . $value))->setTimezone($timezone ?? new DateTimeZone(date_default_timezone_get()));
        }

        try {
            return new static((string) $value, $timezone);
        } catch (Exception) {
            return null;
        }
    }

    public static function fromFormat(string $format, string $value, ?DateTimeZone $timezone = null): ?static
    {
        $date = parent::createFromFormat($format, $value, $timezone);

        return $date === false ? null : static::parse($date);
    }

    // ---------------------------------------------------------- formatting --

    public function __toString(): string
    {
        return $this->format(static::FORMAT);
    }

    public function jsonSerialize(): string
    {
        return $this->format(static::FORMAT);
    }

    public function toDateString(): string
    {
        return $this->format('Y-m-d');
    }

    public function toTimeString(): string
    {
        return $this->format('H:i:s');
    }

    public function toDateTimeString(): string
    {
        return $this->format(static::FORMAT);
    }

    public function toIso8601String(): string
    {
        return $this->format(DATE_ATOM);
    }

    public function timestamp(): int
    {
        return $this->getTimestamp();
    }

    // -------------------------------------------------------- arithmetic --

    public function addSeconds(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' seconds');
    }

    public function addMinutes(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' minutes');
    }

    public function addHours(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' hours');
    }

    public function addDays(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' days');
    }

    public function addWeeks(int $n): static
    {
        return $this->addDays($n * 7);
    }

    public function addMonths(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' months');
    }

    public function addYears(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' years');
    }

    public function subSeconds(int $n): static
    {
        return $this->addSeconds(-$n);
    }

    public function subMinutes(int $n): static
    {
        return $this->addMinutes(-$n);
    }

    public function subHours(int $n): static
    {
        return $this->addHours(-$n);
    }

    public function subDays(int $n): static
    {
        return $this->addDays(-$n);
    }

    public function subWeeks(int $n): static
    {
        return $this->addWeeks(-$n);
    }

    public function subMonths(int $n): static
    {
        return $this->addMonths(-$n);
    }

    public function subYears(int $n): static
    {
        return $this->addYears(-$n);
    }

    public function startOfDay(): static
    {
        return $this->setTime(0, 0, 0);
    }

    public function endOfDay(): static
    {
        return $this->setTime(23, 59, 59);
    }

    public function startOfMonth(): static
    {
        return $this->setDate((int) $this->format('Y'), (int) $this->format('m'), 1)->startOfDay();
    }

    public function endOfMonth(): static
    {
        return $this->setDate((int) $this->format('Y'), (int) $this->format('m'), (int) $this->format('t'))->endOfDay();
    }

    // -------------------------------------------------------- comparison --

    public function isPast(): bool
    {
        return $this < static::now();
    }

    public function isFuture(): bool
    {
        return $this > static::now();
    }

    public function isToday(): bool
    {
        return $this->toDateString() === static::now()->toDateString();
    }

    public function isSameDay(DateTimeInterface $other): bool
    {
        return $this->toDateString() === $other->format('Y-m-d');
    }

    public function equalTo(DateTimeInterface $other): bool
    {
        return $this->getTimestamp() === $other->getTimestamp();
    }

    public function diffInSeconds(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        $diff = ($other ?? static::now())->getTimestamp() - $this->getTimestamp();

        return $absolute ? abs($diff) : $diff;
    }

    public function diffInMinutes(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 60);
    }

    public function diffInHours(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 3600);
    }

    public function diffInDays(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 86400);
    }

    // "3 minutes ago", "in 2 days", "just now" — relative to now (or $other).
    public function diffForHumans(?DateTimeInterface $other = null): string
    {
        // diffInSeconds() is ($other - $this): negative when $this is later.
        $seconds = $this->diffInSeconds($other, false);
        $future = $seconds < 0;
        $seconds = abs($seconds);

        $units = [
            ['year', 31536000], ['month', 2592000], ['week', 604800],
            ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1],
        ];

        foreach ($units as [$name, $size]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);
                $text = $count . ' ' . $name . ($count === 1 ? '' : 's');

                return $future ? 'in ' . $text : $text . ' ago';
            }
        }

        return 'just now';
    }
}
