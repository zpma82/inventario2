<?php
// =============================================================
// INVENTARIO TALLER -- Lista de almacenes
// GET /api/almacenes.php  → [{id, codigo, nombre}]
// Público: la pantalla de login la necesita antes de tener sesión.
// =============================================================
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { responder(['error' => 'Método no permitido'], 405); }

$pdo = getPDO();
responder($pdo->query("SELECT id, codigo, nombre FROM almacenes ORDER BY id")->fetchAll());
