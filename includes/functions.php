<?php
/**
 * Funciones helper reutilizables
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Respuesta JSON estandarizada para los endpoints del API
 *
 * Limpia cualquier output buffereado (warnings, notices) ANTES de enviar
 * el JSON, para evitar que un warning rompa la respuesta del API.
 */
function json_response($data, int $code = 200): void {
    // Descartar cualquier output accidental antes de mandar el JSON
    while (ob_get_level() > 0) {
        $leftover = ob_get_clean();
        // Si había algo, lo registramos para debug
        if ($leftover !== '' && $leftover !== false && function_exists('jr_log_error')) {
            jr_log_error([
                'tipo'    => 'OutputLeak',
                'mensaje' => 'Output inesperado descartado del API: ' . substr($leftover, 0, 500),
                'archivo' => __FILE__,
                'linea'   => __LINE__,
            ]);
        }
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obtiene los datos JSON del request (para POST/PUT)
 */
function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Formatea como moneda mexicana
 */
function money($n): string {
    return '$' . number_format((float)$n, 2);
}

/**
 * Sanitiza salida HTML
 */
function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica que el método del request sea el esperado
 */
function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        json_response(['error' => 'Método no permitido'], 405);
    }
}

/**
 * Recalcula totales de una orden y los guarda.
 * Subtotal incluye TANTO el precio base como los extras de modificadores.
 *   subtotal = SUM((precio + precio_extra) * cantidad)
 *   iva      = subtotal * TAX_RATE
 *   total    = subtotal + iva
 */
function recalc_order_totals(int $orden_id): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM((precio + COALESCE(precio_extra,0))
               * GREATEST(cantidad - COALESCE(cantidad_cancelada,0), 0)), 0) AS sub
        FROM orden_items
        WHERE orden_id = ?
    ");
    $stmt->execute([$orden_id]);
    $subtotal = (float)($stmt->fetch()['sub'] ?? 0);
    $iva = $subtotal * TAX_RATE;
    $total = $subtotal + $iva;

    $upd = $pdo->prepare("UPDATE ordenes SET subtotal=?, iva=?, total=? WHERE id=?");
    $upd->execute([$subtotal, $iva, $total, $orden_id]);

    return compact('subtotal', 'iva', 'total');
}

/**
 * Bloquea agregar productos a una mesa que atiende OTRO mesero.
 * Solo el mesero dueño de la cuenta o un admin pueden. No aplica a
 * pedidos que no son de mesa (llevar/domicilio/mostrador/web).
 */
function assert_mesa_no_ajena(int $orden_id, array $user): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT usuario_id, tipo FROM ordenes WHERE id=?");
    $st->execute([$orden_id]);
    $o = $st->fetch();
    if (!$o) return;
    if (($o['tipo'] ?? 'local') !== 'local') return;  // solo mesas
    if (($user['rol'] ?? '') === 'admin') return;      // admin puede todo
    $dueno = (int)($o['usuario_id'] ?? 0);
    if ($dueno && $dueno !== (int)($user['id'] ?? 0)) {
        // Solo bloquear si la cuenta REALMENTE está siendo atendida, es decir,
        // si ya tiene productos activos. Una orden "fantasma" (otro mesero abrió
        // la mesa para ver el menú y no pidió nada → la mesa quedó libre) NO debe
        // bloquear: se reasigna al mesero actual y se permite.
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM orden_items
                              WHERE orden_id=? AND COALESCE(cantidad_cancelada,0) < cantidad");
        $cnt->execute([$orden_id]);
        if ((int)$cnt->fetchColumn() === 0) {
            $pdo->prepare("UPDATE ordenes SET usuario_id=? WHERE id=?")
                ->execute([(int)($user['id'] ?? 0), $orden_id]);
            return;
        }
        json_response([
            'error' => 'Esta mesa la atiende otro mesero. Solo un administrador puede agregarle productos.',
            'mesa_ajena' => true,
        ], 403);
    }
}

/**
 * Obtiene la orden abierta de una mesa, la crea si no existe
 */
function get_or_create_order(int $mesa_id, ?int $usuario_id = null): int {
    $pdo = db();
    // Si hay varias órdenes abiertas en la mesa (p.ej. una "fantasma" vacía que
    // alguien abrió y una real movida desde otra mesa), preferir la que TIENE
    // productos, para no mostrar una cuenta vacía.
    $stmt = $pdo->prepare("
        SELECT o.id
        FROM ordenes o
        WHERE o.mesa_id=? AND o.estado='abierta'
        ORDER BY (SELECT COUNT(*) FROM orden_items oi WHERE oi.orden_id=o.id) DESC, o.id DESC
        LIMIT 1");
    $stmt->execute([$mesa_id]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    $ins = $pdo->prepare("INSERT INTO ordenes (mesa_id, usuario_id) VALUES (?,?)");
    $ins->execute([$mesa_id, $usuario_id]);
    // La mesa NO se marca ocupada aquí: queda libre hasta que se cargue el
    // primer producto (ver add_item / add_combo). Así abrir una mesa para ver
    // el menú no la "ocupa" si al final no piden nada.

    return (int)$pdo->lastInsertId();
}

/**
 * Etiqueta legible de una mesa para las comandas de cocina.
 * Prefiere el nombre personalizado ("Terraza 1", "Barra"); si no hay, usa el
 * número → "MESA 05". Devuelve '' si la orden no es de mesa (llevar/domicilio).
 */
function mesa_label(array $mesa): string {
    $nombre = trim((string)($mesa['nombre'] ?? ''));
    if ($nombre !== '') return $nombre;
    $numero = trim((string)($mesa['numero'] ?? ''));
    if ($numero !== '') return 'MESA ' . str_pad($numero, 2, '0', STR_PAD_LEFT);
    return '';
}

/**
 * Marca como 'ocupada' la mesa de una orden local cuando se le carga producto.
 * Solo cambia mesas que estén 'libre' (no pisa 'por_cobrar').
 */
function marcar_mesa_ocupada(int $orden_id): void {
    db()->prepare("
        UPDATE mesas SET estado='ocupada'
        WHERE id = (SELECT mesa_id FROM ordenes WHERE id=?) AND estado='libre'
    ")->execute([$orden_id]);
}

/**
 * Vista parcial: incluye un archivo del proyecto desde la raíz
 */
function partial(string $path): void {
    include __DIR__ . '/../' . ltrim($path, '/');
}

/**
 * Lee un valor de la tabla configuracion (con cache de proceso).
 */
function cfg(string $clave, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { $cache = db()->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) {}
    }
    return $cache[$clave] ?? $default;
}

/**
 * Devuelve la jornada abierta (apertura del negocio) o null si no hay ninguna.
 * La abre admin o encargado; mientras no exista, nadie opera y la web está
 * cerrada al público.
 */
function jornada_abierta(): ?array {
    try {
        $stmt = db()->query("
            SELECT j.*, u.nombre AS abierta_por_nombre
            FROM jornadas j LEFT JOIN usuarios u ON u.id = j.abierta_por
            WHERE j.estado='abierta'
            ORDER BY j.abierta_at DESC LIMIT 1
        ");
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Corta la petición del API si el negocio NO está abierto.
 * Se usa en las acciones que "inician operación" (abrir turno, crear pedidos…).
 */
function require_jornada_abierta(): void {
    if (jornada_abierta()) return;
    json_response([
        'error' => '🔒 El negocio aún no ha sido abierto. Un administrador o encargado debe abrirlo antes de iniciar operaciones.',
        'negocio_cerrado' => true,
    ], 423);
}

/**
 * Determina si el restaurante está abierto AHORA para el público (menú/pedidos).
 *
 * Reglas (en orden):
 *  1. Debe haber una JORNADA ABIERTA (el encargado abrió el negocio). Es lo que
 *     dispara la apertura de la web, aunque sea antes del horario habitual.
 *  2. Switch manual web_pedidos_activo = 0 → cerrado (cierre manual inmediato).
 *  3. Red de seguridad: si ya pasó la hora de cierre del horario del día, la web
 *     se apaga sola aunque olvidaran cerrar el negocio.
 *
 * @return ['abierto'=>bool, 'razon'=>'sin_apertura'|'manual'|'hora'|null, 'msg'=>string]
 */
function restaurante_abierto(): array {
    // 1) El negocio debe estar abierto por admin/encargado
    if (!jornada_abierta()) {
        return ['abierto' => false, 'razon' => 'sin_apertura',
                'msg' => 'Todavía no abrimos. Vuelve en un momento.'];
    }

    // 2) Cierre manual inmediato
    if (!(int)cfg('web_pedidos_activo', 1)) {
        return ['abierto' => false, 'razon' => 'manual',
                'msg' => 'Cerramos temporalmente. Vuelve más tarde.'];
    }

    // 3) Red de seguridad: no recibir pedidos después de la hora de cierre
    $dia = (int)date('N'); // 1=Lun .. 7=Dom
    $ahora = date('H:i:s');
    try {
        $stmt = db()->prepare("SELECT * FROM horarios_atencion WHERE dia=?");
        $stmt->execute([$dia]);
        $h = $stmt->fetch();
    } catch (Throwable $e) { $h = null; }

    // Si el día está marcado como cerrado en el horario, manda la apertura manual
    if ($h && $h['abierto']) {
        $ini = $h['hora_inicio']; $fin = $h['hora_fin'];
        $pasoElCierre = ($ini <= $fin)
            ? ($ahora > $fin)                     // horario normal
            : ($ahora > $fin && $ahora < $ini);   // cruza medianoche
        if ($pasoElCierre) {
            return ['abierto' => false, 'razon' => 'hora',
                    'msg' => 'Hoy atendimos de ' . substr($ini,0,5) . ' a ' . substr($fin,0,5) . '.'];
        }
    }

    return ['abierto' => true, 'razon' => null, 'msg' => ''];
}

/**
 * Devuelve el horario semanal legible (para mostrar al cliente).
 */
function horario_semanal(): array {
    $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    $out = [];
    try {
        $rows = db()->query("SELECT * FROM horarios_atencion ORDER BY dia")->fetchAll();
        foreach ($rows as $r) {
            $out[] = [
                'dia' => $dias[$r['dia']] ?? $r['dia'],
                'abierto' => (int)$r['abierto'],
                'horario' => $r['abierto'] ? substr($r['hora_inicio'],0,5).' a '.substr($r['hora_fin'],0,5) : 'Cerrado',
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Distancia en km entre dos coordenadas (fórmula de Haversine).
 */
function distancia_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371; // radio de la Tierra en km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($R * $c, 2);
}

/**
 * Cotiza el costo de envío según la distancia desde el restaurante.
 * Devuelve [ 'distancia_km', 'costo', 'por_cotizar' (bool), 'mensaje' ].
 *
 * Tarifas (configurables):
 *   <= 1 km           → envio_tarifa_1km  (def 30)
 *   > 1 y <= 3 km     → envio_tarifa_3km  (def 40)
 *   > 3 y <= 5 km     → envio_tarifa_5km  (def 50)
 *   > envio_max_km    → por_cotizar = true (el restaurante define el precio)
 */
function cotizar_envio(?float $lat, ?float $lng): array {
    $rLat = (float)cfg('restaurante_lat', 0);
    $rLng = (float)cfg('restaurante_lng', 0);

    if ($lat === null || $lng === null || !$rLat || !$rLng) {
        return ['distancia_km' => null, 'costo' => 0, 'por_cotizar' => true,
                'mensaje' => 'Sin ubicación; el restaurante definirá el costo de envío.'];
    }

    $dist = distancia_km($rLat, $rLng, $lat, $lng);
    $maxKm = (float)cfg('envio_max_km', 5);

    if ($dist <= 1) {
        $costo = (float)cfg('envio_tarifa_1km', 30);
    } elseif ($dist <= 3) {
        $costo = (float)cfg('envio_tarifa_3km', 40);
    } elseif ($dist <= $maxKm) {
        $costo = (float)cfg('envio_tarifa_5km', 50);
    } else {
        return ['distancia_km' => $dist, 'costo' => 0, 'por_cotizar' => true,
                'mensaje' => "Estás a {$dist} km. El restaurante confirmará el costo de envío al revisar tu pedido."];
    }
    return ['distancia_km' => $dist, 'costo' => $costo, 'por_cotizar' => false,
            'mensaje' => "Envío: $" . number_format($costo, 2) . " ({$dist} km)"];
}

/**
 * Despacha los items pendientes de una orden a cocina, creando las comandas
 * separadas por estación (Cocina 1 = todo; Cocina 2 = solo cocina_2=1).
 * Reutilizable desde send_kitchen y desde la aceptación de pedidos web.
 *
 * @return array Lista de comandas creadas (id, cocina, items...)
 */
function dispatch_orden_cocina(int $orden_id, ?int $userId): array {
    $pdo = db();
    $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
    $orden->execute([$orden_id]);
    $o = $orden->fetch();
    if (!$o) return [];

    // Mesa real o pseudo-mesa con datos del cliente (para llevar/domicilio/web)
    if ($o['mesa_id']) {
        $mStmt = $pdo->prepare("SELECT * FROM mesas WHERE id=?");
        $mStmt->execute([$o['mesa_id']]);
        $mesa = $mStmt->fetch() ?: [];
    } else {
        $tipoLabel = $o['tipo'] === 'domicilio' ? '🛵 DOMICILIO' : '📦 LLEVAR';
        $mesa = [
            'id' => 0, 'numero' => '', 'capacidad' => '', 'zona' => $tipoLabel,
            'descripcion' => trim(($o['cliente_nombre'] ?? '') . ' · ' . ($o['cliente_telefono'] ?? ''), ' ·'),
        ];
    }

    $items = $pdo->prepare("
        SELECT oi.*, COALESCE(p.cocina_2, 0) AS cocina_2
        FROM orden_items oi
        JOIN productos p ON p.id = oi.producto_id
        WHERE oi.orden_id=? AND oi.enviado=0 AND COALESCE(oi.cantidad_cancelada,0) < oi.cantidad
        ORDER BY oi.comensal, oi.id
    ");
    $items->execute([$orden_id]);
    $nuevos = $items->fetchAll();
    if (!$nuevos) return [];

    $itemsC1 = array_values(array_filter($nuevos, fn($it) => $it['cocina_2'] == 0));
    $itemsC2 = array_values(array_filter($nuevos, fn($it) => $it['cocina_2'] == 1));

    // Info de la orden para la comanda-TICKET de para llevar / domicilio.
    $ordenInfo = null;
    if (in_array($o['tipo'], ['llevar','domicilio'], true)) {
        $ordenInfo = [
            'cliente_nombre'      => $o['cliente_nombre'] ?? null,
            'cliente_telefono'    => $o['cliente_telefono'] ?? null,
            'cliente_direccion'   => $o['cliente_direccion'] ?? null,
            'cliente_referencias' => $o['cliente_referencias'] ?? null,
            'hora_pickup' => $o['hora_pickup'] ?? null,
            'subtotal'    => (float)$o['subtotal'],
            'total'       => (float)$o['total'],
            'costo_envio' => (float)($o['costo_envio'] ?? 0),
            'paga_con'    => (float)($o['paga_con'] ?? 0),
            'metodo_pago' => $o['web_metodo_pago'] ?? null,
        ];
    }

    // Items ya enviados en rondas anteriores (para "YA EN PREPARACION").
    $itemsPrevios = [];
    if ($ordenInfo !== null) {
        $prev = $pdo->prepare("SELECT oi.* FROM orden_items oi
                               WHERE oi.orden_id=? AND oi.enviado=1 AND COALESCE(oi.cantidad_cancelada,0) < oi.cantidad
                               ORDER BY oi.comensal, oi.id");
        $prev->execute([$orden_id]);
        $itemsPrevios = $prev->fetchAll();
    }

    $rondaStmt = $pdo->prepare("SELECT COALESCE(MAX(ronda),0)+1 FROM comandas WHERE orden_id=?");
    $rondaStmt->execute([$orden_id]);
    $ronda = (int)$rondaStmt->fetchColumn();

    $insComanda = $pdo->prepare("INSERT INTO comandas (orden_id, mesa_id, usuario_id, cocina, ronda) VALUES (?,?,?,?,?)");
    $updItem    = $pdo->prepare("UPDATE orden_items SET enviado=1, comanda_id=? WHERE id=?");
    $comandas = [];
    $created  = date('H:i');

    // Cocina A (1) SIEMPRE recibe una comanda con TODOS los items (control).
    $insComanda->execute([$orden_id, $o['mesa_id'], $userId, 1, $ronda]);
    $c1 = (int)$pdo->lastInsertId();
    foreach ($nuevos as $it) $updItem->execute([$c1, $it['id']]);
    $comandas[] = ['id'=>$c1, 'orden_id'=>$orden_id, 'cocina'=>1, 'ronda'=>$ronda, 'mesa'=>$mesa, 'items'=>$nuevos, 'created'=>$created,
                   'tipo'=>$o['tipo'], 'orden'=>$ordenInfo, 'completa'=>true, 'items_previos'=>$itemsPrevios];

    // Cocina B (2) recibe SOLO sus items, si los hay.
    if ($itemsC2) {
        $insComanda->execute([$orden_id, $o['mesa_id'], $userId, 2, $ronda]);
        $c2 = (int)$pdo->lastInsertId();
        $comandas[] = ['id'=>$c2, 'orden_id'=>$orden_id, 'cocina'=>2, 'ronda'=>$ronda, 'mesa'=>$mesa, 'items'=>$itemsC2, 'created'=>$created,
                       'tipo'=>$o['tipo'], 'orden'=>null, 'completa'=>false];
    }

    @touch(__DIR__ . '/../config/.sse-trigger');
    return $comandas;
}
