<?php

// Validation messages. :field (or :attribute) is the field name, :min /
// :max / :param / :other / :value come from the rule parameters. Override a
// message for one field under "custom", rename a field under "attributes".

return [
    'required' => 'The :field field is required.',
    'present' => 'The :field field must be present.',
    'required_if' => 'The :field field is required when :other is :value.',
    'required_with' => 'The :field field is required when :other is present.',
    'required_without' => 'The :field field is required when :other is not present.',
    'accepted' => 'The :field must be accepted.',
    'string' => 'The :field must be a string.',
    'numeric' => 'The :field must be a number.',
    'integer' => 'The :field must be an integer.',
    'boolean' => 'The :field must be true or false.',
    'array' => 'The :field must be an array.',
    'email' => 'The :field must be a valid email address.',
    'url' => 'The :field must be a valid URL.',
    'ip' => 'The :field must be a valid IP address.',
    'json' => 'The :field must be valid JSON.',
    'date' => 'The :field is not a valid date.',
    'date_format' => 'The :field does not match the format :param.',
    'before' => 'The :field must be a date before :param.',
    'after' => 'The :field must be a date after :param.',
    'alpha' => 'The :field may only contain letters.',
    'alpha_num' => 'The :field may only contain letters and numbers.',
    'alpha_dash' => 'The :field may only contain letters, numbers, dashes and underscores.',
    'digits' => 'The :field must be :param digits.',
    'regex' => 'The :field format is invalid.',
    'not_regex' => 'The :field format is invalid.',
    'starts_with' => 'The :field must start with one of: :params.',
    'ends_with' => 'The :field must end with one of: :params.',
    'min' => 'The :field must be at least :min.',
    'max' => 'The :field may not be greater than :max.',
    'between' => 'The :field must be between :min and :max.',
    'size' => 'The :field must be :param.',
    'in' => 'The selected :field is invalid.',
    'not_in' => 'The selected :field is invalid.',
    'confirmed' => 'The :field confirmation does not match.',
    'same' => 'The :field and :other must match.',
    'different' => 'The :field and :other must be different.',
    'unique' => 'The :field has already been taken.',
    'exists' => 'The selected :field is invalid.',

    // "custom" => ["email" => ["required" => "We need your email address."]],
    "custom" => [],

    // "attributes" => ["email" => "email address"],
    "attributes" => [],
];
