<?php
/**
 * API PÚBLICA del carrito web (sin login).
 * Rutas registradas como públicas en api/index.php.
 *
 *   web/menu     → productos disponibles + combos (para armar el carrito)
 *   web/config   → configuración pública (whatsapp, costo envío, activo)
 *   web/submit   → crear pedido web PENDIENTE (lo acepta el restaurante)
 */
return [
    /** Configuración pública para el carrito */
    'config' => function () {
        $estado = restaurante_abierto();
        json_response([
            'abierto'       => $estado['abierto'],
            'cerrado_msg'   => $estado['msg'],
            'activo'        => (int)cfg('web_pedidos_activo', 1),
            'whatsapp'      => preg_replace('/\D/', '', cfg('whatsapp_pedidos', '')),
            'negocio'       => cfg('negocio_nombre', 'Jacks Rock'),
            'horario'       => cfg('negocio_horario', ''),
            'rest_lat'      => (float)cfg('restaurante_lat', 0),
            'rest_lng'      => (float)cfg('restaurante_lng', 0),
            // Datos de transferencia (para mostrar al elegir ese método)
            'transfer'      => [
                'banco'   => cfg('transfer_banco', ''),
                'titular' => cfg('transfer_titular', ''),
                'cuenta'  => cfg('transfer_cuenta', ''),
                'clabe'   => cfg('transfer_clabe', ''),
            ],
        ]);
    },

    /** Menú para el carrito (solo disponibles) + modificadores */
    'menu' => function () {
        $pdo = db();
        $cats = $pdo->query("SELECT id, nombre FROM categorias WHERE activa=1 ORDER BY orden")->fetchAll();
        $prods = $pdo->query("
            SELECT p.id, p.nombre, p.descripcion, p.precio, p.emoji, p.imagen,
                   p.destacado, p.categoria_id
            FROM productos p
            WHERE p.disponible=1
            ORDER BY p.categoria_id, p.destacado DESC, p.nombre
        ")->fetchAll();

        // Modificadores por producto (igual que el sistema interno)
        $grupos = $pdo->prepare("
            SELECT g.id, g.nombre, g.tipo, g.obligatorio, g.max_selecciones
            FROM producto_modificador_grupo pmg
            JOIN modificador_grupos g ON g.id = pmg.grupo_id
            WHERE pmg.producto_id = ?
        ");
        $opciones = $pdo->prepare("SELECT id, nombre, precio_extra FROM modificadores WHERE grupo_id=? AND activo=1 ORDER BY orden, nombre");

        foreach ($prods as &$p) {
            $p['precio'] = (float)$p['precio'];
            $p['destacado'] = (bool)$p['destacado'];
            $grupos->execute([$p['id']]);
            $gs = $grupos->fetchAll();
            foreach ($gs as &$g) {
                $opciones->execute([$g['id']]);
                $g['opciones'] = array_map(function($o){ $o['precio_extra'] = (float)$o['precio_extra']; return $o; }, $opciones->fetchAll());
            }
            $p['modificadores'] = $gs;
        }

        $combos = $pdo->query("SELECT id, nombre, descripcion, precio, emoji FROM combos WHERE activo=1")->fetchAll();
        $citems = $pdo->prepare("SELECT p.nombre, ci.cantidad FROM combo_items ci JOIN productos p ON p.id=ci.producto_id WHERE ci.combo_id=?");
        foreach ($combos as &$c) {
            $c['precio'] = (float)$c['precio'];
            $citems->execute([$c['id']]);
            $c['items'] = $citems->fetchAll();
        }

        json_response(['categorias' => $cats, 'productos' => $prods, 'combos' => $combos]);
    },

    /**
     * Crea un pedido web PENDIENTE de aceptación.
     * Body JSON:
     *   tipo_entrega: 'llevar' | 'domicilio'
     *   cliente: { nombre, telefono, direccion?, referencias?, lat?, lng? }
     *   items: [{ producto_id, cantidad, notas? }]
     *   combos: [{ combo_id, cantidad }]
     */
    'submit' => function () {
        require_method('POST');

        // Validar horario / cierre manual server-side (anti pedidos fuera de hora)
        $estado = restaurante_abierto();
        if (!$estado['abierto']) {
            json_response(['error' => 'Estamos cerrados. ' . $estado['msg'], 'cerrado' => true], 403);
        }

        $in = json_input();
        $tipo_entrega = $in['tipo_entrega'] ?? '';
        if (!in_array($tipo_entrega, ['llevar','domicilio'])) {
            json_response(['error' => 'Tipo de entrega inválido'], 400);
        }
        $metodo_pago = $in['metodo_pago'] ?? 'efectivo';
        if (!in_array($metodo_pago, ['efectivo','transferencia'])) $metodo_pago = 'efectivo';
        $paga_con = isset($in['paga_con']) && $in['paga_con'] !== '' ? (float)$in['paga_con'] : null;

        $cli = $in['cliente'] ?? [];
        $nombre = trim($cli['nombre'] ?? '');
        $tel    = preg_replace('/\D/', '', $cli['telefono'] ?? '');
        if (strlen($nombre) < 2)  json_response(['error' => 'Nombre requerido'], 400);
        if (strlen($tel) < 10)    json_response(['error' => 'Teléfono válido requerido (10 dígitos)'], 400);

        $direccion = trim($cli['direccion'] ?? '');
        $referencias = trim($cli['referencias'] ?? '');
        $lat = isset($cli['lat']) && $cli['lat'] !== '' ? (float)$cli['lat'] : null;
        $lng = isset($cli['lng']) && $cli['lng'] !== '' ? (float)$cli['lng'] : null;

        // El costo de envío NO se calcula automáticamente: el restaurante lo
        // define manualmente al revisar el pedido (depende de ruta, clima, etc.).
        // Si el cliente compartió GPS, guardamos la distancia SOLO como referencia
        // informativa para que el restaurante tenga contexto.
        $envio_info = ['distancia_km' => null, 'costo' => 0, 'por_cotizar' => false];
        if ($tipo_entrega === 'domicilio') {
            if (strlen($direccion) < 5) json_response(['error' => 'Dirección requerida para domicilio'], 400);
            $envio_info['por_cotizar'] = true;   // siempre lo cotiza el restaurante
            if ($lat !== null && $lng !== null) {
                $rLat = (float)cfg('restaurante_lat', 0);
                $rLng = (float)cfg('restaurante_lng', 0);
                if ($rLat && $rLng) {
                    $envio_info['distancia_km'] = distancia_km($rLat, $rLng, $lat, $lng);
                }
            }
        }

        $items  = $in['items']  ?? [];
        $combos = $in['combos'] ?? [];
        if (!$items && !$combos) json_response(['error' => 'El carrito está vacío'], 400);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Cliente: buscar o crear/actualizar
            $cs = $pdo->prepare("SELECT id FROM clientes WHERE telefono=?");
            $cs->execute([$tel]);
            $cliente_id = (int)($cs->fetchColumn() ?: 0);
            if ($cliente_id) {
                $pdo->prepare("UPDATE clientes SET nombre=?, direccion=COALESCE(NULLIF(?, ''), direccion),
                               referencias=COALESCE(NULLIF(?, ''), referencias), lat=?, lng=? WHERE id=?")
                    ->execute([$nombre, $direccion, $referencias, $lat, $lng, $cliente_id]);
            } else {
                $pdo->prepare("INSERT INTO clientes (telefono, nombre, direccion, referencias, lat, lng) VALUES (?,?,?,?,?,?)")
                    ->execute([$tel, $nombre, $direccion ?: null, $referencias ?: null, $lat, $lng]);
                $cliente_id = (int)$pdo->lastInsertId();
            }

            // Crear la orden web (pendiente, sin tocar cocina aún)
            $costo_envio  = (float)$envio_info['costo'];
            $dist_km      = $envio_info['distancia_km'];
            $por_cotizar  = $envio_info['por_cotizar'] ? 1 : 0;
            $pdo->prepare("
                INSERT INTO ordenes
                  (mesa_id, tipo, estado, estado_web, web_tipo_entrega,
                   cliente_id, cliente_nombre, cliente_telefono, cliente_direccion,
                   cliente_referencias, cliente_lat, cliente_lng,
                   costo_envio, envio_distancia_km, envio_por_cotizar,
                   web_metodo_pago, paga_con,
                   estado_entrega, web_creado_at)
                VALUES (NULL,'web','abierta','pendiente',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $tipo_entrega, $cliente_id, $nombre, $tel,
                $direccion ?: null, $referencias ?: null, $lat, $lng,
                $costo_envio, $dist_km, $por_cotizar,
                $metodo_pago, $paga_con,
                $tipo_entrega === 'domicilio' ? 'pendiente' : null,
            ]);
            $orden_id = (int)$pdo->lastInsertId();

            // Agregar items (con modificadores)
            $prodStmt = $pdo->prepare("SELECT nombre, precio, disponible FROM productos WHERE id=?");
            $modStmt  = $pdo->prepare("SELECT nombre, precio_extra FROM modificadores WHERE id=? AND activo=1");
            $insItem = $pdo->prepare("INSERT INTO orden_items
                (orden_id, producto_id, nombre, precio, cantidad, notas, modificadores_json, precio_extra)
                VALUES (?,?,?,?,?,?,?,?)");
            $resumen = []; // para el mensaje de whatsapp

            foreach ($items as $it) {
                $pid = (int)($it['producto_id'] ?? 0);
                $qty = max(1, (int)($it['cantidad'] ?? 1));
                $notas = trim($it['notas'] ?? '');
                $mods = is_array($it['modificadores'] ?? null) ? $it['modificadores'] : [];
                $prodStmt->execute([$pid]);
                $p = $prodStmt->fetch();
                if (!$p || !$p['disponible']) continue;

                // Validar modificadores contra la BD (nunca confiar en precios del cliente)
                $extra = 0;
                $modsTxt = [];
                $modsLimpio = [];
                foreach ($mods as $m) {
                    $mid = (int)($m['id'] ?? 0);
                    if (!$mid) continue;
                    $modStmt->execute([$mid]);
                    $md = $modStmt->fetch();
                    if (!$md) continue; // modificador inexistente o inactivo → se ignora
                    $pe = (float)$md['precio_extra'];
                    $extra += $pe;
                    $modsLimpio[] = ['id' => $mid, 'nombre' => $md['nombre'], 'precio_extra' => $pe];
                    $modsTxt[] = $md['nombre'] . ($pe > 0 ? ' (+$'.number_format($pe, 2).')' : '');
                }
                $modsJson = $modsLimpio ? json_encode($modsLimpio, JSON_UNESCAPED_UNICODE) : null;

                $insItem->execute([$orden_id, $pid, $p['nombre'], $p['precio'], $qty, $notas ?: null, $modsJson, $extra]);

                $linea = "{$qty}x {$p['nombre']}";
                if ($modsTxt) $linea .= " [" . implode(', ', $modsTxt) . "]";
                if ($notas) $linea .= " ($notas)";
                $resumen[] = $linea;
            }

            // Agregar combos (descompuestos con precio prorrateado)
            foreach ($combos as $cb) {
                $cid = (int)($cb['combo_id'] ?? 0);
                $qty = max(1, (int)($cb['cantidad'] ?? 1));
                $combo = $pdo->prepare("SELECT * FROM combos WHERE id=? AND activo=1");
                $combo->execute([$cid]);
                $c = $combo->fetch();
                if (!$c) continue;
                $ci = $pdo->prepare("SELECT ci.cantidad, p.id, p.nombre, p.precio FROM combo_items ci JOIN productos p ON p.id=ci.producto_id WHERE ci.combo_id=?");
                $ci->execute([$cid]);
                $citems = $ci->fetchAll();
                $sumOrig = 0; foreach ($citems as $x) $sumOrig += $x['cantidad'] * $x['precio'];
                $factor = $sumOrig > 0 ? ($c['precio'] / $sumOrig) : 1;
                $nota = "🎁 {$c['nombre']}";
                foreach ($citems as $x) {
                    $precioPror = round($x['precio'] * $factor, 2);
                    $insItem->execute([$orden_id, $x['id'], $x['nombre'], $precioPror, $x['cantidad'] * $qty, $nota]);
                }
                $resumen[] = "{$qty}x 🎁 {$c['nombre']}";
            }

            recalc_order_totals($orden_id);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => 'No se pudo crear el pedido: ' . $e->getMessage()], 500);
        }

        // Disparar aviso al restaurante (badge/sonido vía SSE)
        @touch(__DIR__ . '/../../config/.sse-trigger');
        @touch(__DIR__ . '/../../config/.web-trigger');

        log_action('web_pedido', 'orden', $orden_id, strtoupper($tipo_entrega) . " · $nombre · $tel");

        // Recargar total final
        $ot = $pdo->prepare("SELECT total, costo_envio FROM ordenes WHERE id=?");
        $ot->execute([$orden_id]);
        $tot = $ot->fetch();
        $totalFinal = (float)$tot['total'] + (float)$tot['costo_envio'];

        // Armar mensaje de WhatsApp pre-llenado
        $wa = preg_replace('/\D/', '', cfg('whatsapp_pedidos', ''));
        $lineas = [];
        $lineas[] = "*NUEVO PEDIDO #" . $orden_id . "* (" . cfg('negocio_nombre','Jacks Rock') . ")";
        $lineas[] = "";
        $lineas[] = "👤 *Cliente:* $nombre";
        $lineas[] = "📞 *Tel:* $tel";
        $lineas[] = "🛍 *Tipo:* " . ($tipo_entrega === 'domicilio' ? "🛵 A DOMICILIO" : "📦 PARA RECOGER");
        if ($tipo_entrega === 'domicilio') {
            $lineas[] = "📍 *Dirección:* $direccion";
            if ($referencias) $lineas[] = "📝 *Ref:* $referencias";
            if ($lat && $lng) $lineas[] = "🗺 *Ubicación:* https://maps.google.com/?q=$lat,$lng";
            if ($dist_km !== null) $lineas[] = "📏 *Distancia:* {$dist_km} km";
        }
        $lineas[] = "";
        $lineas[] = "*Pedido:*";
        foreach ($resumen as $r) $lineas[] = "• $r";
        $lineas[] = "";
        $lineas[] = "*Subtotal productos: $" . number_format($totalFinal, 2) . "*";
        if ($tipo_entrega === 'domicilio') {
            $lineas[] = "🛵 *Envío:* lo confirmamos según tu ubicación";
        }
        $lineas[] = "";
        if ($metodo_pago === 'transferencia') {
            $lineas[] = "💳 *Pago: TRANSFERENCIA*";
            $lineas[] = "_Adjunto mi comprobante de pago._";
        } else {
            $lineas[] = "💵 *Pago: EFECTIVO*";
            if ($paga_con) $lineas[] = "Pago con: $" . number_format($paga_con, 2);
        }
        $lineas[] = "";
        $lineas[] = "_Pedido generado desde la web. Confírmalo por aquí y te pasamos el total final con el envío._";

        $mensaje = implode("\n", $lineas);
        $wa_url = $wa ? "https://wa.me/52$wa?text=" . rawurlencode($mensaje) : null;

        json_response([
            'ok'        => true,
            'orden_id'  => $orden_id,
            'total'     => $totalFinal,
            'whatsapp'  => $wa_url,
            'mensaje'   => 'Tu pedido fue registrado. Confírmalo por WhatsApp para que el restaurante lo prepare.',
        ]);
    },

    /**
     * Estado de un pedido (para que el cliente consulte si fue aceptado)
     */
    'estado' => function () {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare("SELECT estado_web, web_motivo_rechazo, estado_entrega FROM ordenes WHERE id=? AND tipo='web'");
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Pedido no encontrado'], 404);
        json_response($o);
    },
];
