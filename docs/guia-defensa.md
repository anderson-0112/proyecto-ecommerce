# Guía de defensa — Proyecto Final No. 2

> Se actualiza al cerrar cada bloque. Objetivo: que Anderson pueda explicar **todo** el código.

## Bloque 0 — Base del repositorio

- **¿Por qué `.gitignore` excluye `.env`?** El `.env` guarda contraseñas de BD, el secreto del JWT y las claves de Stripe. Si se sube a GitHub cualquiera podría usarlas. Se entrega `.env.example` con nombres de variables y valores falsos.
- **¿Para qué `.gitattributes`?** Windows usa fin de línea CRLF y Linux (los contenedores) LF. Un script `.sh` con CRLF falla dentro del contenedor (`/bin/sh^M: bad interpreter`). Con `eol=lf` Git siempre guarda esos archivos en LF.

## Bloque 1 — Núcleo distribuido (Docker, Gateway, 5 servicios, JWT, compra con Stripe)

### Qué hace cada pieza

| Contenedor | Puerto interno | Qué hace | Archivo clave |
|---|---|---|---|
| `gateway` (Nginx) | 80 (único publicado) | Entrada única: sirve el frontend y reenvía `/api/<servicio>/` al contenedor que toca. Bloquea rutas internas (403) | `nginx/nginx.conf` |
| `auth-service` | 8081 | Registro (bcrypt), login (emite JWT), `verify` (lo usan los demás) | `auth-service/src/jwt.php`, `public/index.php` |
| `catalogo-service` | 8082 | Productos, categorías, filtros + paginación, CRUD admin, verificar/descontar stock | `catalogo-service/public/index.php` |
| `pedidos-service` | 8083 | Crea el pedido, llama a catálogo y a pagos, estados, historial, QR | `pedidos-service/public/index.php` |
| `pagos-service` | 8084 | Cobra con Stripe, guarda `pi_…`, avisa a pedidos y a notificaciones | `pagos-service/src/stripe.php`, `public/index.php` |
| `notificaciones-service` | 8085 | Recibe el aviso de pago (correo real con EmailJS en el Bloque 4) | `notificaciones-service/public/index.php` |
| `mariadb-master` | 3306 | 4 bases aisladas: `auth_db`, `catalogo_db`, `pedidos_db`, `pagos_db` | `db/init-master.sql`, `db/crear-usuarios.sh` |

**Aislamiento de BD:** cada servicio tiene su propio usuario de MariaDB con permisos solo sobre su base (`crear-usuarios.sh`). pedidos-service no puede leer `auth_db` aunque quisiera; por eso pide los datos al otro servicio por API.

### Cómo viaja el JWT
1. `POST /api/auth/login` → auth-service comprueba la contraseña con `password_verify` (bcrypt) y firma un token HS256 con `JWT_SECRET` (2 h cliente / 8 h admin).
2. El navegador envía `Authorization: Bearer <token>` en cada petición.
3. El servicio que recibe la petición **no conoce el secreto**: llama a `POST /api/auth/verify` (función `usuarioAutenticado()` en `src/lib.php`). Si el token fue alterado o expiró → 401.
4. En las llamadas internas (pedidos → catálogo, pedidos → pagos, pagos → pedidos, pagos → notificaciones) se **reenvía el mismo JWT** del usuario (D7), y el servicio destino lo vuelve a verificar.
- **¿Por qué no se puede falsificar el rol?** La firma es `HMAC-SHA256(encabezado.payload, secreto)`. Si se cambia `"rol":"cliente"` por `"admin"`, la firma ya no coincide (`hash_equals`) → 401. Lo prueba `pruebas/prueba-bloque1.php`.

### Flujo de compra (D-PAGO opción B)
1. El navegador convierte la tarjeta en `pm_…` con **Stripe.js** (el número nunca toca nuestro servidor).
2. `POST /api/pedidos` → pedidos verifica el JWT y llama a `catalogo/interno/verificar-stock`, que devuelve **precios oficiales** (el precio no se acepta del cliente). Sin stock → 409.
3. Guarda cliente, dirección, pedido `pendiente` y detalle en una **transacción**, y hace `COMMIT` antes de seguir (pagos va a consultar ese pedido).
4. Llama a `POST /api/pagos/stripe`. pagos consulta el pedido a pedidos (monto + dueño) y crea un PaymentIntent en Stripe (en centavos, USD, con `Idempotency-Key` para no cobrar dos veces).
5. Aprobado → pagos guarda `referencia_stripe` y llama a `pedidos/interno/{id}/pagado`; pedidos cambia el estado y llama a `catalogo/interno/descontar-stock` (`UPDATE … WHERE stock >= ?`, todo o nada).
6. pagos avisa a notificaciones (timeout 3 s). La respuesta final dice si el correo se envió.

### ¿Por qué `php:8.3-apache` y no `php -S`?
`php -S` atiende **una petición a la vez**. En el paso 4, pedidos-service está esperando a pagos y pagos le hace a pedidos una petición nueva (GET del pedido y luego "marcar pagado"). Con un solo proceso, pedidos nunca respondería: **bloqueo mutuo (deadlock)**. Apache crea varios procesos, así que atiende la segunda petición mientras la primera espera.

### Rutas internas protegidas dos veces
`/api/*/interno/*` y `/api/auth/verify` devuelven 403 si llegan por el Gateway. Además exigen JWT válido. Así un cliente no puede llamar a `PUT /api/pedidos/interno/5/pagado` para "pagar" sin pagar.

### Tolerancia a fallos (ya demostrable)
- **Notificaciones caído:** `docker compose stop notificaciones-service` → la compra termina igual (201, pedido `pagado`) y la respuesta dice "No se pudo enviar el correo: servicio de notificaciones no disponible". pagos usa un timeout corto y no detiene la compra (D15).
- **Gateway:** usa `resolver` + variables en `proxy_pass`, así que arranca y sigue funcionando aunque un servicio esté caído; ese servicio responde 503 en JSON.
- **Pagos caído:** el pedido queda `pendiente` (503) y se puede reintentar con `POST /api/pagos/stripe` (D2).

### Preguntas probables del catedrático
1. **¿Dónde se guarda la tarjeta?** En ningún lado nuestro. Stripe.js la tokeniza en el navegador; el backend solo recibe `pm_…` y guarda el `pi_…` del cobro.
2. **¿Qué pasa si el cliente cambia el precio en el navegador?** Nada: el total lo calcula pedidos con los precios que devuelve catálogo, y pagos cobra el total guardado en la BD.
3. **¿Cuántas llamadas inter-servicio con JWT hay?** En una compra: pedidos→auth, pedidos→catálogo (stock), pedidos→pagos, pagos→auth, pagos→pedidos (consulta), pagos→pedidos (pagado), pedidos→catálogo (descontar), pagos→notificaciones, más cada servicio→auth para verificar.
4. **¿Por qué no hay FOREIGN KEY entre `pagos.id_pedido` y `pedidos.id`?** Están en bases distintas de servicios distintos; la relación es lógica y se valida por API.
5. **¿Por qué borrado lógico de productos?** Para no romper el historial: los pedidos antiguos guardan nombre y precio del momento de compra en `detalle_pedidos`.

### Comandos para la demo
```bash
docker compose up --build -d
docker compose ps
docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque1.php
docker compose stop notificaciones-service
docker compose logs notificaciones-service
```
Página de prueba de compra: `http://localhost/prueba-compra.html` (tarjeta 4242 4242 4242 4242 · 12/29 · 123).
