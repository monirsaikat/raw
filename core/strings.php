<?php

// Small string utilities used by the core (model table names, generators)
// and available to app code.

function class_basename(string|object $class): string
{
    $class = is_object($class) ? get_class($class) : $class;

    return basename(str_replace('\\', '/', $class));
}

function str_snake(string $value, string $delimiter = '_'): string
{
    $value = preg_replace('/([a-z0-9])([A-Z])/', '$1' . $delimiter . '$2', $value);
    $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1' . $delimiter . '$2', $value);

    return strtolower(str_replace(['-', ' '], $delimiter, $value));
}

function str_studly(string $value): string
{
    return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
}

function str_camel(string $value): string
{
    return lcfirst(str_studly($value));
}

// Good enough for table names; override Model::$table for irregular nouns.
function str_plural(string $value): string
{
    if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
        return $value . 'es';
    }

    if (preg_match('/[^aeiou]y$/i', $value)) {
        return substr($value, 0, -1) . 'ies';
    }

    return $value . 's';
}

function str_slug(string $value, string $separator = '-'): string
{
    if (function_exists('transliterator_transliterate')) {
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
    }

    $value = preg_replace('/[^\pL\pN]+/u', $separator, $value);

    return strtolower(trim($value, $separator));
}

function str_limit(string $value, int $limit = 100, string $end = '...'): string
{
    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $limit)) . $end;
}

function str_random(int $length = 16): string
{
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}
