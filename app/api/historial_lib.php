<?php
// =============================================================
// INVENTARIO TALLER -- Historial permanente en fichero CSV
// Funciones compartidas por movimientos.php, equipos.php e
// historial.php. Requiere que config.php ya esté cargado.
//
// El fichero se guarda FUERA de la carpeta web (por defecto en
// /var/www/historial, volumen persistente), y solo se descarga
// a través de historial.php con sesión iniciada.
// =============================================================

function historialDir(): string {
    $dir = getenv('HISTORIAL_DIR') ?: '/var/www/historial';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        // Último recurso: carpeta temporal (no sobrevive a recrear el contenedor)
        $dir = sys_get_temp_dir() . '/inventario_historial';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    return $dir;
}

function historialRutaCsv(): string { return historialDir() . '/movimientos.csv'; }

function historialCabecera(): array {
    return ['ID movimiento', 'Fecha', 'Tipo', 'ID equipo', 'Equipo', 'Nº serie', 'Cantidad',
            'Ubicación origen', 'Ubicación destino', 'Operario', 'Proveedor', 'Cliente'];
}

function historialEtiquetaTipo(string $t): string {
    return [
        'suma'       => 'Entrada',
        'resta'      => 'Salida',
        'ubicacion'  => 'Cambio de ubicación',
        'separacion' => 'Separación de ubicaciones',
        'eliminacion'=> 'Eliminado del almacén',
    ][$t] ?? $t;
}

// Evita que Excel interprete una celda de texto como fórmula
function historialCelda($v): string {
    $v = (string)($v ?? '');
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

// ── Sesión (mismo esquema que backup.php) ────────────────────
function historialToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    $h = trim(preg_replace('/^Bearer\s+/i', '', trim((string)$h)));
    return $h !== '' ? $h : null;
}

function historialUsuarioActual(PDO $pdo): ?array {
    $token = historialToken();
    if (!$token) return null;
    try {
        $st = $pdo->prepare("
            SELECT u.id, u.usuario, u.nombre, u.rol, u.empleado_id
            FROM   sesiones s
            JOIN   usuarios u ON u.id = s.usuario_id
            WHERE  s.token = ? AND s.expira_en > NOW() AND u.activo = 1
        ");
        $st->execute([$token]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Ajustes ──────────────────────────────────────────────────
function historialMeses(PDO $pdo): int {
    try {
        $v = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'historial_csv_meses'")->fetchColumn();
        $m = (int)$v;
        return in_array($m, [0, 1, 2], true) ? $m : 0;
    } catch (Throwable $e) { return 0; }
}

// ── Copia del nombre, serie, categoría y notas del equipo ────
// Devuelve [nombre, serie, categoría, notas]
function historialInstantanea(PDO $pdo, int $equipoId): array {
    $st = $pdo->prepare("
        SELECT e.nombre, e.numero_serie, cat.nombre AS categoria,
               COALESCE(
                   NULLIF((SELECT GROUP_CONCAT(i.notas SEPARATOR ' | ')
                           FROM   equipo_estado_info i
                           WHERE  i.equipo_id = e.id AND i.notas IS NOT NULL AND i.notas <> ''), ''),
                   e.notas
               ) AS notas
        FROM   equipos e
        LEFT JOIN categorias cat ON cat.id = e.categoria_id
        WHERE  e.id = ?
    ");
    $st->execute([$equipoId]);
    $r = $st->fetch();
    return [$r['nombre'] ?? null, $r['numero_serie'] ?? null, $r['categoria'] ?? null, $r['notas'] ?? null];
}

// ── Consulta común (JSON, CSV de descarga y fichero del servidor) ──
function historialSqlBase(): string {
    return "
        SELECT m.id, m.fecha, m.tipo, m.cantidad, m.equipo_id,
               COALESCE(m.equipo_nombre, e.nombre)       AS equipo,
               COALESCE(m.equipo_serie,  e.numero_serie) AS serie,
               COALESCE(cat.nombre, m.equipo_categoria)  AS categoria,
               m.equipo_notas                            AS notas,
               (m.equipo_id IS NULL)                     AS eliminado,
               emp.nombre AS empleado,
               c.nombre   AS cliente,
               p.nombre   AS proveedor,
               CONCAT(uo.calle,'-',uo.lado,'-',uo.hueco,'-',uo.altura) AS ubicacion_origen,
               CONCAT(u.calle,'-',u.lado,'-',u.hueco,'-',u.altura)     AS ubicacion_nueva
        FROM   movimientos m
        LEFT JOIN equipos     e   ON e.id   = m.equipo_id
        LEFT JOIN categorias  cat ON cat.id = e.categoria_id
        LEFT JOIN empleados   emp ON emp.id = m.empleado_id
        LEFT JOIN clientes    c   ON c.id   = m.cliente_id
        LEFT JOIN proveedores p   ON p.id   = m.proveedor_id
        LEFT JOIN ubicaciones uo  ON uo.id  = m.ubicacion_origen_id
        LEFT JOIN ubicaciones u   ON u.id   = m.ubicacion_id
    ";
}

function historialFilaCsv(array $r): array {
    return [
        $r['id'],
        $r['fecha'],
        historialEtiquetaTipo((string)$r['tipo']),
        $r['equipo_id'] ?? '',
        historialCelda($r['equipo']),
        historialCelda($r['serie']),
        (int)$r['cantidad'] > 0 ? (int)$r['cantidad'] : '',
        historialCelda($r['ubicacion_origen']),
        historialCelda($r['ubicacion_nueva']),
        historialCelda($r['empleado']),
        historialCelda($r['proveedor']),
        historialCelda($r['cliente']),
    ];
}

// ── Escritura del fichero CSV ────────────────────────────────
function historialCsvAnadir(PDO $pdo, array $movIds): void {
    // El CSV es un extra: si falla NUNCA debe afectar a la respuesta de la API.
    ob_start();
    try {
        $movIds = array_values(array_filter(array_map('intval', $movIds)));
        if (!$movIds) return;
        $in = implode(',', array_fill(0, count($movIds), '?'));
        $st = $pdo->prepare(historialSqlBase() . " WHERE m.id IN ($in) ORDER BY m.id");
        $st->execute($movIds);
        $filas = $st->fetchAll();
        if (!$filas) return;

        $ruta = historialRutaCsv();
        $fh = @fopen($ruta, 'a');
        if (!$fh) throw new RuntimeException("No se puede escribir en $ruta (revisa los permisos de la carpeta del historial)");
        flock($fh, LOCK_EX);
        clearstatcache();
        $stat = fstat($fh);
        if (($stat['size'] ?? 0) === 0) {
            fwrite($fh, "\xEF\xBB\xBF");                 // BOM UTF-8 para Excel
            fputcsv($fh, historialCabecera(), ';', '"', '');
        }
        foreach ($filas as $f) fputcsv($fh, historialFilaCsv($f), ';', '"', '');
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        historialCsvPurgarSiToca($pdo);
    } catch (Throwable $e) {
        error_log('[historial] No se pudo escribir el CSV: ' . $e->getMessage());
    }
    ob_end_clean();
}

// Si el fichero empieza por BOM UTF-8, coloca el puntero justo después
function historialSaltarBom($fh): void {
    rewind($fh);
    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
}

// Elimina del CSV las filas más antiguas que $meses (0 = no borra nada)
function historialCsvPurgar(int $meses): int {
    $ruta = historialRutaCsv();
    if ($meses <= 0 || !is_file($ruta)) return 0;
    $corte = date('Y-m-d H:i:s', strtotime("-{$meses} months"));

    $fh = @fopen($ruta, 'c+');
    if (!$fh) return 0;
    flock($fh, LOCK_EX);
    rewind($fh);
    historialSaltarBom($fh);
    $cab = null; $filas = []; $quitadas = 0;
    while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if ($cab === null) { $cab = $r; continue; }
        if (count($r) < 2) continue;
        if ($r[1] < $corte) { $quitadas++; continue; }
        $filas[] = $r;
    }
    if ($quitadas > 0 && $cab !== null) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $cab, ';', '"', '');
        foreach ($filas as $r) fputcsv($fh, $r, ';', '"', '');
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $quitadas;
}

// Purga como mucho una vez al día
function historialCsvPurgarSiToca(PDO $pdo): void {
    $meses = historialMeses($pdo);
    if ($meses <= 0) return;
    $marca = historialDir() . '/.ultima_purga';
    if (is_file($marca) && filemtime($marca) > time() - 86400) return;
    historialCsvPurgar($meses);
    @touch($marca);
}

// ── Estado del fichero (para la pantalla de Historial) ───────
function historialEstadoFichero(): array {
    $ruta = historialRutaCsv();
    $info = ['ruta' => $ruta, 'existe' => is_file($ruta), 'bytes' => 0, 'filas' => 0,
             'desde' => null, 'hasta' => null];
    if (!$info['existe']) return $info;
    $info['bytes'] = filesize($ruta);
    $fh = @fopen($ruta, 'r');
    if (!$fh) return $info;
    historialSaltarBom($fh);
    $primera = true;
    while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if ($primera) { $primera = false; continue; }
        if (count($r) < 2) continue;
        $info['filas']++;
        if ($info['desde'] === null) $info['desde'] = $r[1];
        $info['hasta'] = $r[1];
    }
    fclose($fh);
    return $info;
}
