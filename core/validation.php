<?php

function input($key = null, $default = null)
{
    if ($key === null) {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $_REQUEST);
    }

    $value = $_REQUEST[$key] ?? $default;

    return is_string($value) ? trim($value) : $value;
}

function validate(array $data, array $rules): array
{
    $errors = [];

    foreach ($rules as $field => $ruleset) {
        $value = $data[$field] ?? null;

        foreach (explode('|', $ruleset) as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            $error = match ($name) {
                'required' => ($value === null || $value === '') ? "$field is required." : null,
                'email' => ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) ? "$field must be a valid email." : null,
                'numeric' => ($value !== null && $value !== '' && !is_numeric($value)) ? "$field must be numeric." : null,
                'alpha' => ($value !== null && $value !== '' && !ctype_alpha((string) $value)) ? "$field must contain only letters." : null,
                'max' => ($value !== null && mb_strlen((string) $value) > (int) $param) ? "$field must not exceed $param characters." : null,
                'min' => ($value !== null && $value !== '' && mb_strlen((string) $value) < (int) $param) ? "$field must be at least $param characters." : null,
                'confirmed' => ($value !== null && $value !== '' && $value !== ($data[$field . '_confirmation'] ?? null)) ? "$field confirmation does not match." : null,
                default => null,
            };

            if ($error) {
                $errors[$field][] = $error;
                break;
            }
        }
    }

    return $errors;
}
