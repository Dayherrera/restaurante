<?php

namespace App\Support;

class UppercaseInput
{
    public static function fields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = mb_strtoupper($data[$field], 'UTF-8');
            }
        }
        return $data;
    }
}
