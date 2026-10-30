# Notas de implementación (para el informe)

> Las mantiene Claude Code al cerrar cada bloque. El chat del Proyecto las convierte en texto del informe (secciones 9, 10, 11, 13, 14).

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
- `pruebas/prueba-bloque1.php` → `pruebas/resultado-bloque1.txt`: **41 correctas, 0 con fallo** (30-oct).
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
- Evidencia: `SHOW SLAVE STATUS` → `Slave_IO_Running: Yes`, `Slave_SQL_Running: Yes`, `Seconds_Behind_Master: 0`; producto creado en el Master visible en el Slave en ≈ 0.01 s (`pruebas/resultado-bloque2.txt`).
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
