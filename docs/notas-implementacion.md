# Notas de implementación

> Detalle técnico de cada bloque: rutas finales, ejemplos JSON, códigos HTTP, versiones y procedimiento de despliegue.

## Bloque 0

- Repositorio: https://github.com/anderson-0112/proyecto-ecommerce (rama `main`).
- Herramientas locales: Git 2.55, Docker 29.8 / Docker Compose v5.5 (Docker Desktop, Windows 11).

## Bloque 1 — Núcleo (30-oct-2026)

### Versiones (verificadas en los contenedores)
| Componente | Versión |
|---|---|
| PHP | 8.3.36 (imagen `php:8.3-apache`, Apache 2.4.69) — extensiones: `pdo_mysql`, `curl` |
| MariaDB | 10.6.28 (imagen `mariadb:10.6`) |
| Nginx | 1.27.5 (imagen `nginx:1.27-alpine`) |
| Stripe | API REST v1 con cURL (sin SDK) + Stripe.js v3 en el navegador |
| Bootstrap | 5.3.3 (CDN jsDelivr) — página de prueba |
| JWT | Implementación propia HS256 (`auth-service/src/jwt.php`), sin librerías |

### Rutas implementadas (a través del Gateway, `http://<host>/…`)
| Servicio | Método y ruta | Acceso | Códigos |
|---|---|---|---|
| auth | `POST /api/auth/register` | público | 201, 409, 422 |
| auth | `POST /api/auth/login` | público | 200, 400, 401 |
| auth | `POST /api/auth/verify` | **interno** (Gateway → 403) | 200, 401 |
| catálogo | `GET /api/catalogo/productos?categoria=&precio_max=&pagina=&por_pagina=` | público | 200, 400 |
| catálogo | `GET /api/catalogo/productos/{id}` | público | 200, 404 |
| catálogo | `GET /api/catalogo/destacados` | público | 200 |
| catálogo | `GET /api/catalogo/categorias` | público | 200 |
| catálogo | `POST /api/catalogo/productos` | admin | 201, 401, 403, 422 |
| catálogo | `PUT /api/catalogo/productos/{id}` (parcial) | admin | 200, 401, 403, 404, 422 |
| catálogo | `DELETE /api/catalogo/productos/{id}` (baja lógica) | admin | 204, 401, 403, 404 |
| catálogo | `POST /api/catalogo/interno/verificar-stock` | interno + JWT | 200, 404, 409, 422 |
| catálogo | `POST /api/catalogo/interno/descontar-stock` | interno + JWT | 200, 409, 422 |
| pedidos | `POST /api/pedidos` | cliente/admin | 201, 202, 401, 404, 409, 422, 503 |
| pedidos | `GET /api/pedidos?estado=` (historial) | dueño / admin ve todos | 200, 401 |
| pedidos | `GET /api/pedidos/{id}` | dueño o admin (D16) | 200, 401, 403, 404 |
| pedidos | `PUT /api/pedidos/{id}/estado` | admin | 200, 401, 403, 404, 409, 422 |
| pedidos | `PUT /api/pedidos/interno/{id}/pagado` | interno + JWT | 200, 404 |
| pagos | `POST /api/pagos/stripe` | dueño del pedido | 200, 202, 401, 403, 404, 409, 422, 500, 503 |
| pagos | `GET /api/pagos/{id}/estado` | dueño o admin | 200, 401, 403, 404 |
| pagos | `GET /api/pagos/config` (clave pública Stripe) | público | 200 |
| notificaciones | `POST /api/notificaciones/interno/confirmacion-pedido` | interno + JWT | 200, 401, 422 |
| todos | `GET /api/<servicio>/health` | público | 200, 503 |

Pendiente de bloques siguientes: `POST /api/auth/logout` (Bloque 2), `POST /api/pagos/paypal` (Bloque 4).

### Ejemplos JSON
**Login** — `POST /api/auth/login` `{"email":"cliente@test.com","password":"Test2026!"}` →
```json
{ "token": "eyJhbGciOiJIUzI1NiIs…", "expira_en": 7200,
  "usuario": { "id": 2, "nombre": "Cliente de Prueba", "email": "cliente@test.com", "rol": "cliente" } }
```
**Crear pedido** — `POST /api/pedidos` (Bearer JWT):
```json
{ "items": [ { "id_producto": 12, "cantidad": 2 }, { "id_producto": 20, "cantidad": 1 } ],
  "metodo_pago": "stripe", "payment_method_id": "pm_…",
  "direccion": { "direccion": "4a. Calle 5-20, Zona 1", "ciudad": "Santa Cruz del Quiché", "telefono": "5555-0000" } }
```
→ **201**
```json
{ "pedido": { "id": 1, "estado": "pagado", "total": 278, "fecha": "2026-10-30 17:11:34",
    "cliente": { "id": 1, "nombre": "Cliente de Prueba", "email": "cliente@test.com", "telefono": "5555-0000" },
    "direccion": { "direccion": "4a. Calle 5-20, Zona 1", "ciudad": "Santa Cruz del Quiché", "telefono": "5555-0000" },
    "detalle": [ { "id_producto": 12, "nombre_producto": "JBL Flip 4", "cantidad": 2, "precio_unitario": 99, "subtotal": 198 },
                 { "id_producto": 20, "nombre_producto": "Raspberry Pi 5 (8 GB)", "cantidad": 1, "precio_unitario": 80, "subtotal": 80 } ],
    "qr_url": "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=http%3A%2F%2Flocalhost%2Fseguimiento.html%3Fpedido%3D1" },
  "pago": { "id": 1, "id_pedido": 1, "monto": 278, "metodo": "stripe", "referencia_stripe": "pi_…", "estado": "aprobado" },
  "pedido_estado": "pagado", "correo_enviado": false,
  "correo_mensaje": "Aviso registrado; el envío real por EmailJS se activa en el Bloque 4" }
```
**Sin stock** → **409** `{"error":"Stock insuficiente","sin_stock":[{"id_producto":6,"nombre":"Lenovo ThinkPad X1 Carbon","solicitado":999,"disponible":5}]}`
**Tarjeta rechazada** → **422** `{"pedido":{…,"estado":"pendiente"},"error":"Pago rechazado: …","pago":{…,"estado":"rechazado"},"codigo_rechazo":"…"}`
**3D Secure** → **202** `{"pedido":{…},"pago":{…,"estado":"pendiente"},"requiere_accion":true,"client_secret":"pi_…_secret_…"}`

### Esquema final de BD (para la sección 11 y el DER, Fig. 3)
- `auth_db.usuarios(id PK, nombre VARCHAR(100), email VARCHAR(150) UNIQUE, password_hash VARCHAR(255), rol ENUM('cliente','admin'), created_at DATETIME)`
- `catalogo_db.categorias(id PK, nombre VARCHAR(80) UNIQUE, descripcion VARCHAR(255))` · `productos(id PK, nombre, descripcion TEXT, precio DECIMAL(10,2), stock INT UNSIGNED, imagen_url VARCHAR(500), destacado TINYINT, activo TINYINT, categoria_id FK→categorias)`
- `pedidos_db.clientes(id PK, id_usuario UNIQUE [ref. lógica a usuarios.id], nombre, email, telefono)` · `direcciones(id PK, id_cliente FK, direccion, ciudad, telefono)` · `pedidos(id PK, id_cliente FK, total DECIMAL(10,2), estado ENUM('pendiente','pagado','preparando','enviado','entregado'), fecha DATETIME)` · `detalle_pedidos(id PK, id_pedido FK, id_producto [ref. lógica], nombre_producto, cantidad, precio_unitario, subtotal)`
- `pagos_db.pagos(id PK, id_pedido [ref. lógica], monto DECIMAL(10,2), metodo ENUM('stripe','paypal'), referencia_stripe UNIQUE, referencia_paypal UNIQUE, estado ENUM('pendiente','aprobado','rechazado'), fecha_pago DATETIME)`
- Cada servicio usa un usuario de BD propio con `SELECT, INSERT, UPDATE, DELETE` solo sobre su base.

### Decisiones de implementación (informar en el informe)
- Hosting: **VPS de Hostinger** con el mismo `docker-compose.yml` (reemplaza Railway). Solo el Gateway publica puerto (`${GATEWAY_PORT:-80}`); MariaDB **no** publica puerto al host.
- URLs entre servicios y conexión a BD en variables de entorno con valores de Docker por defecto (`${AUTH_URL:-http://auth-service:8081}`, etc.).
- Precios y monto del cobro siempre desde el servidor; `Idempotency-Key` en Stripe.
- Al pagarse, el stock se descuenta con `UPDATE … WHERE stock >= ?` dentro de una transacción (mitiga la carrera de D20).
- La dirección se guarda en `direcciones` ligada al cliente (D5); el pedido muestra la última dirección registrada del cliente. Limitación conocida: un pedido antiguo muestra la dirección más reciente.
- Estados del pedido (Fig. 5): las notas `[PENDIENTE]` del diagrama se resuelven con D1 (`pagado` en el flujo, lo pone el sistema) y D2 (rechazado → sigue `pendiente`). Actualizar el diagrama en el chat del Proyecto.
- Imágenes: Wikimedia Commons (licencias libres, `docs/creditos-imagenes.md`); migración a Cloudinary en el Bloque 4.

### Evidencias de prueba
- `pruebas/prueba-bloque1.php` → `docs/pruebas/salidas/bloque1-nucleo.txt`: **41 correctas, 0 con fallo** (30-oct).
- Compra manual en `http://localhost/prueba-compra.html` con 4242 4242 4242 4242 · 12/29 · 123 → pedido #5 `pagado`, pago `aprobado` con `pi_…`, stock del iPhone 15 de 15 a 14.
- Tolerancia: con `notificaciones-service` detenido, la compra respondió 201, pedido `pagado`, `correo_enviado: false` y el mensaje "No se pudo enviar el correo: servicio de notificaciones no disponible" (≈ 4 s).

## Bloque 2 — Datos distribuidos (30-oct-2026)

### Versiones nuevas
| Componente | Versión |
|---|---|
| MariaDB Slave | 10.6.28 (imagen `mariadb:10.6`) |
| Redis | 7 (imagen `redis:7-alpine`), AOF activado, con contraseña |
| phpredis | 6.1.0 (en auth-service y catalogo-service) |

### Contenedores (total 9)
gateway, auth-service, catalogo-service, pedidos-service, pagos-service, notificaciones-service, mariadb-master, **mariadb-slave**, **redis** (+ volúmenes `master-data`, `slave-data`, `redis-data`). Solo el gateway publica puerto.

### Replicación
- Master: `--server-id=1 --log-bin=mysql-bin --binlog-format=ROW`; usuario `replicador` con `REPLICATION REPLICA` (variables `MARIADB_REPLICATION_USER/PASSWORD`).
- Slave: `--server-id=2 --read-only=1 --relay-log=relay-bin`; `MARIADB_MASTER_HOST=mariadb-master`. Replica desde el inicio del binlog (incluye esquema, datos semilla y usuarios de servicio).
- Lecturas desde el Slave: catalogo-service (todas las lecturas públicas) y `GET /api/pedidos` cuando lo consulta un admin (reportes, D9). Verificar y descontar stock se hace en el Master.
- Evidencia: `SHOW SLAVE STATUS` → `Slave_IO_Running: Yes`, `Slave_SQL_Running: Yes`, `Seconds_Behind_Master: 0`; producto creado en el Master visible en el Slave en ≈ 0.01 s (`docs/pruebas/salidas/bloque2-replicacion-redis.txt`).
- Si el Slave cae: lectura desde el Master (≈ 1 s la primera petición, luego ≈ 0.01 s gracias a la marca `caido:<host>` en Redis, 15 s).

### Redis
- Caché del catálogo: claves `catalogo:v<versión>:productos:<md5 de filtros>`, `…:producto:<id>`, `…:destacados`, `…:categorias`; TTL 30 s; invalidación con `INCR catalogo:version`. Encabezado `X-Cache: HIT | MISS | SIN-REDIS`.
- Tokens revocados: `revocado:<jti>` con `SETEX` igual al tiempo restante del token.
- Si Redis cae: catálogo 200 con `X-Cache: SIN-REDIS` (≈ 1 s); compras y consultas siguen; `POST /api/auth/logout` → 503.

### Rutas y cambios
| Método y ruta | Acceso | Códigos | Nota |
|---|---|---|---|
| `POST /api/auth/logout` | con token | 204, 401, 503 | Nueva (D8) |
| `POST /api/auth/verify` | interno | 200, 401 | Rechaza tokens revocados: `{"error":"Token revocado (la sesión fue cerrada)","valido":false}` |
| `GET /api/pedidos?estado=&desde=AAAA-MM-DD` | dueño / admin | 200, 400, 401 | Nuevo parámetro `desde`; el admin lee del Slave (hasta 2000 filas) |
| `GET /api/catalogo/health` | público | 200 | Informa `lecturas_desde` (`Slave (server_id=2)` o `Master (server_id=1)`) y `redis` |

### Variables nuevas en `.env`
`REPLICATION_PASSWORD`, `REDIS_PASSWORD` (opcional `REDIS_HOST`, por defecto `redis`). `DB_READ_HOST` vale `mariadb-slave` por defecto.

### Otros
- `php.ini` por servicio: `display_errors=Off`, errores al log del contenedor.
- `dns_opt: timeout:1, attempts:1` en los servicios PHP: un contenedor detenido se detecta en 1 s (antes ≈ 8 s).
- Para aplicar el Bloque 2 sobre un volumen del Bloque 1 hay que recrear las BD con `docker compose down -v` (el binlog debe existir desde la creación del Master).
- Pruebas: `pruebas/prueba-bloque2.php` 15/15; regresión `prueba-bloque1.php` 41/41.

## Bloque 3 — Interfaces (30-oct-2026)

### Librerías del frontend (CDN jsDelivr, versiones fijas)
| Librería | Versión | Uso |
|---|---|---|
| Bootstrap | 5.3.3 | Estilos y componentes (tienda y admin) |
| Bootstrap Icons | 1.11.3 | Iconos |
| Chart.js | 4.4.4 | Gráficas del dashboard |
| jsPDF | 2.5.1 | Exportar reporte de ventas a PDF (D19) |
| jsPDF-AutoTable | 3.8.2 | Tabla del reporte PDF |
| Stripe.js | v3 (js.stripe.com) | Tokenizar la tarjeta en el navegador |

### Páginas
`/` (catálogo sin registro: destacados, filtros categoría/precio máximo, paginación de 8, detalle modal), `/login.html` (ingreso y registro), `/checkout.html` (Stripe Elements, confirmación con QR), `/mis-pedidos.html`, `/seguimiento.html?pedido=N` (destino del QR), `/admin/` (dashboard Chart.js, pedidos, CRUD, PDF). Página técnica de pruebas: `/prueba-compra.html`.

### Rutas usadas por el frontend
Sin rutas nuevas. Tienda: `GET /api/catalogo/destacados`, `GET /api/catalogo/categorias`, `GET /api/catalogo/productos`, `GET /api/catalogo/productos/{id}`, `POST /api/auth/register|login|logout`, `GET /api/pagos/config`, `POST /api/pedidos`, `POST /api/pagos/stripe` (reintento D2 y 3D Secure), `GET /api/pedidos`, `GET /api/pedidos/{id}`. Admin: además `PUT /api/pedidos/{id}/estado`, `POST/PUT/DELETE /api/catalogo/productos`.

### Detalles de implementación
- Sesión y carrito en `localStorage` (`nodotec_sesion`, `nodotec_carrito`); el servidor recalcula precios.
- Dashboard: KPI de ventas de hoy / últimos 7 días / mes y pedidos por preparar; barras de ventas por día (14 días); dona de pedidos por estado. Venta = pedido en `pagado` o posterior. Fechas de la BD en UTC, mostradas en hora local.
- `mailto:` con asunto "NodoTec — Tu pedido #N" (D12).
- Gateway: `Cache-Control: no-cache` en archivos estáticos (el navegador revalida y no usa versiones viejas tras un cambio) y `absolute_redirect off` (`/admin` → `/admin/` sin cambiar host ni puerto).
- Corrección en pagos-service: la clave de idempotencia de Stripe ahora incluye la fecha del pedido (`pedido-<id>-<AAAAMMDDhhmmss>-<pm>`). Antes, al recrear la BD los ids de pedido se repetían y Stripe rechazaba el cobro por clave reutilizada (`idempotency_error`).

### Evidencias de prueba (navegador, 30-oct)
- Catálogo sin sesión: 6 destacados, 8 productos por página, 3 páginas, 7 categorías en el filtro.
- Checkout: tarjeta de prueba rechazada 4000 0000 0000 0002 → "Pago rechazado… Tu pedido #5 quedó pendiente"; reintento con 4242 sobre el mismo pedido → "Tu pedido #5 está pagado", QR mostrado, carrito vaciado.
- Seguimiento y Mis pedidos muestran estado, productos, dirección y QR; horas en hora de Guatemala.
- Admin: dashboard con KPI y gráficas (US$ 1,253.00 en el día de prueba); pedido #5 pasado a "preparando"; `mailto:` correcto; crear / editar stock / eliminar producto (20 → 21 → 20); PDF generado (jsPDF).
- Responsive: tienda y admin a 375 px sin desplazamiento horizontal.
- Regresión: `prueba-bloque1.php` 41/41 y `prueba-bloque2.php` 15/15.

## Bloque 4 — APIs externas (30-oct-2026)

### APIs integradas y visibles (7)
Stripe, PayPal Sandbox, EmailJS, Cloudinary, OpenWeatherMap, QR Code API (api.qrserver.com) y Stripe.js / PayPal JS SDK en el navegador.

### Rutas nuevas o cambiadas
| Método y ruta | Acceso | Códigos | Nota |
|---|---|---|---|
| `POST /api/pagos/paypal` `{id_pedido}` | dueño del pedido | 202, 401, 403, 404, 409, 422, 500, 503 | Paso 1: crea la orden; responde `{pago, requiere_aprobacion: true, paypal_orden_id}` |
| `POST /api/pagos/paypal` `{id_pedido, orden_id}` | dueño del pedido | 200, 404, 409, 422, 503 | Paso 2: captura; 200 como Stripe (`pago`, `pedido_estado`, `correo_enviado`, `correo_mensaje`); 422 `PayPal no completó el pago (ORDER_NOT_APPROVED)` |
| `POST /api/pedidos` | cliente/admin | + 202 | Acepta `metodo_pago: "paypal"` (sin `payment_method_id`); responde 202 con `pedido` + `paypal_orden_id` |
| `GET /api/pagos/config` | público | 200 | Ahora también `paypal_client_id` |
| `GET /api/pedidos/clima` | admin | 200, 401, 403, 503 | **Ruta nueva.** `{ciudad, temperatura, sensacion, humedad, viento_kmh, descripcion, icono_url, lluvia, hora, cache}` |
| `POST /api/catalogo/imagenes` (multipart, campo `imagen`) | admin | 201, 401, 403, 422, 503 | **Ruta nueva.** `{imagen_url}` de Cloudinary; JPG/PNG/WEBP/GIF, máx. 5 MB |
| `POST /api/notificaciones/interno/confirmacion-pedido` | interno | 200 | Envía el correo con EmailJS: `{registrado, enviado, mensaje}` |

### Variables nuevas (`.env`)
`PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_API_URL` (por defecto sandbox), `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID`, `EMAILJS_PUBLIC_KEY`, `EMAILJS_PRIVATE_KEY`, `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `CLOUDINARY_CARPETA` (por defecto `nodotec/productos`), `OPENWEATHER_API_KEY`, `CLIMA_CIUDAD`, `CLIMA_LAT`, `CLIMA_LON` (por defecto Santa Cruz del Quiché, 15.0306, -91.1489).

### Plantilla EmailJS
Variables: `to_email`, `cliente_nombre`, `pedido_id`, `total` (`US$ 99.00`), `enlace_seguimiento` (`APP_URL/seguimiento.html?pedido=N`). Envío desde el servidor con la Private Key como `accessToken` (opción "Allow EmailJS API for non-browser applications" activada).

### Cloudinary
- 20 imágenes migradas (carpeta `nodotec/productos`, `public_id` `producto-<id>`); `db/init-master.sql` actualizado con las URLs del CDN (`…/image/upload/f_auto,q_auto,w_600/…`).
- Script reutilizable: `docker compose exec catalogo-service php /var/www/html/src/migrar-imagenes-cloudinary.php` (solo procesa imágenes que no estén ya en Cloudinary).

### Evidencias (30-oct)
- Credenciales verificadas: OpenWeather 200 (Santa Cruz del Quiché 21.9 °C, nubes dispersas), Cloudinary 200 (plan Free), PayPal sandbox token 200.
- `pruebas/prueba-bloque4.php`: **16/16** (clima 200 / 403 cliente; 20/20 imágenes en Cloudinary y CDN 200; subida admin 201, cliente 403, archivo falso 422; compra con correo enviado por EmailJS; QR 200 imagen; PayPal: config pública sin secreto, pedido 202 con orden, captura sin aprobar 422 `ORDER_NOT_APPROVED`, orden ajena 404, otro cliente 403).
- Navegador: compra con PayPal sandbox (cuenta compradora de prueba) → pedido #12 `pagado`, pago `aprobado` con `referencia_paypal`, "Correo de confirmación enviado"; dashboard con clima; subida de imagen desde el formulario de producto → URL de Cloudinary.
- Regresión: bloque 1 41/41, bloque 2 15/15.
- Las pruebas automáticas compran con usuarios `@example.com` (dominio reservado para pruebas, RFC 2606) para no enviar correos a `test.com`, que es un dominio real.

## Bloque 5 — Pruebas (30-oct-2026)

| Conjunto | Archivo | Correctas | Fallos |
|---|---|---|---|
| Núcleo, JWT, permisos, compra Stripe | `pruebas/prueba-bloque1.php` | 41 | 0 |
| Replicación, caché Redis, logout | `pruebas/prueba-bloque2.php` | 15 | 0 |
| APIs externas | `pruebas/prueba-bloque4.php` | 16 | 0 |
| Colección Postman (Newman 6, 35 peticiones) | `docs/pruebas/NodoTec.postman_collection.json` | 48 | 0 |
| Tolerancia (5 escenarios) | `pruebas/prueba-tolerancia.php` | 18 | 0 |
| **Total** | `pruebas/ejecutar-pruebas.sh` | **138** | **0** |

- Reporte: `docs/pruebas/reporte-pruebas.md`; salidas completas: `docs/pruebas/salidas/` (incluye `replicacion-estado.txt` con `SHOW SLAVE STATUS`).
- Herramientas: contenedores `php:8.3-apache`, `postman/newman:6-alpine`, `curlimages/curl:8.10.1` (espera entre escenarios).
- Tiempos medidos en tolerancia: compra con notificaciones caído ≈ 1–4 s; catálogo con Slave caído ≈ 1 s la primera petición, luego normal.
- No automatizable (probado a mano en el navegador): tarjeta 4242 escrita en Stripe Elements y aprobación en la ventana de PayPal sandbox.

## Bloque 6 — Despliegue en el VPS de Hostinger (30-oct-2026)

### Datos del despliegue
| Dato | Valor |
|---|---|
| URL de la tienda | https://nodotec.tech |
| URL del panel admin | https://nodotec.tech/admin/ |
| Servidor | VPS de Hostinger · Ubuntu 24.04.5 LTS · 1 vCPU · 3.8 GB RAM (+ 2 GB swap) · 48 GB disco |
| IP pública | 179.197.91.34 (IPv6 `2a02:4780:95:c2c0::1`) |
| DNS (Hostinger) | `A nodotec.tech → 179.197.91.34`, `AAAA → IPv6 del VPS`, `www` → `nodotec.tech` (ya estaban correctos) |
| Software | Docker 29.9.2 + Docker Compose v5.6.1 (repositorio oficial), UFW 0.36, certbot 3.0.1 (imagen `certbot/certbot:v3.0.1`) |
| Carpeta | `/opt/proyecto-ecommerce` (clon del repositorio de GitHub) |
| Certificado | Let's Encrypt para `nodotec.tech` y `www.nodotec.tech`, válido hasta 28-ene-2027, renovación automática diaria (cron) |

### Procedimiento (para la sección 14 del informe)
1. Instalar Docker Engine y el plugin Compose desde el repositorio oficial de Docker.
2. Firewall UFW: denegar entrada por defecto; permitir 22, 80 y 443 (IPv4 e IPv6).
3. SSH: `/etc/ssh/sshd_config.d/01-nodotec.conf` con `PasswordAuthentication no`, `KbdInteractiveAuthentication no`, `PermitRootLogin prohibit-password` (va antes de `50-cloud-init.conf`, que traía `PasswordAuthentication yes`).
4. `git clone` en `/opt/proyecto-ecommerce` y `.env` de producción (permisos 600) con claves nuevas generadas con `openssl rand` y `APP_URL=https://nodotec.tech`; las claves de las APIs externas (modo prueba/sandbox) se copiaron sin mostrarse.
5. `docker compose up --build -d` con `NGINX_CONF=nginx.conf` (HTTP).
6. `docker compose run --rm certbot certonly --webroot -w /var/www/certbot -d nodotec.tech -d www.nodotec.tech` (reto HTTP-01).
7. `NGINX_CONF=nginx-https.conf` y `docker compose up -d gateway`.
8. Pruebas contra la URL pública.

### Cambios en el código
- `nginx/rutas.conf` (nuevo): todas las `location` del Gateway; lo incluyen `nginx.conf` (HTTP, local) y `nginx-https.conf` (nuevo, producción: 80 → 301 a HTTPS, `www` → dominio principal, TLS 1.2/1.3, HTTP/2).
- `docker-compose.yml`: el Gateway publica también `${GATEWAY_HTTPS_PORT:-443}`; la configuración se elige con `${NGINX_CONF:-nginx.conf}`; volúmenes `./certbot/conf` y `./certbot/www`; servicio `certbot` con `profiles: ["certbot"]` (no arranca con `up`). Local sigue igual.
- `.gitignore`: `certbot/`. `.env.example`: `NGINX_CONF`, `GATEWAY_HTTPS_PORT`.
- `pruebas/ejecutar-pruebas.sh`: variable `GATEWAY` para probar contra la URL pública (por defecto `http://gateway`).
- No hay rutas nuevas ni cambios en servicios, BD o frontend. PayPal usa la ventana del JS SDK (sin URL de retorno) y Stripe.js no necesita configuración por dominio; la única URL pública del sistema es `APP_URL`.

### Evidencias (30-oct, contra https://nodotec.tech)
- Batería completa (una sola ejecución, por el límite de EmailJS): **138 correctas, 0 con fallo** — bloque 1 41/41, bloque 2 15/15, bloque 4 16/16, Newman 48/48, tolerancia 18/18; `Slave_IO_Running: Yes`, `Slave_SQL_Running: Yes`, `Seconds_Behind_Master: 0`. Reporte: `docs/pruebas/produccion/reporte-pruebas.md`.
- QR y enlace de seguimiento: `https://nodotec.tech/seguimiento.html?pedido=N`.
- Redirecciones: `http://nodotec.tech/` → 301 `https://nodotec.tech/`; `https://www.nodotec.tech/…` → 301 `https://nodotec.tech/…`; `/api/auth/verify` → 403.
- Navegador: tienda y panel cargan por HTTPS sin errores en consola; 14/14 imágenes de Cloudinary.
- Puertos: solo `gateway` publica (80, 443); SSH con contraseña rechazado (`Permission denied (publickey)`).
