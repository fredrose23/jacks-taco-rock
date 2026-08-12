<?php
/**
 * Impresión térmica directa por red (ESC/POS sobre TCP puerto 9100).
 *
 * Las impresoras (POS891/892) hablan ESC/POS con codepage WCP1252 y papel de
 * 80mm (48 columnas en Font A). Enviamos los comandos crudos al socket; el
 * teléfono/tablet solo dispara la acción, el SERVIDOR imprime.
 *
 * Todo va envuelto en try/catch: si una impresora está apagada (p.ej. Cocina 2
 * aún sin conectar), NUNCA debe romper el flujo de la orden.
 */

const ESCPOS_COLS = 48; // columnas Font A en papel de 80mm

/* ─────────── Comandos base ─────────── */
const ESC = "\x1b";
const GS  = "\x1d";

function esc_init(): string    { return ESC . "@"; }
function esc_center(): string  { return ESC . "a\x01"; }
function esc_left(): string    { return ESC . "a\x00"; }
function esc_bold(bool $on): string   { return ESC . "E" . ($on ? "\x01" : "\x00"); }
/** Tamaño: $w y $h de 0 (normal) a 7 (x8). 1 = doble. */
function esc_size(int $w = 0, int $h = 0): string {
    $n = (($w & 0x07) << 4) | ($h & 0x07);
    return GS . "!" . chr($n);
}
function esc_feedcut(): string { return "\n\n\n\n" . GS . "V\x00"; }
/** Avanza el papel N puntos (203 dpi ≈ 8 puntos/mm). ESC J acepta 0–255. */
function esc_feed_dots(int $n): string { return ESC . "J" . chr(max(0, min(255, $n))); }
/** Avanza el papel N milímetros (203 dpi). Encadena ESC J para pasar de 255. */
function esc_feed_mm(int $mm): string {
    $dots = (int)round($mm * 203 / 25.4);
    $out = '';
    while ($dots > 0) { $n = min(255, $dots); $out .= ESC . "J" . chr($n); $dots -= $n; }
    return $out;
}

/** Convierte UTF-8 → CP1252 (mantiene acentos, descarta emojis). */
function esc_t(string $s): string {
    return @iconv('UTF-8', 'CP1252//IGNORE', $s) ?: '';
}

/** Línea con texto a la izquierda y a la derecha, rellenando con espacios. */
function esc_lr(string $left, string $right, int $cols = ESCPOS_COLS): string {
    $l = esc_t($left); $r = esc_t($right);
    $space = $cols - strlen($l) - strlen($r);
    if ($space < 1) { $l = substr($l, 0, max(0, $cols - strlen($r) - 1)); $space = 1; }
    return $l . str_repeat(' ', $space) . $r . "\n";
}

function esc_hr(string $ch = '-', int $cols = ESCPOS_COLS): string {
    return str_repeat($ch, $cols) . "\n";
}

/** Envuelve texto largo a $cols columnas con sangría opcional. */
function esc_wrap(string $s, int $cols = ESCPOS_COLS, string $indent = ''): string {
    $s = esc_t($s);
    $out = '';
    foreach (explode("\n", wordwrap($s, $cols - strlen($indent), "\n", true)) as $line) {
        $out .= $indent . $line . "\n";
    }
    return $out;
}

/**
 * Envía datos crudos a "ip" o "ip:puerto". Devuelve true si se envió.
 *
 * Estas impresoras aceptan UNA conexión a la vez y a veces rechazan/cortan
 * bajo tráfico, así que reintentamos varias veces con una pausa corta. Esto
 * sube la fiabilidad de ~80-100% por intento a ~99.9% efectivo.
 * Silencioso ante fallos (log en bitácora) para no romper la orden.
 */
function escpos_send(?string $ipPort, string $data, float $timeout = 1.5, int $reintentos = 3): bool {
    $ipPort = trim((string)$ipPort);
    if ($ipPort === '') return false;
    if (strpos($ipPort, ':') === false) $ipPort .= ':9100';
    [$host, $port] = explode(':', $ipPort, 2);
    $port = (int)$port ?: 9100;

    $ultimoError = '';
    for ($intento = 1; $intento <= max(1, $reintentos); $intento++) {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if ($fp) {
            stream_set_timeout($fp, (int)$timeout);
            $ok = @fwrite($fp, $data);
            @fclose($fp);
            if ($ok !== false) return true;
            $ultimoError = 'fallo al escribir';
        } else {
            $ultimoError = $errstr ?: "no conecta";
        }
        // Pausa breve antes de reintentar (la impresora puede estar ocupada)
        if ($intento < $reintentos) usleep(300000); // 0.3s
    }
    if (function_exists('log_action')) {
        log_action('print_error', 'impresora', null, "Sin impresión en {$host}:{$port} tras {$reintentos} intentos — {$ultimoError}");
    }
    return false;
}

/**
 * Convierte un PNG a raster ESC/POS (GS v 0) para imprimir el logo.
 * Devuelve '' si falla (se usa encabezado de texto como respaldo).
 */
function escpos_raster_png(string $path, int $targetW = 384): string {
    if (!function_exists('imagecreatefrompng') || !is_file($path)) return '';
    $img = @imagecreatefrompng($path);
    if (!$img) return '';

    $w0 = imagesx($img); $h0 = imagesy($img);
    // Ancho múltiplo de 8; alto proporcional
    $targetW = max(8, (int)(floor($targetW / 8) * 8));
    $targetH = (int)round($h0 * ($targetW / $w0));
    $canvas = imagecreatetruecolor($targetW, $targetH);
    // Fondo blanco
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $targetW, $targetH, $w0, $h0);
    imagedestroy($img);

    $bytesPerRow = intdiv($targetW, 8);
    $raster = '';
    for ($y = 0; $y < $targetH; $y++) {
        for ($b = 0; $b < $bytesPerRow; $b++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $b * 8 + $bit;
                $rgb = imagecolorat($canvas, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $bl = $rgb & 0xFF;
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $bl;
                if ($lum < 128) $byte |= (0x80 >> $bit); // oscuro = punto negro
            }
            $raster .= chr($byte);
        }
    }
    imagedestroy($canvas);

    $xL = $bytesPerRow & 0xFF; $xH = ($bytesPerRow >> 8) & 0xFF;
    $yL = $targetH & 0xFF;     $yH = ($targetH >> 8) & 0xFF;
    // GS v 0 m xL xH yL yH data
    return GS . "v0\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $raster;
}

/* ─────────── Selección de impresora ─────────── */

/** Letra visible de una cocina: 1 → A, 2 → B. */
function cocina_letra(int $cocina): string {
    return $cocina == 2 ? 'B' : 'A';
}

/** Destino (ip:puerto) de la impresora de una cocina (1 ó 2 → A ó B). */
function printer_dest_cocina(int $cocina): ?string {
    $st = db()->prepare("SELECT destino FROM impresoras
        WHERE activa=1 AND driver='escpos_red' AND nombre LIKE ? AND destino IS NOT NULL AND destino<>'' LIMIT 1");
    $st->execute(["Cocina " . cocina_letra($cocina) . "%"]);
    return $st->fetchColumn() ?: null;
}

/** Destino de la impresora de tickets (caja). */
function printer_dest_ticket(): ?string {
    $st = db()->query("SELECT destino FROM impresoras
        WHERE activa=1 AND driver='escpos_red' AND tipo IN ('ticket','ambos')
          AND destino IS NOT NULL AND destino<>'' ORDER BY (ubicacion='caja') DESC, id LIMIT 1");
    return $st->fetchColumn() ?: null;
}

/* ─────────── Construcción de documentos ─────────── */

/**
 * Título grande del aviso según el tipo de pedido sin mesa.
 * llevar → RECOGER · domicilio → DOMICILIO · mostrador → MOSTRADOR
 */
function togo_titulo(string $tipo): string {
    if ($tipo === 'domicilio') return "** DOMICILIO **";
    if ($tipo === 'mostrador') return "** MOSTRADOR **";
    return "** RECOGER **";
}

/**
 * Comanda-TICKET para PARA LLEVAR / DOMICILIO / MOSTRADOR: como un ticket (con
 * precios, dirección y forma de pago) pero sin logo, para que en cocina/empaque
 * tengan toda la info. $c incluye 'orden' con los datos financieros y del cliente.
 */
function build_comanda_togo_escpos(array $c): string {
    $cocina = (int)$c['cocina'];
    $tipo   = strtolower((string)($c['tipo'] ?? 'llevar'));
    $info   = $c['orden'] ?? [];

    // Encabezado grande = número de ORDEN + letra de cocina (ej. "40-A").
    $folio = !empty($c['orden_id']) ? ($c['orden_id'] . '-' . cocina_letra($cocina)) : ('COCINA ' . cocina_letra($cocina));

    $d  = esc_init();
    $d .= esc_feed_mm(40); // espacio para la pinza
    $d .= esc_center() . esc_bold(true) . esc_size(2, 2);
    $d .= esc_t($folio) . "\n";
    $d .= esc_size(0, 0) . esc_bold(false);
    $d .= esc_bold(true) . esc_size(1, 1) . esc_t(togo_titulo($tipo)) . "\n" . esc_size(0,0) . esc_bold(false);
    $d .= esc_left() . esc_hr('=');

    // Cliente (grande). Mostrador no tiene cliente real ("Mostrador"), se omite.
    $nombre = trim((string)($info['cliente_nombre'] ?? ''));
    if ($tipo === 'mostrador') $nombre = '';
    if ($nombre !== '') {
        // Nombre del cliente en ~6mm (doble alto) para identificarlo de un vistazo.
        $d .= esc_bold(true) . esc_t("CLIENTE:") . "\n";
        $d .= esc_size(1, 1) . esc_t(mb_strtoupper($nombre)) . "\n" . esc_size(0, 0) . esc_bold(false);
    }
    if (!empty($info['cliente_telefono'])) $d .= esc_t("Tel: " . $info['cliente_telefono']) . "\n";
    // Para llevar: hora a la que pasan a recoger (grande).
    if ($tipo === 'llevar' && !empty($info['hora_pickup'])) {
        $hp = @date('g:i A', strtotime((string)$info['hora_pickup'])) ?: substr((string)$info['hora_pickup'], 0, 5);
        $d .= esc_bold(true) . esc_t("RECOGER A LAS:") . "\n";
        $d .= esc_size(1, 1) . esc_t($hp) . "\n" . esc_size(0, 0) . esc_bold(false);
    }
    // Domicilio: a dónde va
    if ($tipo === 'domicilio') {
        if (!empty($info['cliente_direccion'])) {
            $d .= esc_bold(true) . esc_t("A DOMICILIO:") . "\n" . esc_bold(false);
            $d .= esc_wrap((string)$info['cliente_direccion'], ESCPOS_COLS);
        }
        if (!empty($info['cliente_referencias'])) $d .= esc_wrap("Ref: " . $info['cliente_referencias'], ESCPOS_COLS);
    }
    $d .= esc_lr("Orden #" . (!empty($c['orden_id']) ? $c['orden_id'] : $c['id']), (string)($c['created'] ?? date('H:i')));
    $d .= esc_hr('-');

    // Si hay rondas anteriores, esta es una ronda NUEVA: etiquetar lo nuevo.
    $hayPrevios = !empty($c['items_previos']);
    if ($hayPrevios) {
        $d .= esc_bold(true) . esc_size(1, 1) . esc_t(">> NUEVO <<") . "\n" . esc_size(0, 0) . esc_bold(false);
    }

    // Items con MISMO estilo/tamaño que la comanda de cocina B: nombre en ~6mm
    // (doble, proporcional, negrita) y el precio en su propia línea a la derecha,
    // para que las letras del producto queden idénticas a la copia de cocina B
    // sin romper la alineación del precio.
    $primerItem = true;
    foreach (($c['items'] ?? []) as $it) {
        $qty = (int)$it['cantidad'] - (int)($it['cantidad_cancelada'] ?? 0);
        if ($qty <= 0) continue;
        // Línea en blanco ANTES de cada item nuevo (no en el primero): así cada
        // item + sus modificadores quedan como un bloque separado del siguiente.
        if (!$primerItem) $d .= "\n";
        $primerItem = false;
        $lineTot = ((float)$it['precio'] + (float)($it['precio_extra'] ?? 0)) * $qty;
        // Item (negrita) y sus modificadores PEGADOS, sin nada en medio.
        $d .= esc_bold(true) . esc_size(1, 1) . esc_t($qty . "x " . $it['nombre']) . "\n" . esc_size(0, 0) . esc_bold(false);
        if ($cocina === 1 && (int)($it['cocina_2'] ?? 0) === 1) $d .= esc_t("   >> COCINA B") . "\n";
        // Modificadores y notas: ~6mm SIN negrita (el producto va en negrita),
        // para distinguir item vs modificador por la negrita, no por el tamaño.
        if (!empty($it['modificadores_json'])) {
            $mods = json_decode($it['modificadores_json'], true) ?: [];
            foreach ($mods as $m) { $n = is_array($m) ? ($m['nombre'] ?? '') : (string)$m; if ($n !== '') $d .= esc_bold(false) . esc_size(1,1) . esc_t("  + " . $n) . "\n" . esc_size(0,0); }
        }
        if (!empty($it['notas'])) $d .= esc_bold(false) . esc_size(1,1) . esc_t("  * " . $it['notas']) . "\n" . esc_size(0,0);
        // Precio al CIERRE del bloque del item (después la línea en blanco separa).
        $d .= esc_lr('', '$' . number_format($lineTot, 2));
    }

    // Rondas anteriores del MISMO pedido: la cocina ya las hizo / están en
    // preparación. Se listan (más chico, sin negrita) para el control, y todo
    // se cobra en UN solo ticket.
    if ($hayPrevios) {
        $d .= esc_hr('-');
        $d .= esc_bold(true) . esc_t("YA EN PREPARACION:") . "\n" . esc_bold(false);
        foreach ($c['items_previos'] as $it) {
            $qty = (int)$it['cantidad'] - (int)($it['cantidad_cancelada'] ?? 0);
            if ($qty <= 0) continue;
            $lineTot = ((float)$it['precio'] + (float)($it['precio_extra'] ?? 0)) * $qty;
            $d .= esc_lr($qty . "x " . $it['nombre'], '$' . number_format($lineTot, 2));
        }
    }
    $d .= esc_hr('-');

    // Totales
    $envio = (float)($info['costo_envio'] ?? 0);
    $subtotal = (float)($info['subtotal'] ?? 0);
    $total = (float)($info['total'] ?? 0) + $envio; // comida + envío
    if ($subtotal > 0) $d .= esc_lr("Subtotal", '$' . number_format($subtotal, 2));
    if ($envio > 0)    $d .= esc_lr("Envio", '$' . number_format($envio, 2));
    $d .= esc_bold(true) . esc_lr("TOTAL", '$' . number_format($total, 2)) . esc_bold(false);

    // Forma de pago
    $metodo = $info['metodo_pago'] ?? null;
    if ($metodo) {
        $lbl = $metodo === 'transferencia' ? 'TRANSFERENCIA' : ($metodo === 'efectivo' ? 'EFECTIVO' : mb_strtoupper((string)$metodo));
        $d .= esc_hr('-') . esc_bold(true) . esc_t("PAGO: " . $lbl) . "\n" . esc_bold(false);
    }
    $pagaCon = (float)($info['paga_con'] ?? 0);
    if ($pagaCon > 0) {
        $d .= esc_t("Paga con: $" . number_format($pagaCon, 2)) . "\n";
        $d .= esc_bold(true) . esc_t("CAMBIO: $" . number_format(max(0, $pagaCon - $total), 2)) . "\n" . esc_bold(false);
    }
    $mesero = trim((string)($c['mesero'] ?? ''));
    if ($mesero !== '') $d .= esc_t("Atendio: " . $mesero) . "\n";
    $d .= esc_feedcut();
    return $d;
}

/**
 * Comanda de cocina. $c = ['id','cocina','mesa'=>[...],'items'=>[...],'created'].
 */
function build_comanda_escpos(array $c): string {
    $cocina = (int)$c['cocina'];
    $mesa = $c['mesa'] ?? [];
    $numMesa = trim((string)($mesa['numero'] ?? ''));
    $zona = trim((string)($mesa['zona'] ?? ''));
    $desc = trim((string)($mesa['descripcion'] ?? ''));
    // Etiqueta de mesa: nombre personalizado ("Terraza 1") o "MESA 05"
    $etiquetaMesa = function_exists('mesa_label') ? mesa_label($mesa) : '';
    // Pedidos para llevar / domicilio: fuente más grande para que el cocinero
    // no se equivoque al empacar.
    $tipo = strtolower((string)($c['tipo'] ?? 'local'));
    $esToGo = in_array($tipo, ['llevar', 'domicilio', 'mostrador'], true);

    // La copia COMPLETA de para llevar / domicilio se imprime como TICKET
    // (con precios, dirección y forma de pago).
    if ($esToGo && !empty($c['completa']) && !empty($c['orden'])) {
        return build_comanda_togo_escpos($c);
    }

    $d  = esc_init();
    // Espacio en blanco arriba en AMBAS cocinas (~4 cm) para colgar la comanda
    // con la pinza sin tapar el contenido.
    $d .= esc_feed_mm(40);

    // Encabezado grande = número de ORDEN + letra de cocina (ej. "40-A").
    $folio = !empty($c['orden_id']) ? ($c['orden_id'] . '-' . cocina_letra($cocina)) : ('COCINA ' . cocina_letra($cocina));
    $d .= esc_center() . esc_bold(true) . esc_size(2, 2);
    $d .= esc_t($folio) . "\n";
    $d .= esc_size(0, 0) . esc_bold(false);
    // RONDA / tanda: distingue cada envío de una misma mesa (aunque pidan lo
    // mismo). Solo aplica a mesas (los para llevar/domicilio se piden una vez).
    if (!$esToGo && !empty($c['ronda'])) {
        $d .= esc_center() . esc_bold(true) . esc_size(1, 1);
        $d .= esc_t("RONDA " . (int)$c['ronda']) . "\n";
        $d .= esc_size(0, 0) . esc_bold(false);
    }
    // Aviso grande de PARA LLEVAR / DOMICILIO / MOSTRADOR
    if ($esToGo) {
        $d .= esc_center() . esc_bold(true) . esc_size(1, 1);
        $d .= esc_t(togo_titulo($tipo)) . "\n";
        $d .= esc_size(0, 0) . esc_bold(false);
    }

    // Número de MESA en GRANDE (aparte del número de orden), centrado, para que
    // cocina identifique la mesa de un vistazo.
    if ($etiquetaMesa !== '') {
        $d .= esc_center() . esc_bold(true) . esc_size(2, 2);
        $d .= esc_t(mb_strtoupper($etiquetaMesa)) . "\n";
        $d .= esc_size(0, 0) . esc_bold(false);
    } elseif (!$esToGo && $zona !== '') {
        // En comandas para llevar/domicilio/mostrador NO repetimos el tipo aquí
        // (ya sale arriba como "** RECOGER **"/"** DOMICILIO **"), para no duplicar.
        $d .= esc_center() . esc_bold(true) . esc_size(1, 1) . esc_t(mb_strtoupper($zona)) . "\n" . esc_size(0,0) . esc_bold(false);
    }
    $d .= esc_left();
    if ($desc !== '') $d .= esc_wrap($desc);
    $d .= esc_lr("Orden #" . (!empty($c['orden_id']) ? $c['orden_id'] : $c['id']), (string)($c['created'] ?? date('H:i')));
    $d .= esc_hr('=');

    // Agrupar por comensal
    $items = $c['items'] ?? [];
    $porComensal = [];
    foreach ($items as $it) { $porComensal[(int)($it['comensal'] ?? 1)][] = $it; }
    $multi = count($porComensal) > 1;

    $primerItem = true;
    foreach ($porComensal as $com => $its) {
        if ($multi) $d .= esc_bold(true) . esc_t("— Comensal $com —") . "\n" . esc_bold(false);
        foreach ($its as $it) {
            $qty = (int)$it['cantidad'] - (int)($it['cantidad_cancelada'] ?? 0);
            if ($qty <= 0) continue;
            // Línea en blanco ANTES de cada item nuevo (no en el primero): agrupa
            // cada item con sus modificadores y lo separa del siguiente.
            if (!$primerItem) $d .= "\n";
            $primerItem = false;
            // Producto en ~6mm (doble) — legible sin ocupar tanto papel.
            $d .= esc_bold(true) . esc_size(1, 1);
            $d .= esc_t($qty . "x " . $it['nombre']) . "\n";
            $d .= esc_size(0, 0) . esc_bold(false);
            // Item marcado PARA LLEVAR dentro de una cuenta de mesa: la cocina
            // debe empacarlo aunque el resto sea para comer aquí.
            if ((int)($it['para_llevar'] ?? 0) === 1) {
                $d .= esc_bold(true) . esc_size(1, 1) . esc_t(">> RECOGER <<") . "\n" . esc_size(0,0) . esc_bold(false);
            }
            // En la comanda de Cocina A, marcar los items que en realidad
            // prepara Cocina B (para que Cocina A sepa que ese NO lo hace).
            if ($cocina === 1 && (int)($it['cocina_2'] ?? 0) === 1) {
                $d .= esc_bold(true) . esc_t("  >>> COCINA B <<<") . "\n" . esc_bold(false);
            }
            // Modificadores y notas en ~6mm PERO SIN negrita: mismo tamaño que el
            // producto (que va en negrita), para distinguir item vs modificador
            // por la negrita, no por el tamaño.
            if (!empty($it['modificadores_json'])) {
                $mods = json_decode($it['modificadores_json'], true) ?: [];
                foreach ($mods as $m) {
                    $nombre = is_array($m) ? ($m['nombre'] ?? '') : (string)$m;
                    if ($nombre !== '') $d .= esc_bold(false) . esc_size(1,1) . esc_t("  + " . $nombre) . "\n" . esc_size(0,0);
                }
            }
            if (!empty($it['notas'])) {
                $d .= esc_bold(false) . esc_size(1,1) . esc_t("  * " . $it['notas']) . "\n" . esc_size(0,0);
            }
        }
    }
    $d .= esc_hr('-');
    $mesero = trim((string)($c['mesero'] ?? ''));
    if ($mesero !== '') $d .= esc_t("Mesero: " . $mesero) . "\n";
    $d .= esc_feedcut();
    return $d;
}

/**
 * Ticket de venta (caja). $t = datos del ticket del handler pay.
 */
function build_ticket_escpos(array $t): string {
    $nombre   = cfg('negocio_nombre',   defined('APP_NAME') ? APP_NAME : 'JACKS ROCK');
    $eslogan  = cfg('negocio_eslogan',  '');
    $direccion= cfg('negocio_direccion','');
    $horario  = cfg('negocio_horario',  '');
    $telefono = cfg('negocio_telefono', '');
    $pie      = cfg('ticket_pie',       '¡GRACIAS POR SU COMPRA!');

    $d  = esc_init();
    $d .= esc_center();

    // Logo (raster) con respaldo a nombre grande
    $logoPath = __DIR__ . '/../assets/img/logo-ticket.png';
    $raster = escpos_raster_png($logoPath, 384);
    if ($raster !== '') {
        $d .= $raster . "\n";
    }
    $d .= esc_bold(true) . esc_size(1, 1) . esc_t($nombre) . "\n" . esc_size(0, 0) . esc_bold(false);
    if ($eslogan)   $d .= esc_wrap($eslogan);
    if ($direccion) $d .= esc_wrap($direccion);
    if ($horario)   $d .= esc_wrap($horario);
    if ($telefono)  $d .= esc_t("Tel: " . $telefono) . "\n";

    $d .= esc_left() . esc_hr('=');
    $d .= esc_lr("Ticket:", "V-" . str_pad((string)$t['orden_id'], 4, '0', STR_PAD_LEFT));
    $d .= esc_lr("Fecha:", (string)($t['fecha'] ?? date('Y-m-d H:i')));
    if (!empty($t['mesa_etiqueta'])) {
        $d .= esc_lr("Mesa:", (string)$t['mesa_etiqueta']);
    } elseif (!empty($t['mesa_numero'])) {
        $d .= esc_lr("Mesa:", str_pad((string)$t['mesa_numero'], 2, '0', STR_PAD_LEFT));
    }
    // Nombre del cliente GRANDE (para identificar rápido los pedidos para
    // llevar / domicilio). Las mesas no traen nombre, así que no se afectan.
    if (!empty($t['cliente_nombre'])) {
        $d .= esc_bold(true) . esc_t("CLIENTE:") . "\n";
        $d .= esc_size(1, 1) . esc_t(mb_strtoupper((string)$t['cliente_nombre'])) . "\n";
        $d .= esc_size(0, 0) . esc_bold(false);
    }
    $d .= esc_hr('-');

    // Items
    $items = $t['items'] ?? [];
    foreach ($items as $it) {
        $cancQty = (int)($it['cantidad_cancelada'] ?? 0);
        $qty = (int)$it['cantidad'] - $cancQty;
        if ($qty <= 0) continue;
        $extra = (float)($it['precio_extra'] ?? 0);
        $lineTot = ((float)$it['precio'] + $extra) * $qty;
        $d .= esc_lr($qty . "x " . $it['nombre'], '$' . number_format($lineTot, 2));
        if ($extra > 0) $d .= esc_wrap("+ extras $" . number_format($extra, 2) . " c/u", ESCPOS_COLS, '   ');
        if (!empty($it['notas'])) $d .= esc_wrap("-> " . $it['notas'], ESCPOS_COLS, '   ');
    }
    $d .= esc_hr('-');

    if ((float)($t['iva'] ?? 0) > 0.001) {
        $d .= esc_lr("Subtotal", '$' . number_format((float)$t['subtotal'], 2));
        $d .= esc_lr("IVA", '$' . number_format((float)$t['iva'], 2));
    }
    if ((float)($t['descuento'] ?? 0) > 0) {
        $lbl = "Descuento" . (!empty($t['descuento_motivo']) ? " (" . $t['descuento_motivo'] . ")" : '');
        $d .= esc_lr($lbl, '-$' . number_format((float)$t['descuento'], 2));
    }
    if ((float)($t['costo_envio'] ?? 0) > 0) $d .= esc_lr("Envio", '$' . number_format((float)$t['costo_envio'], 2));
    if ((float)($t['propina'] ?? 0) > 0)     $d .= esc_lr("Propina", '$' . number_format((float)$t['propina'], 2));

    $d .= esc_bold(true) . esc_size(0, 1);
    $d .= esc_lr("TOTAL", '$' . number_format((float)$t['total'], 2), ESCPOS_COLS);
    $d .= esc_size(0, 0) . esc_bold(false);

    // Pagos
    if (!empty($t['pagos'])) {
        $d .= esc_hr('-');
        static $metodoNombres = null;
        if ($metodoNombres === null) {
            $metodoNombres = db()->query("SELECT codigo, nombre FROM metodos_pago")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        foreach ($t['pagos'] as $p) {
            $nom = $metodoNombres[$p['metodo'] ?? ''] ?? ($p['metodo'] ?? 'Pago');
            $d .= esc_lr($nom, '$' . number_format((float)($p['monto'] ?? 0), 2));
        }
        if ((float)($t['cambio'] ?? 0) > 0) $d .= esc_lr("Cambio", '$' . number_format((float)$t['cambio'], 2));
    }

    $d .= esc_center() . "\n";
    if ($pie) $d .= esc_wrap($pie);
    $d .= esc_feedcut();
    return $d;
}

/* ─────────── API de alto nivel (usadas por los handlers) ─────────── */

/** Imprime una comanda a la impresora de su cocina. No lanza excepciones. */
function print_comanda_red(array $comanda): bool {
    try {
        $dest = printer_dest_cocina((int)$comanda['cocina']);
        if (!$dest) return false;
        return escpos_send($dest, build_comanda_escpos($comanda));
    } catch (Throwable $e) {
        if (function_exists('log_action')) log_action('print_error', 'comanda', $comanda['id'] ?? null, $e->getMessage());
        return false;
    }
}

/**
 * Aviso suelto para una cocina (recordatorio tipo "freír bolsas de papas").
 */
function build_aviso_escpos(int $cocina, string $mensaje, string $usuario = '', string $hora = ''): string {
    $d  = esc_init();
    $d .= esc_center();
    $d .= esc_bold(true) . esc_size(1, 1);
    $d .= esc_t("AVISO") . "\n";
    $d .= esc_t("COCINA " . cocina_letra($cocina)) . "\n";
    $d .= esc_size(0, 0) . esc_bold(false);
    $d .= esc_hr('=');
    // Mensaje grande y en negrita para lectura rápida
    $d .= esc_bold(true) . esc_size(0, 1);
    $d .= esc_wrap($mensaje);
    $d .= esc_size(0, 0) . esc_bold(false);
    $d .= esc_hr('-');
    $d .= esc_left();
    if ($usuario !== '') $d .= esc_t("De: " . $usuario) . "\n";
    $d .= esc_t("Hora: " . ($hora !== '' ? $hora : date('H:i'))) . "\n";
    $d .= esc_feedcut();
    return $d;
}

/** Imprime un aviso a la impresora de su cocina. No lanza excepciones. */
function print_aviso_red(int $cocina, string $mensaje, string $usuario = ''): bool {
    try {
        $dest = printer_dest_cocina($cocina);
        if (!$dest) return false;
        return escpos_send($dest, build_aviso_escpos($cocina, $mensaje, $usuario));
    } catch (Throwable $e) {
        if (function_exists('log_action')) log_action('print_error', 'aviso', $cocina, $e->getMessage());
        return false;
    }
}

/** Imprime el ticket de venta a la impresora de caja. No lanza excepciones. */
function print_ticket_red(array $ticket): bool {
    try {
        $dest = printer_dest_ticket();
        if (!$dest) return false;
        return escpos_send($dest, build_ticket_escpos($ticket));
    } catch (Throwable $e) {
        if (function_exists('log_action')) log_action('print_error', 'ticket', $ticket['orden_id'] ?? null, $e->getMessage());
        return false;
    }
}
