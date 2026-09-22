<?php
// config/sanitize.php

function sanitizeString($val, $maxLen = 255) {
    if (!is_string($val)) return '';
    $val = trim($val);
    $val = strip_tags($val);
    $val = htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr($val, 0, $maxLen);
}

function sanitizeEmail($val) {
    $val = strtolower(trim($val ?? ''));
    return filter_var($val, FILTER_SANITIZE_EMAIL);
}

function sanitizeInt($val, $min = 0, $max = PHP_INT_MAX) {
    $val = intval($val);
    return max($min, min($max, $val));
}

function sanitizeFloat($val, $min = 0) {
    $val = floatval($val);
    return max($min, $val);
}

function sanitizeFilename($val) {
    // Only allow alphanumeric, dash, underscore, dot
    return preg_replace('/[^a-zA-Z0-9._-]/', '', basename($val ?? ''));
}

function validateRequired($fields, $body) {
    foreach ($fields as $field) {
        if (!isset($body[$field]) || trim((string)$body[$field]) === '') {
            respondError('Missing required field: ' . $field);
        }
    }
}