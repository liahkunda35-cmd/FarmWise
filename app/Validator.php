<?php

class Validator
{
    public static function validate(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $name => $field) {
            $rules = $field['rules'] ?? [];
            $label = $field['label'] ?? ucwords(str_replace('_', ' ', $name));
            $value = $data[$name] ?? '';
            if (in_array('required', $rules, true) && ($value === '' || $value === null)) {
                $errors[$name] = $label . ' is required.';
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array('email', $rules, true) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = $label . ' must be a valid email.';
            }
            if (in_array('numeric', $rules, true) && !is_numeric($value)) {
                $errors[$name] = $label . ' must be a number.';
            }
            if (isset($field['min']) && is_numeric($value) && (float) $value < (float) $field['min']) {
                $errors[$name] = $label . ' must be at least ' . $field['min'] . '.';
            }
            if (isset($field['max']) && is_numeric($value) && (float) $value > (float) $field['max']) {
                $errors[$name] = $label . ' cannot exceed ' . $field['max'] . '.';
            }
            if (in_array('date', $rules, true) && !strtotime((string) $value)) {
                $errors[$name] = $label . ' must be a valid date.';
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
