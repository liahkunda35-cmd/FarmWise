<?php

class PasswordPolicy
{
    public static function rules(): array
    {
        return [
            'length' => 'At least 8 characters',
            'upper' => 'At least one uppercase letter (A–Z)',
            'lower' => 'At least one lowercase letter (a–z)',
            'number' => 'At least one number (0–9)',
            'special' => 'At least one special character (!@#$%^&* etc.)',
        ];
    }

    public static function errors(string $password): array
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include an uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must include a lowercase letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must include a number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must include a special character.';
        }
        return $errors;
    }

    public static function isStrong(string $password): bool
    {
        return self::errors($password) === [];
    }

    public static function box(bool $includeMatch = false): string
    {
        $items = '';
        foreach (self::rules() as $key => $label) {
            $items .= '<li data-rule="' . e($key) . '">' . e($label) . '</li>';
        }
        if ($includeMatch) {
            $items .= '<li data-rule="match">Passwords must match</li>';
        }
        return '<div class="password-reqs" data-password-reqs>'
            . '<p class="password-reqs-title">Password must include:</p>'
            . '<ul>' . $items . '</ul>'
            . '</div>';
    }
}
