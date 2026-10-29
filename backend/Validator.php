<?php

class Validator
{
    public static function validate(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $name => $field) {
            $rules = $field['rules'] ?? [];
            $label = $field['label'] ?? ucwords(str_replace('_', ' ', $name));
            $type = (string) ($field['type'] ?? 'text');
            $value = $data[$name] ?? '';
            if (is_string($value)) {
                $value = trim($value);
            }

            $isEmpty = $value === '' || $value === null;

            if (in_array('required', $rules, true) && $isEmpty) {
                $errors[$name] = $label . ' is required.';
                continue;
            }
            if ($isEmpty) {
                continue;
            }

            if (in_array('email', $rules, true) || $type === 'email') {
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = $label . ' must be a valid email address.';
                    continue;
                }
            }

            if (in_array('phone', $rules, true) || $type === 'tel') {
                if (!valid_phone((string) $value)) {
                    $errors[$name] = phone_validation_message();
                    continue;
                }
            }

            if (in_array('numeric', $rules, true) || in_array($type, ['number', 'decimal', 'float', 'integer'], true)) {
                if (!is_numeric($value)) {
                    $errors[$name] = $label . ' must be a number.';
                    continue;
                }
            }

            if (isset($field['min']) && is_numeric($value) && (float) $value < (float) $field['min']) {
                $errors[$name] = $label . ' must be at least ' . $field['min'] . '.';
            }
            if (isset($field['max']) && is_numeric($value) && (float) $value > (float) $field['max']) {
                $errors[$name] = $label . ' cannot exceed ' . $field['max'] . '.';
            }

            $maxLen = $field['maxlength'] ?? null;
            if ($maxLen !== null && is_string($value) && mb_strlen($value) > (int) $maxLen) {
                $errors[$name] = $label . ' is too long (max ' . (int) $maxLen . ' characters).';
            }
            $minLen = $field['minlength'] ?? null;
            if ($minLen !== null && is_string($value) && mb_strlen($value) < (int) $minLen) {
                $errors[$name] = $label . ' is too short (min ' . (int) $minLen . ' characters).';
            }

            if (in_array('date', $rules, true) || $type === 'date') {
                $dt = date_create((string) $value);
                if (!$dt) {
                    $errors[$name] = $label . ' must be a valid date.';
                    continue;
                }
            }

            if (($type === 'select' || !empty($field['options'])) && !empty($field['options']) && is_array($field['options'])) {
                $allowed = array_is_list($field['options'])
                    ? array_map('strval', $field['options'])
                    : array_map('strval', array_keys($field['options']));
                if (!in_array((string) $value, $allowed, true)) {
                    $errors[$name] = $label . ' has an invalid selection.';
                }
            }

            if (!empty($field['in']) && is_array($field['in'])) {
                if (!in_array((string) $value, array_map('strval', $field['in']), true)) {
                    $errors[$name] = $label . ' has an invalid value.';
                }
            }
        }

        if (!empty($data['end_date']) && !empty($data['start_date']) && $data['end_date'] < $data['start_date']) {
            $errors['end_date'] = 'End date cannot be before start date.';
        }
        if (!empty($data['period_end']) && !empty($data['period_start']) && $data['period_end'] < $data['period_start']) {
            $errors['period_end'] = 'Period end cannot be before start.';
        }
        if (!empty($data['due_date']) && !empty($data['invoice_date']) && $data['due_date'] < $data['invoice_date']) {
            $errors['due_date'] = 'Due date cannot be before invoice date.';
        }
        return $errors;
    }
}
