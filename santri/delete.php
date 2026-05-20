<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $statement = $pdo->prepare('DELETE FROM santri WHERE id = :id');
    $statement->execute(['id' => $id]);
    set_flash('success', 'Data santri berhasil dihapus.');
}

header('Location: /santri/index.php');
exit;
