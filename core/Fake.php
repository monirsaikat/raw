<?php

// Fake data for factories and seeders: fake()->name(), fake()->email(), ...
// Seed with fake()->seed(42) for reproducible output; prefix a call with
// unique() to guarantee a value has not been returned before.

final class Fake
{
    private const FIRST_NAMES = [
        'Aisha', 'Amir', 'Ana', 'Ben', 'Carla', 'Chen', 'Dana', 'David', 'Elena', 'Emeka', 'Fatima', 'Felix',
        'Grace', 'Hana', 'Ivan', 'Jamal', 'Julia', 'Kenji', 'Layla', 'Leo', 'Maya', 'Mohammed', 'Nadia', 'Noah',
        'Olivia', 'Omar', 'Priya', 'Rafael', 'Sara', 'Tariq', 'Uma', 'Victor', 'Wei', 'Yara', 'Zain', 'Zoe',
    ];

    private const LAST_NAMES = [
        'Ahmed', 'Alvarez', 'Anderson', 'Brown', 'Chen', 'Costa', 'Diallo', 'Fischer', 'Garcia', 'Haddad',
        'Hassan', 'Ibrahim', 'Jensen', 'Khan', 'Kim', 'Kowalski', 'Lee', 'Martin', 'Meyer', 'Nakamura',
        'Nguyen', 'Okafor', 'Patel', 'Rahman', 'Rossi', 'Santos', 'Silva', 'Singh', 'Tanaka', 'Williams',
    ];

    private const WORDS = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod',
        'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam',
        'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea', 'commodo',
        'consequat', 'duis', 'aute', 'irure', 'in', 'reprehenderit', 'voluptate', 'velit', 'esse', 'cillum',
        'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat', 'non', 'proident', 'sunt',
        'culpa', 'qui', 'officia', 'deserunt', 'mollit', 'anim', 'id', 'est', 'laborum',
    ];

    private const DOMAINS = ['example.com', 'example.net', 'example.org'];
    private const CITIES = ['Dhaka', 'Berlin', 'Lagos', 'Lima', 'Madrid', 'Nairobi', 'Osaka', 'Porto', 'Seoul', 'Toronto', 'Vienna', 'Zurich'];
    private const COUNTRIES = ['Bangladesh', 'Brazil', 'Canada', 'Germany', 'India', 'Japan', 'Kenya', 'Nigeria', 'Peru', 'Portugal', 'South Korea', 'Spain'];
    private const STREETS = ['Main Street', 'Oak Avenue', 'Park Road', 'Lake View', 'Hill Street', 'River Lane', 'Station Road', 'Market Square'];
    private const COMPANY_SUFFIXES = ['Ltd', 'Inc', 'Group', 'Labs', 'Studio', 'Partners', 'Co', 'Systems'];
    private const JOBS = ['Engineer', 'Designer', 'Analyst', 'Manager', 'Consultant', 'Developer', 'Architect', 'Coordinator', 'Specialist', 'Director'];
    private const COLORS = ['red', 'green', 'blue', 'yellow', 'orange', 'purple', 'teal', 'pink', 'gray', 'black', 'white', 'brown'];

    private array $used = [];
    private bool $unique = false;

    public function seed(int $seed): static
    {
        mt_srand($seed);

        return $this;
    }

    public function unique(): static
    {
        $this->unique = true;

        return $this;
    }

    private function value(string $kind, callable $generator)
    {
        if (!$this->unique) {
            return $generator();
        }

        $this->unique = false;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $candidate = $generator();

            if (!isset($this->used[$kind][(string) $candidate])) {
                $this->used[$kind][(string) $candidate] = true;

                return $candidate;
            }
        }

        throw new RuntimeException("Could not generate a unique $kind.");
    }

    // ------------------------------------------------------------- people --

    public function firstName(): string
    {
        return $this->value('firstName', fn () => $this->randomElement(self::FIRST_NAMES));
    }

    public function lastName(): string
    {
        return $this->value('lastName', fn () => $this->randomElement(self::LAST_NAMES));
    }

    public function name(): string
    {
        return $this->value('name', fn () => $this->randomElement(self::FIRST_NAMES) . ' ' . $this->randomElement(self::LAST_NAMES));
    }

    public function userName(): string
    {
        return $this->value('userName', fn () => strtolower($this->randomElement(self::FIRST_NAMES) . '.' . $this->randomElement(self::LAST_NAMES)) . mt_rand(1, 999));
    }

    public function email(): string
    {
        return $this->value('email', fn () => strtolower($this->randomElement(self::FIRST_NAMES) . '.' . $this->randomElement(self::LAST_NAMES)) . mt_rand(1, 9999) . '@' . $this->randomElement(self::DOMAINS));
    }

    public function safeEmail(): string
    {
        return $this->email();
    }

    public function password(int $length = 12): string
    {
        return $this->value('password', fn () => substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes($length + 4))), 0, $length));
    }

    public function phone(): string
    {
        return $this->value('phone', fn () => sprintf('+%d %03d-%03d-%04d', mt_rand(1, 99), mt_rand(200, 999), mt_rand(100, 999), mt_rand(1000, 9999)));
    }

    public function jobTitle(): string
    {
        return ucfirst($this->word()) . ' ' . $this->randomElement(self::JOBS);
    }

    public function company(): string
    {
        return $this->value('company', fn () => ucfirst($this->word()) . ' ' . $this->randomElement(self::COMPANY_SUFFIXES));
    }

    // -------------------------------------------------------------- places --

    public function city(): string
    {
        return $this->randomElement(self::CITIES);
    }

    public function country(): string
    {
        return $this->randomElement(self::COUNTRIES);
    }

    public function postcode(): string
    {
        return (string) mt_rand(10000, 99999);
    }

    public function streetAddress(): string
    {
        return mt_rand(1, 999) . ' ' . $this->randomElement(self::STREETS);
    }

    public function address(): string
    {
        return $this->streetAddress() . ', ' . $this->city() . ' ' . $this->postcode() . ', ' . $this->country();
    }

    // --------------------------------------------------------------- text --

    public function word(): string
    {
        return $this->randomElement(self::WORDS);
    }

    public function words(int $count = 3, bool $asText = false): array|string
    {
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word();
        }

        return $asText ? implode(' ', $words) : $words;
    }

    public function sentence(int $words = 6): string
    {
        return ucfirst($this->words(max(1, $words), true)) . '.';
    }

    public function sentences(int $count = 3, bool $asText = false): array|string
    {
        $sentences = [];

        for ($i = 0; $i < $count; $i++) {
            $sentences[] = $this->sentence(mt_rand(4, 10));
        }

        return $asText ? implode(' ', $sentences) : $sentences;
    }

    public function paragraph(int $sentences = 3): string
    {
        return $this->sentences($sentences, true);
    }

    public function paragraphs(int $count = 3, bool $asText = false): array|string
    {
        $paragraphs = [];

        for ($i = 0; $i < $count; $i++) {
            $paragraphs[] = $this->paragraph(mt_rand(2, 5));
        }

        return $asText ? implode("\n\n", $paragraphs) : $paragraphs;
    }

    public function text(int $maxLength = 200): string
    {
        $text = '';

        while (strlen($text) < $maxLength) {
            $text .= ($text === '' ? '' : ' ') . $this->sentence(mt_rand(4, 10));
        }

        return strlen($text) <= $maxLength ? $text : rtrim(substr($text, 0, $maxLength - 1)) . '.';
    }

    public function title(int $words = 3): string
    {
        return $this->value('title', fn () => ucwords($this->words($words, true)));
    }

    public function slug(int $words = 3): string
    {
        return $this->value('slug', fn () => implode('-', $this->words($words)) . '-' . mt_rand(1, 9999));
    }

    // ------------------------------------------------------------ numbers --

    public function number(int $min = 0, int $max = 1000): int
    {
        return $this->value('number', fn () => mt_rand($min, $max));
    }

    public function numberBetween(int $min = 0, int $max = 1000): int
    {
        return $this->number($min, $max);
    }

    public function float(float $min = 0, float $max = 1000, int $decimals = 2): float
    {
        return round($min + ($max - $min) * (mt_rand() / mt_getrandmax()), $decimals);
    }

    public function digits(int $count = 6): string
    {
        return $this->value('digits', function () use ($count): string {
            $digits = '';

            for ($i = 0; $i < $count; $i++) {
                $digits .= mt_rand(0, 9);
            }

            return $digits;
        });
    }

    public function boolean(int $chanceOfTrue = 50): bool
    {
        return mt_rand(1, 100) <= $chanceOfTrue;
    }

    // -------------------------------------------------------------- dates --

    public function dateTime(string $from = '-1 year', string $to = 'now'): DateTimeValue
    {
        $start = strtotime($from);
        $end = strtotime($to);

        return DateTimeValue::parse(mt_rand(min($start, $end), max($start, $end)));
    }

    public function date(string $format = 'Y-m-d', string $from = '-1 year', string $to = 'now'): string
    {
        return $this->dateTime($from, $to)->format($format);
    }

    public function dateTimeBetween(string $from = '-1 year', string $to = 'now'): DateTimeValue
    {
        return $this->dateTime($from, $to);
    }

    public function time(string $format = 'H:i:s'): string
    {
        return $this->dateTime()->format($format);
    }

    // --------------------------------------------------------------- misc --

    public function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function domain(): string
    {
        return $this->randomElement(self::DOMAINS);
    }

    public function url(): string
    {
        return $this->value('url', fn () => 'https://' . $this->domain() . '/' . implode('-', $this->words(2)));
    }

    public function ipv4(): string
    {
        return $this->value('ipv4', fn () => mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254));
    }

    public function hexColor(): string
    {
        return sprintf('#%06x', mt_rand(0, 0xFFFFFF));
    }

    public function colorName(): string
    {
        return $this->randomElement(self::COLORS);
    }

    public function randomElement(array $items)
    {
        if ($items === []) {
            return null;
        }

        return $items[array_keys($items)[mt_rand(0, count($items) - 1)]];
    }

    public function randomElements(array $items, int $count = 1): array
    {
        $keys = array_keys($items);
        shuffle($keys);

        return array_map(fn ($key) => $items[$key], array_slice($keys, 0, min($count, count($keys))));
    }

    public function shuffle(array $items): array
    {
        shuffle($items);

        return $items;
    }
}
