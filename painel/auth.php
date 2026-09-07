<?php

require_once __DIR__ . '/auth_helpers.php';

if (empty($_SESSION['painel_id'])) {
    header("Location: index.php");
    exit;
}
