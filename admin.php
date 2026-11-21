<?php
if (!empty($_GET['code']) || !empty($_GET['pin'])) {
    $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /' . $qs, true, 302);
    header('Cache-Control: no-store');
    exit;
}
require_once __DIR__ . '/admin.html';
?>
