<?php
// =============================================================
// INVENTARIO TALLER -- Historial general de movimientos
// GET  historial.php                    → JSON con filtros
//        ?q=texto &tipo=suma|resta|ubicacion|separacion|eliminacion
//        &solo_eliminados=1 &desde=YYYY-MM-DD &hasta=YYYY-MM-DD &limite=N
// GET  historial.php?formato=csv        → descarga CSV de la consulta filtrada
// GET  historial.php?accion=fichero     → descarga el CSV permanente del servidor
// GET  historial.php?accion=ajustes     → retención y estado del fichero
// POST historial.php {accion:'ajustes', meses:0|1|2}  (solo admin)
// Requiere sesión iniciada (cualquier rol).
// =============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/historial_lib.php';

$pdo     = getPDO();
$method  = $_SERVER['REQUEST_METHOD'];
$usuario = historialUsuarioActual($pdo);
if (!$usuario) responder(['error' => 'No autenticado'], 401);
$esAdmin = $usuario['rol'] === 'admin';

function historialFiltros(): array {
    $w = []; $p = [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $w[] = "(COALESCE(m.equipo_nombre, e.nombre) LIKE ? OR COALESCE(m.equipo_serie, e.numero_serie) LIKE ?)";
        $p[] = "%$q%"; $p[] = "%$q%";
    }
    $tipo = (string)($_GET['tipo'] ?? '');
    if (in_array($tipo, ['suma', 'resta', 'ubicacion', 'separacion', 'eliminacion'], true)) {
        $w[] = 'm.tipo = ?'; $p[] = $tipo;
    }
    if (($_GET['solo_eliminados'] ?? '') === '1') $w[] = 'm.equipo_id IS NULL';
    $desde = (string)($_GET['desde'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) { $w[] = 'm.fecha >= ?'; $p[] = $desde; }
    $hasta = (string)($_GET['hasta'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) { $w[] = 'm.fecha < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $hasta; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}

function historialConsultar(PDO $pdo, int $limite): array {
    [$where, $params] = historialFiltros();
    $st = $pdo->prepare(historialSqlBase() . $where . " ORDER BY m.fecha DESC, m.id DESC LIMIT " . $limite);
    $st->execute($params);
    return $st->fetchAll();
}

if ($method === 'GET') {
    $accion = $_GET['accion'] ?? '';

    if ($accion === 'ajustes') {
        responder([
            'meses'   => historialMeses($pdo),
            'fichero' => historialEstadoFichero(),
            'admin'   => $esAdmin,
        ]);
    }

    if ($accion === 'fichero') {
        $ruta = historialRutaCsv();
        if (!is_file($ruta)) responder(['error' => 'Todavía no hay fichero de historial'], 404);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="historial_servidor_' . date('Ymd') . '.csv"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }

    if (($_GET['formato'] ?? '') === 'csv') {
        $filas = historialConsultar($pdo, 100000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="historial_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, historialCabecera(), ';', '"', '');
        foreach ($filas as $f) fputcsv($out, historialFilaCsv($f), ';', '"', '');
        fclose($out);
        exit;
    }

    $limite = min(max((int)($_GET['limite'] ?? 500), 1), 5000);
    responder(historialConsultar($pdo, $limite));
}

if ($method === 'POST') {
    $d = leerBody();
    if (($d['accion'] ?? '') !== 'ajustes') responder(['error' => 'Acción no válida'], 400);
    if (!$esAdmin) responder(['error' => 'Solo los administradores pueden cambiar este ajuste'], 403);
    $meses = (int)($d['meses'] ?? -1);
    if (!in_array($meses, [0, 1, 2], true)) responder(['error' => 'Valor no válido (0, 1 o 2 meses)'], 400);

    $pdo->prepare("INSERT INTO ajustes (clave, valor) VALUES ('historial_csv_meses', ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([(string)$meses]);
    $quitadas = historialCsvPurgar($meses);
    @touch(historialDir() . '/.ultima_purga');
    responder(['meses' => $meses, 'filas_eliminadas_del_fichero' => $quitadas]);
}

responder(['error' => 'Método no permitido'], 405);
