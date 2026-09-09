<?php

// Validation. validate() returns an errors array; validated() throws a
// ValidationException — which the exception handler turns into a redirect
// back with errors + old input (or a 422 JSON payload) — and returns only
// the fields you asked for, safe to hand straight to a model:
//
//   $data = validated(input(), [
//       'email' => 'required|email|unique:users,email',
//       'password' => 'required|min:8|confirmed',
//   ]);
//
// Rules run in order and stop at the first failure per field. Rules other
// than required/present/accepted are skipped when the value is empty, so
// 'email' alone allows a blank field; add 'required' to forbid it.
// Add your own with validator_extend('rule', fn ($value, $params, $data, $field) => bool, 'message').

const VALIDATION_IMPLICIT = ['required', 'required_if', 'required_with', 'required_without', 'present', 'accepted'];

$validatorRules = [];
$validatorMessages = [];

function validator_extend(string $name, callable $rule, string $message = 'The :field field is invalid.'): void
{
    global $validatorRules, $validatorMessages;

    $validatorRules[$name] = $rule;
    $validatorMessages[$name] = $message;
}

function validation_is_empty($value): bool
{
    return $value === null || $value === '' || $value === [];
}

// Size of a value for min/max/between/size: number, item count or length.
function validation_size($value, bool $numeric): float|int
{
    if ($numeric && is_numeric($value)) {
        return $value + 0;
    }

    if (is_array($value)) {
        return count($value);
    }

    return mb_strlen((string) $value);
}

function validation_message(string $field, string $rule, array $params, array $custom): string
{
    global $validatorMessages;

    // Precedence: messages passed to validate(), lang/<locale>/validation.php
    // (custom.<field>.<rule>, then <rule>), the rule's built-in message.
    $template = $custom[$field . '.' . $rule]
        ?? $custom[$rule]
        ?? validation_lang_line('custom.' . $field . '.' . $rule)
        ?? validation_lang_line($rule)
        ?? $validatorMessages[$rule]
        ?? 'The :field field is invalid.';

    $label = validation_lang_line('attributes.' . $field) ?? str_replace(['_', '-'], ' ', $field);
    $otherLabel = validation_lang_line('attributes.' . ($params[0] ?? '')) ?? str_replace(['_', '-'], ' ', $params[0] ?? '');

    return strtr($template, [
        ':attribute' => $label,
        ':field' => $label,
        ':param' => $params[0] ?? '',
        ':params' => implode(', ', $params),
        ':min' => $params[0] ?? '',
        ':max' => $params === [] ? '' : end($params),
        ':other' => $otherLabel,
        ':value' => $params[1] ?? '',
    ]);
}

// A string from lang/<locale>/validation.php, null when the lang module is
// not loaded or the key is missing (arrays such as `custom` are skipped).
function validation_lang_line(string $key): ?string
{
    if (!function_exists('lang_line')) {
        return null;
    }

    $line = lang_line('validation.' . $key, app_locale());

    if ($line === null && app_locale() !== fallback_locale()) {
        $line = lang_line('validation.' . $key, fallback_locale());
    }

    return is_string($line) ? $line : null;
}

function validate(array $data, array $rules, array $messages = []): array
{
    global $validatorRules;

    $errors = [];

    foreach ($rules as $field => $definition) {
        $definition = is_array($definition) ? $definition : explode('|', (string) $definition);
        $definition = array_values(array_filter(array_map('trim', $definition), fn ($rule) => $rule !== ''));

        $names = array_map(fn ($rule) => explode(':', $rule, 2)[0], $definition);
        $value = $data[$field] ?? null;

        if (in_array('sometimes', $names, true) && !array_key_exists($field, $data)) {
            continue;
        }

        $numeric = in_array('numeric', $names, true) || in_array('integer', $names, true);

        foreach ($definition as $rule) {
            [$name, $paramString] = array_pad(explode(':', $rule, 2), 2, null);

            if (in_array($name, ['nullable', 'sometimes', 'bail'], true)) {
                continue;
            }

            if (validation_is_empty($value) && !in_array($name, VALIDATION_IMPLICIT, true)) {
                continue;
            }

            if ($paramString === null || $paramString === '') {
                $params = [];
            } elseif (in_array($name, ['regex', 'not_regex'], true)) {
                $params = [$paramString];
            } else {
                $params = array_map('trim', explode(',', $paramString));
            }

            if (!isset($validatorRules[$name])) {
                throw new InvalidArgumentException("Unknown validation rule [$name].");
            }

            if (!$validatorRules[$name]($value, $params, $data, $field, $numeric)) {
                $errors[$field][] = validation_message($field, $name, $params, $messages);

                break;
            }
        }
    }

    return $errors;
}

// Validates or throws; returns only the keys named in $rules.
function validated(array $data, array $rules, array $messages = []): array
{
    $errors = validate($data, $rules, $messages);

    if ($errors !== []) {
        throw new ValidationException($errors, old_input_filter($data));
    }

    return array_intersect_key($data, $rules);
}

// Strips secrets and framework fields before input is flashed for re-display.
function old_input_filter(array $data): array
{
    return array_filter($data, function ($value, $key): bool {
        if (in_array($key, ['_token', '_method'], true) || str_contains(strtolower((string) $key), 'password')) {
            return false;
        }

        return is_scalar($value) || is_array($value) || $value === null;
    }, ARRAY_FILTER_USE_BOTH);
}

// Manual counterpart of validated(): flash errors + input and go back.
function back_with_errors(array $errors, ?array $old = null): Response
{
    flash('errors', $errors);
    flash('old', old_input_filter($old ?? input()));

    return back();
}

// ---------------------------------------------------------- built-in rules --

validator_extend('required', fn ($v) => !validation_is_empty($v), 'The :field field is required.');
validator_extend('present', fn ($v, $p, $data, $field) => array_key_exists($field, $data), 'The :field field must be present.');
validator_extend('required_if', function ($v, $p, $data) {
    $other = $data[$p[0] ?? ''] ?? null;

    return in_array((string) $other, array_slice($p, 1), true) ? !validation_is_empty($v) : true;
}, 'The :field field is required when :other is :value.');
validator_extend('required_with', fn ($v, $p, $data) => validation_is_empty($data[$p[0] ?? ''] ?? null) || !validation_is_empty($v), 'The :field field is required when :other is present.');
validator_extend('required_without', fn ($v, $p, $data) => !validation_is_empty($data[$p[0] ?? ''] ?? null) || !validation_is_empty($v), 'The :field field is required when :other is not present.');
validator_extend('accepted', fn ($v) => in_array($v, ['yes', 'on', '1', 1, true, 'true'], true), 'The :field must be accepted.');

validator_extend('string', fn ($v) => is_string($v), 'The :field must be a string.');
validator_extend('numeric', fn ($v) => is_numeric($v), 'The :field must be a number.');
validator_extend('integer', fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false, 'The :field must be an integer.');
validator_extend('boolean', fn ($v) => in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true), 'The :field must be true or false.');
validator_extend('array', fn ($v) => is_array($v), 'The :field must be an array.');
validator_extend('email', fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false, 'The :field must be a valid email address.');
validator_extend('url', fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false, 'The :field must be a valid URL.');
validator_extend('ip', fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false, 'The :field must be a valid IP address.');
validator_extend('json', fn ($v) => is_string($v) && json_validate($v), 'The :field must be valid JSON.');
validator_extend('date', fn ($v) => is_string($v) && strtotime($v) !== false, 'The :field is not a valid date.');
validator_extend('date_format', function ($v, $p) {
    if (!is_string($v)) {
        return false;
    }

    $date = DateTime::createFromFormat($p[0] ?? '', $v);

    return $date !== false && $date->format($p[0] ?? '') === $v;
}, 'The :field does not match the format :param.');
validator_extend('before', fn ($v, $p) => is_string($v) && ($t = strtotime($v)) !== false && ($o = strtotime($p[0] ?? '')) !== false && $t < $o, 'The :field must be a date before :param.');
validator_extend('after', fn ($v, $p) => is_string($v) && ($t = strtotime($v)) !== false && ($o = strtotime($p[0] ?? '')) !== false && $t > $o, 'The :field must be a date after :param.');

validator_extend('alpha', fn ($v) => is_string($v) && preg_match('/^[\pL\pM]+$/u', $v) === 1, 'The :field may only contain letters.');
validator_extend('alpha_num', fn ($v) => is_string($v) && preg_match('/^[\pL\pM\pN]+$/u', $v) === 1, 'The :field may only contain letters and numbers.');
validator_extend('alpha_dash', fn ($v) => is_string($v) && preg_match('/^[\pL\pM\pN_-]+$/u', $v) === 1, 'The :field may only contain letters, numbers, dashes and underscores.');
validator_extend('digits', fn ($v, $p) => is_scalar($v) && preg_match('/^\d+$/', (string) $v) === 1 && strlen((string) $v) === (int) ($p[0] ?? 0), 'The :field must be :param digits.');
validator_extend('regex', fn ($v, $p) => is_scalar($v) && preg_match($p[0], (string) $v) === 1, 'The :field format is invalid.');
validator_extend('not_regex', fn ($v, $p) => is_scalar($v) && preg_match($p[0], (string) $v) === 0, 'The :field format is invalid.');
validator_extend('starts_with', function ($v, $p) {
    foreach ($p as $prefix) {
        if (is_string($v) && str_starts_with($v, $prefix)) {
            return true;
        }
    }

    return false;
}, 'The :field must start with one of: :params.');
validator_extend('ends_with', function ($v, $p) {
    foreach ($p as $suffix) {
        if (is_string($v) && str_ends_with($v, $suffix)) {
            return true;
        }
    }

    return false;
}, 'The :field must end with one of: :params.');

validator_extend('min', fn ($v, $p, $d, $f, $numeric) => validation_size($v, $numeric) >= (float) ($p[0] ?? 0), 'The :field must be at least :min.');
validator_extend('max', fn ($v, $p, $d, $f, $numeric) => validation_size($v, $numeric) <= (float) ($p[0] ?? 0), 'The :field may not be greater than :max.');
validator_extend('between', function ($v, $p, $d, $f, $numeric) {
    $size = validation_size($v, $numeric);

    return $size >= (float) ($p[0] ?? 0) && $size <= (float) ($p[1] ?? 0);
}, 'The :field must be between :min and :max.');
validator_extend('size', fn ($v, $p, $d, $f, $numeric) => validation_size($v, $numeric) == (float) ($p[0] ?? 0), 'The :field must be :param.');

validator_extend('in', fn ($v, $p) => is_scalar($v) && in_array((string) $v, $p, true), 'The selected :field is invalid.');
validator_extend('not_in', fn ($v, $p) => !is_scalar($v) || !in_array((string) $v, $p, true), 'The selected :field is invalid.');
validator_extend('confirmed', fn ($v, $p, $data, $field) => $v === ($data[$field . '_confirmation'] ?? null), 'The :field confirmation does not match.');
validator_extend('same', fn ($v, $p, $data) => $v === ($data[$p[0] ?? ''] ?? null), 'The :field and :other must match.');
validator_extend('different', fn ($v, $p, $data) => $v !== ($data[$p[0] ?? ''] ?? null), 'The :field and :other must be different.');

// unique:table,column,exceptId,idColumn — column defaults to the field name.
validator_extend('unique', function ($v, $p, $data, $field) {
    $column = ($p[1] ?? '') !== '' ? $p[1] : $field;
    $query = Database::table($p[0] ?? '')->where($column, $v);

    if (($p[2] ?? '') !== '') {
        $query->where(($p[3] ?? '') !== '' ? $p[3] : 'id', '<>', $p[2]);
    }

    return !$query->exists();
}, 'The :field has already been taken.');

// exists:table,column — column defaults to the field name.
validator_extend('exists', function ($v, $p, $data, $field) {
    $column = ($p[1] ?? '') !== '' ? $p[1] : $field;

    return Database::table($p[0] ?? '')->where($column, $v)->exists();
}, 'The selected :field is invalid.');
