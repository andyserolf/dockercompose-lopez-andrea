<?php
header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function conectarDB(): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME'));
    return new PDO($dsn, getenv('DB_USER'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function conectarRedis(): Redis {
    $r = new Redis();
    $r->connect(getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'), 2);
    return $r;
}

function existe(PDO $db, int $id): bool {
    $st = $db->prepare('SELECT id FROM reservaciones WHERE id = ?');
    $st->execute([$id]);
    return (bool) $st->fetch();
}

function leerSmtp($fp): void {
    while (($linea = fgets($fp, 512)) !== false) {
        if (strlen($linea) < 4 || $linea[3] === ' ') break;
    }
}

function enviarCorreo(array $r): bool {
    $fp = @fsockopen(getenv('SMTP_HOST'), (int) getenv('SMTP_PORT'), $errno, $errstr, 3);
    if (!$fp) return false;
    stream_set_timeout($fp, 3);
    leerSmtp($fp);
    foreach ([
        'EHLO backend',
        'MAIL FROM:<sistema@reservaciones.local>',
        'RCPT TO:<solicitante@reservaciones.local>',
        'DATA'
    ] as $paso) {
        fwrite($fp, $paso . "\r\n");
        leerSmtp($fp);
    }
    $cuerpo = "Se creo una reservacion:\r\n"
            . "Espacio: {$r['espacio']}\r\n"
            . "Solicitante: {$r['solicitante']}\r\n"
            . "Fecha: {$r['fecha']}\r\n"
            . "Horario: {$r['hora_inicio']} a {$r['hora_fin']}\r\n";
    fwrite($fp, "From: sistema@reservaciones.local\r\n"
              . "To: solicitante@reservaciones.local\r\n"
              . "Subject: Nueva reservacion: {$r['espacio']}\r\n\r\n"
              . $cuerpo . "\r\n.\r\n");
    leerSmtp($fp);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

// Validación avanzada (mejora individual)
function validar(array $d, PDO $db, int $idActual = 0): array {
    $espacio = trim($d['espacio'] ?? '');
    $solicitante = trim($d['solicitante'] ?? '');
    $fecha = $d['fecha'] ?? '';
    $ini = substr($d['hora_inicio'] ?? '', 0, 5);
    $fin = substr($d['hora_fin'] ?? '', 0, 5);
    $estado = $d['estado'] ?? 'pendiente';

    if ($espacio === '' || $solicitante === '')
        responder(422, ['error' => 'El espacio y el solicitante son obligatorios']);
    if (mb_strlen($espacio) < 3 || mb_strlen($espacio) > 100)
        responder(422, ['error' => 'El espacio debe tener entre 3 y 100 caracteres']);
    if (mb_strlen($solicitante) < 3 || mb_strlen($solicitante) > 100)
        responder(422, ['error' => 'El solicitante debe tener entre 3 y 100 caracteres']);
    if (!preg_match('/^[\p{L}\p{N} .,#\-]+$/u', $espacio) || !preg_match('/^[\p{L}\p{N} .,#\-]+$/u', $solicitante))
        responder(422, ['error' => 'Solo se permiten letras, numeros, espacios y los signos . , # -']);
    
    $f = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$f || $f->format('Y-m-d') !== $fecha)
        responder(422, ['error' => 'La fecha es invalida (formato AAAA-MM-DD)']);
    if (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin))
        responder(422, ['error' => 'Las horas son invalidas (formato HH:MM)']);
    if ($fin <= $ini)
        responder(422, ['error' => 'La hora de fin debe ser mayor a la de inicio']);
    if (!in_array($estado, ['pendiente', 'confirmada', 'cancelada'], true))
        responder(422, ['error' => 'Estado invalido']);

    // Validar duplicados / traslape de horarios
    if ($estado !== 'cancelada') {
        $st = $db->prepare("SELECT id FROM reservaciones
            WHERE espacio = ? AND fecha = ? AND estado <> 'cancelada'
              AND id <> ? AND hora_inicio < ? AND hora_fin > ?");
        $st->execute([$espacio, $fecha, $idActual, $fin . ':00', $ini . ':00']);
        if ($st->fetch())
            responder(409, ['error' => 'Ese espacio ya esta reservado en ese horario']);
    }

    return [
        'espacio' => $espacio, 'solicitante' => $solicitante, 'fecha' => $fecha,
        'hora_inicio' => $ini . ':00', 'hora_fin' => $fin . ':00', 'estado' => $estado
    ];
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$ruta    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$partes  = explode('/', $ruta);
$recurso = $partes[0] ?? '';
$id      = isset($partes[1]) ? (int) $partes[1] : null;
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    if ($recurso === 'health' && $metodo === 'GET') {
        conectarDB()->query('SELECT 1');
        conectarRedis()->ping();
        responder(200, ['estado' => 'ok', 'db' => 'ok', 'redis' => 'ok']);
    }

    if ($recurso !== 'reservaciones') {
        responder(404, ['error' => 'Ruta no encontrada']);
    }

    $db = conectarDB();
    $redis = conectarRedis();

    // LISTAR (GET): caché 30s + contador de consultas
    if ($metodo === 'GET' && $id === null) {
        $redis->incr('contador_consultas');
        $cache = $redis->get('reservaciones:lista');
        if ($cache !== false) {
            header('X-Cache: HIT');
            echo $cache;
            exit;
        }
        $filas = $db->query('SELECT * FROM reservaciones ORDER BY fecha, hora_inicio')->fetchAll();
        $json = json_encode($filas, JSON_UNESCAPED_UNICODE);
        $redis->setex('reservaciones:lista', 30, $json);
        header('X-Cache: MISS');
        echo $json;
        exit;
    }

    // CREAR (POST)
    if ($metodo === 'POST' && $id === null) {
        $r = validar($entrada, $db);
        $st = $db->prepare('INSERT INTO reservaciones (espacio, solicitante, fecha, hora_inicio, hora_fin, estado) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$r['espacio'], $r['solicitante'], $r['fecha'], $r['hora_inicio'], $r['hora_fin'], $r['estado']]);
        $redis->del('reservaciones:lista');
        enviarCorreo($r);
        responder(201, ['id' => (int) $db->lastInsertId()] + $r);
    }

    // ACTUALIZAR (PUT)
    if ($metodo === 'PUT' && $id) {
        if (!existe($db, $id)) responder(404, ['error' => 'Reservacion no encontrada']);
        $r = validar($entrada, $db, $id);
        $st = $db->prepare('UPDATE reservaciones SET espacio=?, solicitante=?, fecha=?, hora_inicio=?, hora_fin=?, estado=? WHERE id=?');
        $st->execute([$r['espacio'], $r['solicitante'], $r['fecha'], $r['hora_inicio'], $r['hora_fin'], $r['estado'], $id]);
        $redis->del('reservaciones:lista');
        responder(200, ['mensaje' => 'Actualizada']);
    }

    // ELIMINAR (DELETE)
    if ($metodo === 'DELETE' && $id) {
        if (!existe($db, $id)) responder(404, ['error' => 'Reservacion no encontrada']);
        $db->prepare('DELETE FROM reservaciones WHERE id = ?')->execute([$id]);
        $redis->del('reservaciones:lista');
        responder(200, ['mensaje' => 'Eliminada']);
    }

    responder(405, ['error' => 'Metodo no permitido']);
} catch (Throwable $e) {
    responder(503, ['error' => 'Servicio no disponible', 'detalle' => $e->getMessage()]);
}