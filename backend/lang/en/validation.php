<?php

return [
    'required' => 'The :attribute field is required.',
    'email' => 'The :attribute must be a valid email address.',
    'string' => 'The :attribute must be a string.',
    'array' => 'The :attribute must be an array.',
    'integer' => 'The :attribute must be an integer.',
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must be at least :min characters.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'string' => 'The :attribute may not be greater than :max characters.',
        'array' => 'The :attribute may not have more than :max items.',
    ],
    'in' => 'The selected :attribute is invalid.',
    'date' => 'The :attribute must be a valid date.',
    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'name' => 'name',
        'abilities' => 'abilities',
        'expires_in_days' => 'expiration days',
    ],
];
