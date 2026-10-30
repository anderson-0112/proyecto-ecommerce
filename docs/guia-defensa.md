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

## Bloque 2 — Replicación Master-Slave y Redis

### Replicación (cómo funciona y cómo se demuestra)
- **Master** (`mariadb-master`, `--server-id=1 --log-bin --binlog-format=ROW`): recibe TODAS las escrituras y las anota en el **binlog** (log binario).
- **Slave** (`mariadb-slave`, `--server-id=2 --read-only=1`): al arrancar ejecuta `CHANGE MASTER TO MASTER_HOST='mariadb-master', MASTER_USER='replicador'…` (lo hace la imagen oficial con las variables `MARIADB_MASTER_HOST` y `MARIADB_REPLICATION_*`). Dos hilos: **IO** descarga el binlog del Master a su *relay log*; **SQL** lo vuelve a ejecutar.
- Como el binlog del Master registra también `init-master.sql` y `crear-usuarios.sh`, el Slave obtiene el esquema, los 20 productos y los usuarios de servicio sin ejecutar ningún script propio.
- `read-only=1`: los usuarios de servicio no pueden escribir en el Slave; solo el hilo de replicación.
- **Quién lee del Slave:** catalogo-service (todas las lecturas públicas) y el listado de pedidos del admin, que alimenta los reportes del dashboard (D9). El cliente lee sus pedidos del Master para ver su compra al instante (sin retraso de réplica).
- **Demostración:** `docker exec -it mariadb-slave mariadb -uroot -p -e "SHOW SLAVE STATUS\G"` debe mostrar `Slave_IO_Running: Yes`, `Slave_SQL_Running: Yes`, `Seconds_Behind_Master: 0`. Luego crear un producto en el admin (va al Master) y verlo en la tienda (se lee del Slave). `GET /api/catalogo/health` muestra `"lecturas_desde": "Slave (server_id=2)"`.
- **Si cae el Slave:** `conexion('DB_READ_HOST')` falla en 1 s y se usa el Master; se guarda en Redis la marca `caido:mariadb-slave` por 15 s para que las peticiones siguientes no esperen. Health muestra `Master (server_id=1)`.
- **¿Por qué ambos en el puerto 3306?** Cada contenedor tiene su propia IP en la red de Docker, así que no chocan; el 3307 del enunciado sería el puerto en una sola máquina. Ninguno se publica al exterior.

### Redis (para qué se usa)
1. **Caché del catálogo** (`catalogo-service`, función `responderConCache`): la primera lectura va al Slave y se guarda 30 s (`X-Cache: MISS`); las siguientes salen de Redis (`X-Cache: HIT`). Al crear/editar/borrar un producto o descontar stock se incrementa `catalogo:version`; las claves llevan la versión en el nombre, así que la caché vieja deja de usarse al instante y expira sola.
2. **Tokens revocados** (`auth-service`): `POST /api/auth/logout` guarda `revocado:<jti>` con caducidad igual al tiempo que le quedaba al token. `verify` rechaza (401) un token cuyo `jti` esté en esa lista. Así un JWT (que no se puede "borrar") deja de servir antes de expirar.
- Redis tiene contraseña (`REDIS_PASSWORD`), no publica puerto y guarda en disco (AOF) para que los revocados sobrevivan a un reinicio.
- **Si cae Redis:** el catálogo responde igual desde la BD (`X-Cache: SIN-REDIS`), las compras siguen y el logout responde 503. La revocación no se puede comprobar: se registra en el log y se acepta el token (se prioriza que la tienda siga operando; riesgo académico documentado).
- **Sesiones:** no se guardan en Redis porque la autenticación es con JWT (sin sesión en el servidor).

### `dns_opt timeout:1`
Cuando un contenedor está detenido, su nombre deja de existir en el DNS de Docker y la búsqueda tardaba ~8 s, más que los timeouts entre servicios. Con `timeout:1, attempts:1` la falla se detecta en 1 s y el servicio aplica su plan B.

### Preguntas probables (Bloque 2)
1. **¿Qué pasa si escribo directamente en el Slave?** Los usuarios de servicio reciben error por `read-only`; si se forzara con root, el Slave quedaría distinto del Master: por eso solo se escribe en el Master.
2. **¿Qué es el retraso de réplica?** El tiempo entre que el Master escribe y el Slave aplica el cambio (`Seconds_Behind_Master`). En local son milisegundos; la prueba lo mide (≈ 0.01 s).
3. **¿Por qué invalidar con versión y no borrar claves?** Borrar exigiría buscar todas las claves de listados con distintos filtros; subir la versión las invalida todas con una sola operación atómica (`INCR`).

### Comandos para la demo (Bloque 2)
```bash
docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque2.php
docker compose stop mariadb-slave
docker compose start mariadb-slave
docker compose stop redis
docker compose start redis
```

## Bloque 3 — Tienda pública y panel de administración

### Páginas (todas consumen el API Gateway con `fetch`)
| Página | Qué hace | Archivo |
|---|---|---|
| `/` | Destacados, catálogo con filtros (categoría, precio máximo) y paginación, detalle en ventana modal. **Sin registro** | `index.html`, `js/tienda.js` |
| `/login.html` | Ingresar y crear cuenta (registro + login automático) | `login.html` |
| `/checkout.html` | Dirección + tarjeta (Stripe Elements) → pedido; confirmación con QR | `checkout.html`, `js/checkout.js` |
| `/mis-pedidos.html` | Historial del cliente | `mis-pedidos.html` |
| `/seguimiento.html?pedido=N` | Línea de estados, productos y QR. Es el destino del QR | `seguimiento.html` |
| `/admin/` | Dashboard (Chart.js), pedidos (detalle, cambio de estado, `mailto:`), CRUD de productos, PDF | `admin/index.html`, `js/admin.js` |

Archivos comunes: `js/api.js` (llamadas al API con el JWT, carrito, formato, `esc()` contra XSS), `js/layout.js` (barra, carrito lateral, pie), `css/nodotec.css`.

### Cómo se explica
- **¿Dónde se guarda la sesión?** En `localStorage` del navegador (`nodotec_sesion`: token + usuario). Cada petición lleva `Authorization: Bearer <token>`. "Salir" llama a `POST /api/auth/logout` (revoca el token en Redis) y borra la sesión local.
- **¿Dónde está el carrito?** En `localStorage` (`nodotec_carrito`). Solo guarda ids y cantidades útiles; el **precio final lo calcula el servidor** (pedidos pide los precios a catálogo).
- **Checkout:** `stripe.createPaymentMethod` (la tarjeta va directo a Stripe) → `POST /api/pedidos` con `pm_…`. Si la tarjeta se rechaza (422), el pedido queda `pendiente` y el siguiente intento usa `POST /api/pagos/stripe` con el mismo `id_pedido` (D2), sin crear otro pedido. Si falta stock (409) se abre el carrito para ajustarlo (D3).
- **Dashboard:** toma `GET /api/pedidos` (admin, leído del **Slave**) y calcula en el navegador: ventas de hoy, últimos 7 días y mes (pedidos `pagado` o posteriores), barras de ventas por día (14 días) y dona de pedidos por estado. Las fechas de la BD están en UTC y se muestran en hora local.
- **Cambio de estado:** el botón solo ofrece el siguiente estado permitido (D1); el servidor lo vuelve a validar (409 si se intenta saltar).
- **Comunicarse con el cliente (D12):** enlace `mailto:` con asunto "NodoTec — Tu pedido #N" y saludo prellenado.
- **CRUD:** crear/editar con `POST`/`PUT /api/catalogo/productos`; eliminar es baja lógica (`DELETE` → `activo = 0`). Cada cambio invalida la caché de Redis. La imagen se ingresa como URL (la subida a Cloudinary llega en el Bloque 4).
- **PDF (D19):** jsPDF + autoTable genera en el navegador el reporte de ventas del mes (pedido, fecha, cliente, estado, total y suma).
- **Seguridad en el frontend:** todo dato de la BD se escapa con `esc()` antes de insertarlo como HTML (evita XSS si alguien guarda `<script>` en un nombre). Esconder botones no da permisos: el servidor siempre verifica el rol.
- **Responsive:** grilla de Bootstrap (2 columnas en móvil, 4 en escritorio); en el admin las pestañas bajan a una segunda fila en pantallas pequeñas. Probado a 375 px sin desplazamiento horizontal.

### Preguntas probables (Bloque 3)
1. **¿Qué pasa si un cliente entra a `/admin/`?** Ve el formulario de acceso; si ingresa con una cuenta cliente, el panel la rechaza, y aunque manipulara el JavaScript, el servidor responde 403 a toda acción de admin.
2. **¿Y si alguien cambia el precio en `localStorage`?** No sirve: el servidor ignora el precio del carrito.
3. **¿Por qué el dashboard calcula en el navegador?** Usa el historial que ya expone `GET /api/pedidos` (leído del Slave) sin agregar rutas nuevas; para el volumen del proyecto es suficiente.

### Demo (Bloque 3)
Tienda `http://localhost/` → agregar productos → Ir a pagar → login `cliente@test.com / Test2026!` → tarjeta 4242 4242 4242 4242 · 12/29 · 123 → confirmación con QR → Ver seguimiento. Admin `http://localhost/admin/` con `admin@mitienda.com / Admin2026!` → dashboard → Pedidos → Ver → "Pasar a preparando" → Contactar al cliente → Productos → editar → Exportar PDF.

## Bloque 4 — APIs externas

| API | Dónde | Cómo se integra | Dónde se ve |
|---|---|---|---|
| **Stripe** | pagos-service (`src/stripe.php`) + Stripe.js | El navegador tokeniza la tarjeta (`pm_…`); el servidor crea y confirma un PaymentIntent con la clave secreta | Checkout → "Tarjeta" |
| **PayPal Sandbox** | pagos-service (`src/paypal.php`) + SDK JS de PayPal | OAuth (Client ID + Secret → token); **dos pasos**: crear orden (servidor) → el comprador aprueba en la ventana de PayPal → capturar (servidor) | Checkout → "PayPal" |
| **EmailJS** | notificaciones-service | API REST `POST https://api.emailjs.com/api/v1.0/email/send` con `service_id`, `template_id`, `user_id` (Public Key), `accessToken` (Private Key) y las variables de la plantilla | Correo al cliente; la confirmación dice "Correo de confirmación enviado" |
| **Cloudinary** | catalogo-service (`src/cloudinary.php`) | Subida **firmada**: `signature = SHA1(parámetros ordenados + API_SECRET)`; la URL entregada usa `f_auto,q_auto,w_600` | Todas las fotos de la tienda; admin → producto → "Imagen" |
| **OpenWeatherMap** | pedidos-service (`GET /api/pedidos/clima`) | `GET /data/2.5/weather?lat&lon&units=metric&lang=es`; respuesta guardada 10 min en un archivo temporal | Dashboard admin (tarjeta de clima) |
| **QR Code API** | pedidos-service | URL `https://api.qrserver.com/v1/create-qr-code/?data=<enlace de seguimiento>` | Confirmación de compra, seguimiento, detalle admin |

### Puntos para explicar
- **Ningún secreto llega al navegador.** El navegador solo recibe datos públicos (`GET /api/pagos/config`: clave publicable de Stripe y Client ID de PayPal). Clave secreta de Stripe, secreto de PayPal, Private Key de EmailJS, secreto de Cloudinary y la key de OpenWeather viven en `.env` y solo los usan los servicios.
- **PayPal y el diagrama de secuencia:** `POST /api/pedidos` con `metodo_pago: "paypal"` → pedidos verifica stock, crea el pedido y **llama a pagos** (`POST /api/pagos/paypal`), que crea la orden (202 + `paypal_orden_id`). El SDK abre la ventana; cuando el comprador aprueba, el navegador llama `POST /api/pagos/paypal` con `orden_id` y pagos **captura**; después, igual que con Stripe: pedido `pagado`, stock descontado, correo. Sin aprobación PayPal responde `ORDER_NOT_APPROVED` y no se cobra (422).
- **Idempotencia:** `PayPal-Request-Id` (PayPal) e `Idempotency-Key` (Stripe) evitan cobrar dos veces si una petición se repite.
- **Correo y tolerancia (D15):** EmailJS tiene 2.5 s de timeout y pagos espera máximo 3 s a notificaciones. Si EmailJS o notificaciones fallan, la compra termina igual y la respuesta lo dice.
- **Cloudinary:** las 20 fotos (Wikimedia Commons, licencias libres) se subieron con `src/migrar-imagenes-cloudinary.php` y `db/init-master.sql` ya trae las URLs del CDN. Al subir una imagen nueva, el servidor revisa el **contenido real** (`finfo`), no la extensión, y limita a 5 MB.
- **Clima:** ciudad configurable en `.env` (`CLIMA_CIUDAD`, `CLIMA_LAT`, `CLIMA_LON`); solo el admin lo consulta (403 para clientes). La caché de 10 min cuida el límite gratuito.

### Preguntas probables (Bloque 4)
1. **¿Por qué EmailJS desde el servidor y no desde el navegador?** Para que la Private Key no quede expuesta y para que el correo dependa del pago confirmado, no de lo que haga el navegador.
2. **¿Qué pasa si el comprador cierra la ventana de PayPal?** El pedido queda `pendiente` y la página ofrece reintentar sobre el mismo pedido.
3. **¿Cómo sabe Cloudinary que la subida es nuestra?** Por la firma SHA-1 calculada con el API Secret; sin el secreto no se puede generar.

### Demo PayPal
Checkout → "PayPal" → botón amarillo → iniciar sesión con la cuenta compradora sandbox → "Completar compra" → confirmación "pagado" con QR.

## Bloque 5 — Pruebas

- **Un solo comando** (Git Bash en Windows o Linux en el VPS):
  ```bash
  bash pruebas/ejecutar-pruebas.sh
  ```
  Corre las pruebas de los bloques 1, 2 y 4, la colección Postman con Newman, revisa `SHOW SLAVE STATUS` y ejecuta 5 escenarios de **tolerancia a fallos** deteniendo un contenedor a la vez. Escribe `docs/pruebas/reporte-pruebas.md` y las salidas en `docs/pruebas/salidas/`. Resultado actual: **138 correctas, 0 con fallo**.
- **¿Por qué en contenedores?** Las pruebas se ejecutan en `php:8.3-apache` (y Newman en `postman/newman`) conectados a la red `ecommerce`: no hace falta instalar PHP ni Node en el equipo, y prueban el sistema a través del Gateway, igual que un navegador.
- **Tolerancia demostrada (criterio 10):**

  | Contenedor detenido | Qué sigue funcionando | Qué responde lo afectado |
  |---|---|---|
  | notificaciones-service | Catálogo y compra completa (`pagado`) | "No se pudo enviar el correo: servicio de notificaciones no disponible" |
  | pagos-service | Catálogo, login | 503 y el pedido queda `pendiente` para reintentar |
  | catalogo-service | Login, historial de pedidos | Gateway 503 en JSON; no se crea el pedido |
  | mariadb-slave | Catálogo (lee del Master) y compras | — |
  | redis | Catálogo sin caché (`X-Cache: SIN-REDIS`), login y compras | Logout 503 |
- **Colección Postman/Thunder Client:** `docs/pruebas/NodoTec.postman_collection.json`. Importarla, dejar `base_url = http://localhost` (o la URL del VPS) y ejecutar en orden: guarda sola los tokens e ids.
- **Correos en las pruebas:** los compradores automáticos usan `@example.com` (dominio reservado para pruebas) para no escribir a buzones reales.

### Demo de tolerancia (en vivo)
```bash
docker compose stop notificaciones-service
```
Hacer una compra en la tienda: termina en `pagado` y avisa que el correo no se envió. Luego:
```bash
docker compose start notificaciones-service
```

## Bloque 6 — Despliegue en el VPS de Hostinger (nodotec.tech)

- **URLs:** tienda https://nodotec.tech · panel https://nodotec.tech/admin/ (HTTP y `www` redirigen con 301 a `https://nodotec.tech`).
- **Mismo `docker-compose.yml` que en local.** Lo único que cambia es el `.env` del servidor: claves de BD, Redis y JWT **nuevas y aleatorias** (`openssl rand`), `APP_URL=https://nodotec.tech` (enlace de seguimiento del correo y del QR) y `NGINX_CONF=nginx-https.conf`.
- **Solo el Gateway publica puertos (80 y 443).** MariaDB, Redis y los 5 servicios no son alcanzables desde Internet: solo por la red interna `ecommerce`.
- **Servidor:** Ubuntu 24.04 · Docker 29.9 + Compose v5.6 (repositorio oficial de Docker) · firewall UFW: solo 22, 80 y 443 · SSH solo con llave (`PasswordAuthentication no`) · swap de 2 GB.
- **HTTPS (Let's Encrypt):**
  1. El Gateway arranca con `nginx.conf` (HTTP), que sirve `/.well-known/acme-challenge/` desde `./certbot/www`.
  2. `docker compose run --rm certbot certonly --webroot ...` → Let's Encrypt pide un archivo por HTTP a `nodotec.tech`, certbot lo deja en esa carpeta y así se prueba que controlamos el dominio (reto HTTP-01).
  3. Con el certificado en `./certbot/conf`, se cambia a `nginx-https.conf` (TLS 1.2/1.3, redirección 80 → 443).
  4. Renovación: cron diario (`/etc/cron.d/nodotec-certbot`) con `certbot renew` + `nginx -s reload`; `renew --dry-run` probado.
- **Rutas sin duplicar:** las `location` del Gateway viven en `nginx/rutas.conf`, incluidas por `nginx.conf` y `nginx-https.conf`.
- **Pruebas en producción:** `GATEWAY=https://nodotec.tech bash pruebas/ejecutar-pruebas.sh` en el VPS → **138 correctas, 0 con fallo** (reporte en `docs/pruebas/produccion/`). Se corrió una sola vez por el límite de 200 correos/mes de EmailJS.

### Preguntas probables (Bloque 6)
1. **¿Por qué un VPS y no Render/Railway?** Aprobado por el catedrático: permite el mismo `docker-compose.yml` con 9 contenedores (dos MariaDB con replicación y Redis), cosa que un PaaS gratuito no da.
2. **¿Cómo se protege la BD en la nube?** No publica puerto; el firewall solo abre 22/80/443; cada servicio tiene su usuario con permisos solo sobre su BD; claves distintas a las de local.
3. **¿Cómo actualizan el servidor?** `ssh root@179.197.91.34` → `cd /opt/proyecto-ecommerce && git pull && docker compose up --build -d`.

### Comandos útiles en el VPS
```bash
ssh root@179.197.91.34
```
```bash
cd /opt/proyecto-ecommerce && docker compose ps
```
```bash
docker exec mariadb-slave sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SHOW SLAVE STATUS\G"' | grep Running
```
