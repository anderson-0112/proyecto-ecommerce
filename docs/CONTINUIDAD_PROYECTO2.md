# CONTINUIDAD — Proyecto Final No. 2: E-Commerce Distribuido (Tienda de Electrónica y Tecnología)

> Archivo de traspaso. Última actualización: **30 de octubre de 2026, 16:25 (hora de Guatemala)** — Bloque 7 en curso: figuras actualizadas e informe **v5**.
> **Fuente de verdad de los requisitos:** `Proyecto2_ECommerce_Distribuido.docx` (enunciado del ingeniero).
> Si este archivo y el enunciado se contradicen, **manda el enunciado**.
> Ubicación: raíz del Proyecto en Claude (Contexto) **y** `docs/CONTINUIDAD_PROYECTO2.md` en el repositorio. Mantener ambas copias iguales (ver sección 12).

---

## 0. Estado en una línea

Análisis ✅ · **Informe v5** (C1–C15 + Bloques 0–6 + 8 figuras insertadas) ✅ · 8 diagramas ✅ (arquitectura incluida, sin `[PENDIENTE]`) · Decisiones D1–D21 y D-PAGO ✅ · **Bloque 0 ✅ · Bloque 1 ✅ (compra 4242 → pedido `pagado`; 41/41 pruebas) · Bloque 2 ✅ (Master-Slave + Redis; 15/15) · Bloque 3 ✅ (tienda NodoTec + panel admin) · Bloque 4 ✅ (7 APIs externas visibles) · Bloque 5 ✅ (138/138 pruebas)** · **Bloque 6 ✅ (https://nodotec.tech y /admin/ con HTTPS; 138/138 en producción)** · Hosting: VPS de Hostinger (`179.197.91.34`).

**Siguiente paso exacto:** **Bloque 7** (chat del Proyecto): guion de demo de 10 min y lista de capturas para la sección 15. Anderson: compra manual en producción con 4242 y con PayPal sandbox, capturas desde https://nodotec.tech, portada (con **NodoTec**) y norma de citación; luego conclusiones (17), referencias (18) y PDF final.

---

## 1. Datos generales

| Dato | Valor |
|---|---|
| Universidad | Universidad Mariano Gálvez de Guatemala — Facultad de Ingeniería en Sistemas de Información |
| Centro | Centro Universitario de Santa Cruz del Quiché |
| Curso | Desarrollo de Aplicaciones Web — PHP, MySQL y Aplicaciones Distribuidas |
| Catedrático | M.Sc. Elmer Rolando Lux Laynez (consultas: erolandolx@gmail.com) |
| Proyecto | Proyecto Final No. 2 — Plataforma E-Commerce Distribuida con Servicios Web |
| Valor | 15 puntos |
| **Fecha de entrega** | **31 de octubre de 2026** (−1 punto por cada día de atraso) |
| Tipo de negocio | **Opción C: Tienda de Electrónica y Tecnología** — ✅ confirmado por Anderson que **no repite** el negocio del Proyecto 1 |
| Tienda | **Ficticia**. Nombre comercial: **NodoTec** (definido por Anderson; "cada servicio es un nodo independiente") |
| Equipo | Integrantes: **Anderson Roberto Ixcotoyac López** y **Abdy Baltazar Aguare Santiago** (ambos se mencionan en el informe, 2.2). Desde el 29-oct, Anderson realiza el código y la documentación. |
| Portada del informe | **La elabora Anderson personalmente.** No tocarla. |
| Entorno de Anderson | Windows, VS Code, PowerShell, Docker Desktop, GitHub `anderson-0112` (2FA) |
| Repositorio | https://github.com/anderson-0112/proyecto-ecommerce.git (rama `main`) |
| Carpeta local | `proyecto-ecommerce` (entorno seleccionado en Claude Code) |

---

## 2. Requisitos del enunciado (resumen fiel)

### 2.1 Arquitectura (sección 3 del enunciado)

| Componente | Responsabilidad | Tecnología | Puerto |
|---|---|---|---|
| API Gateway | Punto único de entrada, reverse proxy, balanceo | Nginx Alpine | 80/443 |
| auth-service | Registro, login, JWT, roles cliente/admin | PHP 8+ + auth_db | 8081 |
| catalogo-service | Productos, categorías, imágenes (Cloudinary), stock | PHP 8+ + catalogo_db | 8082 |
| pedidos-service | Carrito, pedidos, estado de entrega, historial | PHP 8+ + pedidos_db | 8083 |
| pagos-service | Checkout, Stripe/PayPal, confirmación de pago | PHP 8+ + pagos_db | 8084 |
| notificaciones-service | Emails (EmailJS), SMS opcional | PHP 8+ / Node | 8085 |
| MariaDB Master | Todas las escrituras | MariaDB 10.6 | 3306 |
| MariaDB Slave | Lecturas de catálogo y reportes | MariaDB 10.6 | 3307 |
| Redis | Caché de catálogo, sesiones, tokens JWT revocados | Redis 7 | 6379 |

**Flujo de compra (sección 3.1):** cliente navega catálogo (`GET /api/catalogo/productos` → Slave) → `POST /api/pedidos` → pedidos verifica stock en catálogo → pedidos llama a pagos → `POST /api/pagos/stripe` → Stripe → pago confirmado → pagos notifica a pedidos (estado: pagado) → notificaciones envía email (EmailJS).

### 2.2 Endpoints definidos (sección 6)

- **auth:** `POST /api/auth/register` (nombre, email, password bcrypt) · `POST /api/auth/login` (JWT con rol) · `POST /api/auth/verify` (interno). Token: **2 h cliente / 8 h admin**.
- **catálogo:** `GET /api/catalogo/productos?categoria=&precio_max=` (filtros + **paginación**) · `GET /api/catalogo/productos/{id}` · `GET /api/catalogo/destacados` · POST/PUT/DELETE **solo admin** (JWT verificado vía auth). Lecturas desde **Slave**.
- **pedidos:** `POST /api/pedidos` (verifica stock en catálogo) · `GET /api/pedidos/{id}` · `PUT /api/pedidos/{id}/estado` (pendiente, preparando, enviado, entregado) · llama a pagos al confirmar · genera **QR** (API externa).
- **pagos:** `POST /api/pagos/stripe` · `POST /api/pagos/paypal` · `GET /api/pagos/{id}/estado` · al confirmar notifica a pedidos y notificaciones · guarda `transaction_id` de Stripe.
- **Panel admin (6.5):** dashboard Chart.js (ventas día/semana/mes, pedidos por estado) · gestión de pedidos (cambiar estado, ver detalle, **comunicarse con cliente**) · CRUD catálogo con imágenes a Cloudinary · **clima del día** (OpenWeatherMap) · **exportar reporte de ventas a PDF**.

### 2.3 Bases de datos (sección 7)

| Servicio | BD | Tablas definidas por el enunciado |
|---|---|---|
| auth | auth_db | usuarios (id, nombre, email, password_hash, rol, created_at) |
| catálogo | catalogo_db | categorias; productos (id, nombre, precio, stock, imagen_url, destacado, activo) |
| pedidos | pedidos_db | clientes; pedidos (id, id_cliente, total, estado, fecha); detalle_pedidos; direcciones |
| pagos | pagos_db | pagos (id, id_pedido, monto, metodo, referencia_stripe, estado, fecha_pago) |

Replicación: Master recibe escrituras; **catálogo lee del Slave**. Script `db/init-master.sql` con **mínimo 20 productos con imágenes reales**.

### 2.4 Tecnologías requeridas (sección 4)

Frontend público HTML5/CSS3/JS/**Bootstrap 5** (consume el Gateway) · Admin Bootstrap + **Chart.js** · Backend **PHP 8+** (un proceso por servicio) · MariaDB 10.6+ (BD aislada por servicio) · Master-Slave · Redis · Docker + docker-compose (un contenedor por servicio) · Nginx gateway · JWT firmado · Cloudinary · mínimo 3 APIs externas · Hosting **Render.com / Railway**.

### 2.5 Estructura exigida (sección 8)

```
proyecto-ecommerce/
├── docker-compose.yml
├── nginx/nginx.conf
├── auth-service/
├── catalogo-service/
├── pedidos-service/
├── pagos-service/
├── notificaciones-service/
├── db/init-master.sql      (schema + 20 productos mínimo)
└── frontend/               (tienda pública + admin)
```
Comando: `docker-compose up --build -d`.

### 2.6 Entregables obligatorios (sección 9)

1. URL pública de la tienda (catálogo visible **sin registro**).
2. URL del panel admin con usuario y contraseña de prueba.
3. Repositorio GitHub con **todos** los microservicios, `docker-compose.yml` y README.
4. Scripts SQL de cada BD con mínimo 20 productos con imágenes reales.
5. Documento PDF: portada, diagrama de arquitectura, endpoints de cada servicio, capturas.
6. Presentación de 10 minutos: demo del flujo completo de compra y comunicación entre servicios.
7. Credenciales de prueba: admin `admin@mitienda.com / Admin2026!`, cliente `cliente@test.com / Test2026!`, tarjeta Stripe `4242 4242 4242 4242 / 12/29 / 123`.

### 2.7 Rúbrica (15 pts) — lo que da "Excelente"

| # | Criterio | Excelente | Pts |
|---|---|---|---|
| 1 | Arquitectura distribuida | **5 servicios** + Nginx enrutando + Redis activo | 2 |
| 2 | Comunicación inter-servicio | **3+ llamadas** inter-servicio con **JWT verificado** | 2 |
| 3 | Flujo de compra | End-to-end, pago Stripe/PayPal, **email** de confirmación | 2 |
| 4 | APIs externas | **3+ integradas y visibles** | 2 |
| 5 | Replicación + Docker | Master-Slave **activa** + `docker-compose up` funcional | 2 |
| 6 | Panel admin | Gráficas, CRUD, estados de pedido | 1.5 |
| 7 | Hosting | Todo en la nube, BD remota, URL pública | 1 |
| 8 | Diseño y UX | Profesional, responsive, imágenes en Cloudinary | 0.5 |
| 9 | Presentación y documentación | Demo sin errores, explica cada servicio y API, README detallado | 1 |
| 10 | Tolerancia a fallos | Con un servicio caído el sistema sigue (ej.: sin notificaciones, catálogo sigue) | 1 |

**Penalizaciones:** monolítico −5 · sin pagos (Stripe/PayPal) −2 · sin Docker −2 · sin repo GitHub −2 · mismo negocio que Proyecto 1 −2 · atraso −1/día · **plagio o código que no se puede explicar → nota 0**.

> ⚠️ La regla de "nota 0 por no poder explicar" obliga a que **Anderson entienda todo el código**. Ver sección 9 (guía de defensa).

---

## 3. Decisiones ya aprobadas (no cambiar sin justificación)

| # | Decisión | Origen |
|---|---|---|
| A1 | Negocio: Opción C, tienda **ficticia**; no repite el Proyecto 1 | Anderson |
| A2 | APIs externas: **Stripe + PayPal + EmailJS + Cloudinary + QR Code API (api.qrserver.com) + OpenWeatherMap** | Anderson |
| A3 | **PayPal es obligatorio** (confirmado con el catedrático) — se implementa junto con Stripe | Anderson |
| A4 | Fuera del alcance: **SMS, Google Maps, CurrencyFreaks** | Anderson |
| A5 | Se mantiene el actor **Visitante** (catálogo sin registro) | Anderson |
| A6 | El ingeniero **sí pide informe formal**, además de lo técnico | Anderson |
| A7 | Estructura del informe de 19 secciones (sección 4) | Anderson |
| A8 | Diagramas UML/ER aprobados (sección 5) | Anderson |
| A9 | ~~Railway~~ (suspendió registros nuevos tras su incidente del 21-oct) y ~~Render~~ (BD de pago, 9 servicios, arranques en frío) → **VPS de Hostinger KVM 1, 1 mes** (autorizado por el catedrático por WhatsApp el 30-oct, 09:50; Anderson guarda captura). Se despliega con el **mismo `docker-compose.yml`**; solo el Gateway publica puertos. URLs entre servicios y datos de conexión en variables de entorno con valores de Docker por defecto (`${VAR:-valor}`). Sigue descartado el hosting compartido | Anderson, 30-oct |
| A10 | Código con **Claude Code** + pruebas en **Docker Desktop** antes de desplegar | Anderson |
| A11 | Diagramas como código (Mermaid) cuando sea posible; PNG/SVG para el informe | Aprobado |
| A12 | **Todas las decisiones técnicas D1–D21 y D-PAGO (opción B)** de la sección 6 | Anderson, 29-oct |
| A13 | Datos semilla: 20 productos de **marcas/modelos reales**, precios en **USD**, fotos con **licencia libre** por URL pública (migración a Cloudinary en Bloque 4). Claude Code propone la lista y Anderson la revisa antes de cerrar el Bloque 1 | Anderson, 29-oct |
| A14 | División del trabajo: **Claude Code = código**; **chat del Proyecto = documentación** (informe, diagramas, guía de defensa final). Ver sección 12 | Anderson, 29-oct |
| A15 | Cuando un chat se vuelva demasiado largo, generar un **resumen/prompt de continuidad** para seguir en un chat nuevo (sección 12) | Anderson, 29-oct |
| A16 | Nombre comercial: **NodoTec** | Anderson, 30-oct |
| A17 | 20 productos semilla aprobados (lista en `db/init-master.sql`; créditos de fotos en `docs/creditos-imagenes.md`) | Anderson, 30-oct |
| A18 | Rutas adicionales aprobadas: `GET /api/catalogo/categorias`, `GET /api/pedidos` (historial), `GET /api/pagos/config` (clave pública de Stripe), `/api/<servicio>/health`; rutas internas `/api/*/interno/*` (bloqueadas en el Gateway): `catalogo/interno/verificar-stock`, `catalogo/interno/descontar-stock`, `pedidos/interno/{id}/pagado`, `notificaciones/interno/confirmacion-pedido` | Anderson, 30-oct |
| A19 | El chat del Proyecto **actualiza el informe por iniciativa propia** cuando hay avances: genera la siguiente versión .docx (_v3, _v4…), la entrega, sube su texto al Contexto y actualiza CONTINUIDAD | Anderson, 29-oct |
| A20 | Stripe: cuenta solo en sandbox con país Estados Unidos (Guatemala no está disponible en Stripe); nunca se activa para cobros reales | Anderson, 29-oct |
| A21 | Rutas solo admin aprobadas: `GET /api/pedidos/clima` y `POST /api/catalogo/imagenes` (para no exponer claves de OpenWeather y Cloudinary en el navegador) | Anderson, 30-oct |

---

## 4. Estado del informe (documentación — se lleva en el chat del Proyecto)

**Versión maestra:** `Informe Proyecto Final No. 2 — E-Commerce Distribuido_v5.docx` (30-oct, en `docs/`), que guarda Anderson (fuera del repo: `docs/Informe*.docx` está en `.gitignore`). En el Contexto del Proyecto está su **texto** (el Proyecto solo admite texto). Cada nueva versión se genera sobre la anterior y se entrega como `_v4`, `_v5`… ⚠️ El Claude Doc https://claude.ai/artifact/QD8pPeaP2CxNVP8yfFWPiW está **desactualizado**; no usarlo.

| Sección | Estado (v5) |
|---|---|
| Portada | ⛔ **No tocar** — la hace Anderson. ⚠️ Debe poner ahí el nombre comercial **NodoTec** (campo `[PENDIENTE]` de la portada) |
| 1–6 | ✅ (C1 con ambos integrantes y "NodoTec"; PayPal en 4.2 y 5.2) · ✅ Figura 1 (casos de uso) en 5.2 |
| 7. Alcance | ✅ C7, C8 · 7.2 con limitaciones reales (Redis caído acepta tokens revocados; dirección más reciente) |
| 8. Requerimientos | ✅ RF-19 |
| 9. Tecnologías | ✅ versiones reales (local y producción), PHP 8.3 + Apache, Redis sin sesiones, hosting VPS de Hostinger |
| 10. Arquitectura | ✅ 10.2 (notas de puertos, Apache, Redis), 10.3 (HS256, rutas internas), 10.4 (PayPal, idempotencia), 10.5 (5 escenarios) · ✅ Figuras 2–7 insertadas (arquitectura, autenticación, flujo de compra, estados con D1/D2, checkout, tolerancia) |
| 11. Base de datos | ✅ tipos, llaves, usuarios por servicio, baja lógica, replicación y comprobación, 20 productos / 7 categorías · ✅ Figura 8 (DER con campos finales) |
| 12. APIs | ✅ integración de las 6 APIs |
| 13. Endpoints | ✅ rutas finales, accesos, ejemplo POST /api/pedidos |
| 14. Despliegue | ✅ (v4) estructura real, requisitos, proveedor autorizado, servidor, dominio y certificado, 8 pasos del procedimiento, URLs |
| 15. Evidencias | ✅ resultados de pruebas (138/138 local y en producción) · ⏳ **capturas** (Anderson, en producción) |
| 16. Credenciales | ✅ completa (URLs, admin, cliente, Stripe, PayPal sandbox) |
| 17. Conclusiones | ⏳ Al final |
| 18. Referencias | ⏳ Documentación oficial usada; norma de citación `[PENDIENTE — Anderson]` |
| 19. Anexos | ⏳ Opcional |

---

## 5. Diagramas

Carpeta: `docs/diagramas/` en el repo (copiar desde el paquete). Cada diagrama tiene PNG, SVG, HTML y, cuando aplica, `.mmd`.

Numeración del informe v5 = **orden de aparición** (las figuras anchas van en páginas horizontales).

| Fig. (v5) | Diagrama | Estado | Sección | Archivos |
|---|---|---|---|---|
| 1 | Casos de uso | ✅ `mailto:` (D12) en lugar de "mecanismo PENDIENTE" | 5.2 | `diagrama3_casos_uso/` |
| 2 | **Arquitectura** (obligatorio) | ✅ Aprobado por Anderson (30-oct) | 10.1 | `diagrama0_arquitectura/` (`generar.py`) |
| 3 | Secuencia: autenticación JWT | ✅ Códigos HTTP (D18), Redis/revocados, 503 | 10.3 | `diagrama5_autenticacion/` (.mmd) |
| 4 | Secuencia: flujo de compra | ✅ Sin cambios (D-PAGO opción B) | 10.4 | `diagrama1_secuencia/` (.mmd) |
| 5 | Estados del pedido | ✅ D1/D2, 409 en transiciones no válidas | 10.4 | `diagrama4_estados/` (`generar.py`) |
| 6 | Actividades: checkout | ✅ D3 (409 → carrito) y D2 (reintento); ahora horizontal | 10.4 | `diagrama7_actividades/` (.mmd) |
| 7 | Secuencia: tolerancia a fallos | ✅ D15 (timeout ≈3 s, `correo_enviado: false`) | 10.5 | `diagrama6_tolerancia_fallos/` (.mmd) |
| 8 | DER por microservicio | ✅ Campos y tipos finales, `referencia_paypal`, referencias lógicas | 11.1 | `diagrama2_der/` (`generar.py`) |

Los `.mmd` se renderizan con `docs/diagramas/mermaid-config.json`; arquitectura, estados y DER se generan con su `generar.py` (Python + cairosvg, utilidades en `svglib.py`).

No se hace diagrama de clases (se usa DER). Casos de uso: un solo diagrama.

---

## 6. Decisiones técnicas — ✅ APROBADAS EN BLOQUE (29-oct-2026)

| ID | Tema | Decisión aprobada |
|---|---|---|
| D1 | Estados | `pendiente → pagado → preparando → enviado → entregado`. `pagado` lo pone el sistema; el admin solo avanza al siguiente estado |
| D2 | Pago rechazado | Pedido queda `pendiente`; el cliente reintenta con `POST /api/pagos/stripe` + `id_pedido` (pagos valida el pedido con pedidos-service) |
| D3 | Sin stock | `409 Conflict` con mensaje; el cliente vuelve al carrito |
| D4 | Referencia PayPal | Se mantiene `referencia_stripe` y se **agrega** `referencia_paypal` |
| D5 | Campos no definidos | categorias(id, nombre, descripcion) · productos + `categoria_id`, `descripcion` · clientes(id, id_usuario, nombre, email, telefono) · direcciones(id, id_cliente, direccion, ciudad, telefono) · detalle_pedidos(id, id_pedido, id_producto, nombre_producto, cantidad, precio_unitario, subtotal) |
| D6 | clientes ↔ usuarios | `clientes.id_usuario` = `usuarios.id` (referencia lógica, sin FK) |
| D7 | JWT entre servicios | Cada servicio verifica con `POST /api/auth/verify`; en llamadas internas se **reenvía el JWT del usuario** |
| D8 | Redis | (a) caché de catálogo con TTL corto e invalidación al modificar productos; (b) tokens revocados con **ruta nueva** `POST /api/auth/logout` |
| D9 | Lecturas en Slave | catalogo-service + reportes/dashboard |
| D10 | Llamadas internas | Directas por la red interna (no pasan por el Gateway) |
| D11 | QR | Al pasar a `pagado`: imagen de api.qrserver.com con enlace de seguimiento |
| D12 | Comunicarse con cliente | Botón `mailto:` con asunto prellenado en el detalle del pedido (admin) |
| D13 | EmailJS | Desde notificaciones-service (backend, API REST). **Verificar** en documentación oficial; plan B: desde el frontend |
| D14 | Lenguaje notificaciones | PHP 8 |
| D15 | Falla de notificaciones | Timeout ≈3 s, registrar fallo, **no** detener la compra; la respuesta indica que el correo no se envió |
| D16 | Acceso a pedido/pago | Dueño del pedido o admin (403 en otro caso) |
| D17 | Usuarios semilla | `admin@mitienda.com / Admin2026!` · `cliente@test.com / Test2026!` |
| D18 | Códigos HTTP | 200, 201, **202** (pago que requiere 3D Secure; agregado 30-oct), 204, 400, 401, 403, 404, 409, 422, 500, 503 |
| D19 | Exportar PDF | jsPDF en el panel admin |
| **D-PAGO** | **Quién inicia el cobro** | **Opción B.** El navegador tokeniza la tarjeta con Stripe.js (`payment_method_id`; la tarjeta **nunca** llega al servidor) → `POST /api/pedidos` (productos + método + token) → pedidos verifica stock con catálogo, crea pedido `pendiente` y **llama a pagos** (`/api/pagos/stripe`, reenviando el JWT) → Stripe → pagos guarda la referencia y notifica a pedidos (`pagado`) y a notificaciones. Respeta el enunciado y **no cambia el diagrama de secuencia aprobado**. Si Stripe pide acción adicional (3DS), se devuelve el `client_secret` al frontend. PayPal (dos pasos: crear orden y capturar) se encaja en el Bloque 4 dentro de `POST /api/pagos/paypal` |
| D-PAGO (req.) | Concurrencia | Pedidos espera a pagos mientras pagos llama de vuelta a pedidos → cada servicio debe atender peticiones concurrentes: imágenes **`php:8.x-apache`** (varios procesos), **no** `php -S` |
| D20 | Descuento de stock | Al confirmarse el pago, pedidos llama a catálogo para descontar stock (llamada interna con JWT). Riesgo conocido (académico): carrera entre verificación y descuento; se documenta |
| D21 | Moneda | Precios en **USD** (PayPal no admite GTQ — **verificar** en documentación oficial de Stripe y PayPal antes de implementar) |

**Llamadas inter-servicio con JWT previstas (criterio 2):** catálogo→auth (verify), pedidos→auth, pedidos→catálogo (stock), pedidos→pagos (cobro), pagos→pedidos (estado `pagado`), pagos→notificaciones (correo), pedidos→catálogo (descuento de stock).

---

## 7. Plan de implementación (Claude Code)

**Principio:** primero lo que evita penalizaciones y suma más puntos. Probar cada bloque en Docker Desktop. Commit + push al cerrar cada bloque.

| Bloque | Contenido | Criterio de "terminado" | Rúbrica |
|---|---|---|---|
| **0. Base** | Estructura de 2.5, `.gitignore` (verificar `.env`), `.gitattributes` (`*.sh text eol=lf`), `.env.example`, `CLAUDE.md`, `docs/` (CONTINUIDAD, enunciado, diagramas), README inicial; remoto GitHub conectado | `git status` limpio, sin secretos, push hecho | 9 |
| **1. Núcleo** | docker-compose, Nginx gateway, 5 servicios PHP (`php:8.x-apache`) con JWT (verify), SQL con 4 BD + 20 productos + usuarios semilla, flujo de compra con Stripe test (D-PAGO opción B), descuento de stock, notificaciones con manejo de fallo (envío real en Bloque 4) | `docker compose up --build -d` levanta todo; compra completa con tarjeta 4242 deja el pedido en `pagado` | 1, 2, 3, 5; evita −5 y −2 |
| **2. Datos distribuidos** | Replicación Master-Slave activa (catálogo y reportes leen del Slave), Redis (caché + revocados + logout) | Insertar en Master y leer en Slave; `SHOW SLAVE STATUS` OK; caché visible | 1, 5 |
| **3. Interfaces** | Tienda pública Bootstrap 5 responsive; panel admin (Chart.js, pedidos, CRUD, `mailto:`) | Flujo completo desde el navegador | 6, 8 |
| **4. APIs externas** | QR, OpenWeatherMap, Cloudinary, EmailJS, PayPal sandbox | Cada API visible en pantalla | 3, 4 |
| **5. Pruebas** | Script automatizado (en contenedor) + colección Postman/Thunder; incluye 401, 403, replicación y tolerancia a fallos | Reporte en `docs/pruebas/` | 9, 10 |
| **6. Despliegue VPS Hostinger** | Docker + Compose en el VPS, mismo `docker-compose.yml`, `.env` de producción, solo el Gateway publica puertos (80/443), volúmenes MariaDB | URL pública funcionando; pruebas repetidas en producción | 7 |
| **7. Documentación final** | (Chat del Proyecto) Fig. 1, informe sin `[PENDIENTE]` salvo portada, capturas, PDF | Informe listo | 9 |

**Si falta tiempo, recortar en este orden:** exportar a PDF → detalles visuales → Redis en sesiones (mantener caché y revocados). **Nunca recortar:** Docker, 5 servicios, JWT entre servicios, Stripe, PayPal, email, replicación, hosting.

### 7.1 Notas del VPS de Hostinger (verificar en la documentación de Hostinger antes de aplicar)

- Mismo `docker-compose.yml` que en local; solo cambia el `.env` (claves, `APP_URL`, opcionalmente `GATEWAY_PORT`).
- Solo el Gateway publica puertos; MariaDB, Redis y los servicios quedan en la red interna `ecommerce`.
- Firewall del VPS: abrir solo 22 (SSH), 80 y 443.
- ✅ **Comprado el 30-oct:** KVM 1 (1 vCPU, 4 GB RAM, 50 GB NVMe), 1 mes = 7,99 € (**renueva sola a 17,49 €/mes → desactivar**). IPv4 `179.197.91.34`, Ubuntu 24.04.5 LTS, ubicación EE. UU., sin Docker Manager ni backups de pago.
- Dominio gratis: **`nodotec.tech`** (1 año; renovación 59,99 € → desactivar). DNS A/AAAA al VPS; `www` → dominio principal.
- ✅ **Desplegado (Bloque 6):** proyecto en `/opt/proyecto-ecommerce`; Docker 29.9 + Compose v5.6; UFW (22/80/443); SSH solo con llave; swap 2 GB; `.env` de producción (600) con claves nuevas; HTTPS Let's Encrypt (vence 28-ene-2027, renovación por cron); Gateway elige configuración con `NGINX_CONF` (`nginx.conf` local / `nginx-https.conf` producción; rutas en `nginx/rutas.conf`).
- Acceso: `ssh root@179.197.91.34` con llave ed25519 de la PC de Anderson (`C:\Users\ander\.ssh\id_ed25519`, sin passphrase; probada el 30-oct). La llave privada nunca se comparte.
- ~~Railway~~ descartado el 30-oct (A9).

### 7.2 Riesgos técnicos conocidos

- **CRLF en Windows:** `.gitattributes` con `eol=lf`.
- **Secretos:** solo en `.env` (ignorado). Si una clave aparece en el chat, rotarla.
- **Stripe:** claves de prueba; nunca enviar números de tarjeta al backend.
- **Deadlock entre servicios** si se usa `php -S` (ver D-PAGO req.).
- **Retraso de replicación:** tras un cambio del admin, el Slave puede mostrar datos viejos unos instantes; se invalida la caché y se documenta.

---

## 8. Tareas de Anderson en paralelo (Claude no puede hacerlas)

1. ✅ Claves de Stripe, OpenWeather, Cloudinary, PayPal y EmailJS en `.env` (30-oct). EmailJS: servicio `service_j4mhvpi` (Gmail `anderlopez996@gmail.com`), plantilla "Order Confirmation" con variables `to_email`, `cliente_nombre`, `pedido_id`, `total`, `enlace_seguimiento`; API no-browser activada. **Límite: 200 envíos/mes** → no correr la batería completa de pruebas muchas veces.
2. ✅ Cuenta compradora PayPal sandbox (ficticia): `sb-v6tr653517842@personal.example.com` / `(3:KDi2!`.
3. ✅ VPS y dominio comprados; llave SSH configurada. ⏳ **Desactivar la renovación automática** del VPS y del dominio en Hostinger.
4. ⏳ Portada del informe (con el nombre **NodoTec**).
5. ⏳ Capturas en producción para la sección 15 (lista en el informe).
6. ⏳ Norma de citación para las referencias (sección 18).
7. Comandos manuales: **uno a la vez**, pegar la salida.

---

## 9. Guía de defensa (obligatoria por la regla de "nota 0")

Claude Code mantiene `docs/guia-defensa.md` al cerrar cada bloque: qué hace cada servicio, cómo viaja el JWT y por qué se verifica, por qué `php:8.x-apache` y no `php -S`, cómo funciona y se comprueba la replicación, para qué se usa Redis, cómo se integra cada API externa, cómo se demuestra la tolerancia a fallos. Más 5 preguntas probables del catedrático con respuesta.

**Guion de demo (10 min):** catálogo sin registro → registro/login → carrito → pago Stripe (4242) → correo recibido → QR → admin: dashboard + clima + cambiar estado → detener notificaciones y repetir compra → mostrar replicación.

---

## 10. Checklist de entrega final

- [x] URL pública de la tienda (catálogo sin registro) — https://nodotec.tech
- [x] URL del panel admin + credenciales — https://nodotec.tech/admin/
- [x] Repo GitHub **público** con los 5 servicios, `docker-compose.yml`, README, sin secretos (confirmado por Anderson, 30-oct)
- [x] Scripts SQL con 20+ productos con imágenes reales (`db/init-master.sql`, Cloudinary)
- [ ] PDF del informe (portada de Anderson + diagramas + endpoints + capturas)
- [x] Credenciales: admin, cliente, tarjeta Stripe, cuenta PayPal sandbox (informe §16)
- [x] Evidencias de pruebas (reporte + colección; local y producción)
- [x] Guía de defensa (`docs/guia-defensa.md`) · ⏳ ensayar el guion de demo
- [x] Diagrama de arquitectura y demás figuras insertados (informe v5)
- [ ] Desactivar renovación automática del VPS y del dominio

---

## 11. Registro de cambios y avances

> Agregar **una línea** al terminar cada bloque o al cambiar una decisión: fecha, qué se hizo, qué cambió y por qué.

- 2026-09-23 — Análisis del enunciado, estructura del informe aprobada, informe redactado con `[PENDIENTE]`.
- 2026-09-23 — APIs elegidas; PayPal confirmado como obligatorio; tienda ficticia.
- 2026-09/10 — Diagramas aprobados: secuencia compra, DER, casos de uso, estados, autenticación, tolerancia a fallos; actividades entregado.
- 2026-10-29 — Anderson asume todo el proyecto. Hostinger descartado; Railway elegido. Desarrollo con Claude Code. Archivo de continuidad creado.
- 2026-10-29 — Aprobadas en bloque D1–D19, D-PAGO (opción B: pedidos llama a pagos; tarjeta tokenizada en el navegador; diagrama de secuencia sin cambios), D20 (descuento de stock al pagar) y D21 (USD). Confirmado que el negocio no repite el Proyecto 1. Revisión del .docx: C2–C6, C9, C11–C15 ya aplicadas; pendientes C1 (parcial), C7, C8, C10. Definida la división código (Claude Code) / documentación (chat del Proyecto) y el protocolo de continuidad (sección 12).
- 2026-10-29 — Informe v2: C1 (integrantes Anderson y Abdy + negocio ficticio), C7, C8, C10 y secciones completadas con las decisiones aprobadas. Railway descartado (registros suspendidos); se evaluó Render. Regla A19 (informe por iniciativa propia).
- 2026-10-29 — **Bloque 0 terminado:** repo git inicializado (rama `main`), `.gitignore` (excluye `.env`, verificado con `git check-ignore`), `.gitattributes` (LF para .sh/.php/.sql/.conf/Dockerfile), `.env.example`, README inicial, estructura de carpetas de la sección 2.5; remoto `anderson-0112/proyecto-ecommerce` conectado y push hecho.
- 2026-10-30 — Decisiones nuevas: hosting final **VPS de Hostinger** con el mismo `docker-compose.yml` (A9, reemplaza a Railway; aprobado por el catedrático); nombre **NodoTec** (A16); 20 productos aprobados (A17); rutas adicionales (A18); código **202** agregado a D18.
- 2026-10-30 — **Bloque 1 terminado:** `docker compose up --build -d` levanta gateway (Nginx 1.27), 5 servicios PHP 8.3 + Apache y MariaDB 10.6 (4 BD aisladas, un usuario por servicio, 20 productos, usuarios semilla). JWT HS256 propio en auth-service, verificado por los demás vía `/api/auth/verify`. Compra con tarjeta 4242 desde el navegador (Stripe.js) → pedido `pagado`, `pi_…` guardado, stock descontado, QR generado. Pruebas automáticas `pruebas/prueba-bloque1.php`: 41/41. Tolerancia: con notificaciones detenido la compra termina (201, `pagado`, correo no enviado). Envío real de correo pendiente (Bloque 4).
- 2026-10-30 — Informe `.docx` sacado del repositorio (`git rm --cached`) y `docs/Informe*.docx` agregado a `.gitignore` (sigue en el historial de Git del commit `ed26bd7`).
- 2026-10-30 — **Bloque 2 terminado:** MariaDB Slave (`server-id=2`, `read-only`) replicando del Master por binlog (usuario `replicador`; el Slave copia desde el binlog incluso el esquema y los datos iniciales); catalogo-service y el listado admin de pedidos (reportes, D9) leen del Slave; si el Slave cae se lee del Master (marca de 15 s en Redis). Redis 7 con contraseña y AOF: caché del catálogo (TTL 30 s, invalidación por versión, encabezado `X-Cache`), tokens revocados y `POST /api/auth/logout` (204). Si Redis cae, catálogo y compras siguen. `GET /api/pedidos` acepta `?desde=AAAA-MM-DD`. Redis **no** se usa para sesiones (JWT sin sesión; recorte previsto en la sección 7). Pruebas: bloque 2 15/15, bloque 1 41/41.
- 2026-10-30 — **Bloque 3 terminado:** tienda pública NodoTec (Bootstrap 5, responsive): catálogo sin registro con destacados, filtros y paginación, detalle, carrito en el navegador, registro/login, checkout con Stripe Elements (reintento sobre el mismo pedido si se rechaza, D2; 409 abre el carrito, D3), confirmación con QR, mis pedidos y página de seguimiento (destino del QR). Panel `/admin/`: dashboard Chart.js (ventas día/7 días/mes, ventas por día, pedidos por estado; datos del Slave), gestión de pedidos (detalle, siguiente estado, `mailto:`), CRUD de productos (imagen por URL; Cloudinary en Bloque 4) y PDF con jsPDF. Sin rutas nuevas. Corregida la clave de idempotencia de Stripe (incluye la fecha del pedido). Gateway con `Cache-Control: no-cache`. Probado en navegador (escritorio y 375 px); regresión 41/41 y 15/15.
- 2026-10-30 — **Bloque 4 terminado:** EmailJS desde notificaciones-service (Private Key como `accessToken`, timeout 2.5 s; correo real enviado); PayPal sandbox en dos pasos dentro de `POST /api/pagos/paypal` (crear orden 202 → aprobación en ventana de PayPal → captura), `POST /api/pedidos` acepta `metodo_pago: paypal`; Cloudinary: 20 fotos migradas (URLs del CDN en `init-master.sql`) y subida firmada desde el CRUD (**ruta nueva** `POST /api/catalogo/imagenes`, admin); OpenWeatherMap en el dashboard (**ruta nueva** `GET /api/pedidos/clima`, admin, ciudad configurable en `.env`). Pruebas `prueba-bloque4.php` 16/16; compra PayPal sandbox completa en navegador (pedido `pagado`). Las pruebas automáticas usan compradores `@example.com` para no enviar correos a `test.com`.
- 2026-10-30 — **Bloque 5 terminado:** `pruebas/ejecutar-pruebas.sh` corre todo en contenedores (bloques 1, 2 y 4, colección Postman con Newman, `SHOW SLAVE STATUS` y 5 escenarios de tolerancia deteniendo notificaciones, pagos, catálogo, Slave y Redis) y genera `docs/pruebas/reporte-pruebas.md` + `docs/pruebas/salidas/`. Colección `docs/pruebas/NodoTec.postman_collection.json` (35 peticiones, 48 aserciones). Resultado: **138 correctas, 0 con fallo**. Las salidas sueltas `pruebas/resultado-*.txt` se reemplazaron por `docs/pruebas/salidas/`.
- 2026-10-30 — VPS Hostinger KVM 1 comprado (`179.197.91.34`, Ubuntu 24.04 LTS), dominio `nodotec.tech`, acceso SSH con llave. Rutas `GET /api/pedidos/clima` y `POST /api/catalogo/imagenes` aprobadas (A21). Copias de CONTINUIDAD del repo y del Proyecto unificadas (A19–A21 renumeradas).
- 2026-10-30 — Informe v3: incorporados los Bloques 0–5 (versiones, notas de arquitectura, 5 escenarios de tolerancia, tipos y llaves, replicación, integración de las 6 APIs, rutas finales con ejemplo, requisitos de despliegue, resultados 138/138, cuenta PayPal sandbox). Pendiente v4: despliegue, URLs, figuras y capturas.
- 2026-10-30 — **Bloque 6 terminado:** desplegado en el VPS de Hostinger (Ubuntu 24.04, 179.197.91.34) con el mismo `docker-compose.yml`: **https://nodotec.tech** y **/admin/**. DNS ya correcto (A y AAAA al VPS). Docker 29.9 + Compose v5.6 desde el repositorio oficial, UFW (22/80/443), SSH solo con llave, swap 2 GB. `.env` de producción con claves nuevas (`openssl rand`) y `APP_URL=https://nodotec.tech`. HTTPS Let's Encrypt (HTTP-01, certbot en contenedor con perfil; renovación por cron, `--dry-run` OK; vence 28-ene-2027). Gateway: rutas movidas a `nginx/rutas.conf`; `nginx-https.conf` nuevo (80→301 HTTPS, `www`→dominio); compose publica 443 y elige config con `NGINX_CONF`. `ejecutar-pruebas.sh` acepta `GATEWAY`. Batería completa una vez contra la URL pública: **138/138** (`docs/pruebas/produccion/`).
- 2026-10-30 — Informe v4: sección 14 completa (estructura real, servidor, dominio y certificado, 8 pasos, URLs), URLs en §16, pruebas en producción en §15.4; quitado el resaltado amarillo de títulos sin pendientes y alineado el bloque de código de 14.1.
- 2026-10-30 — CONTINUIDAD unificado subido al repo por Anderson (commit + push OK). Repositorio confirmado como público. El chat del Proyecto se continúa en uno nuevo (chat largo, A15).
- 2026-10-30 — **Bloque 7 (parte 1):** diagrama de arquitectura aprobado por Anderson. Figuras actualizadas con las decisiones aprobadas: casos de uso (D12), estados (D1/D2), DER (D4–D6, tipos finales), autenticación (D18, Redis), tolerancia (D15), checkout (D2/D3). Informe **v5** con las 8 figuras insertadas, numeradas por orden de aparición; casos de uso, arquitectura, estados, checkout y DER en páginas horizontales. Sin cambios en el código.

---

## 12. Protocolo de trabajo y continuidad

**División del trabajo**

| Dónde | Qué se hace |
|---|---|
| **Claude Code** (carpeta `proyecto-ecommerce`) | Código, Docker, SQL, pruebas, despliegue, README, `docs/guia-defensa.md`, `docs/notas-implementacion.md` |
| **Chat del Proyecto** (claude.ai) | Informe (.docx, versión maestra; texto en el Contexto), diagramas (incluida Fig. 1), correcciones C1–C15, capturas, conclusiones, referencias, PDF |

**Sincronización entre ambos**

1. Al cerrar cada bloque, Claude Code actualiza en el repo: Registro (sección 11 de `docs/CONTINUIDAD_PROYECTO2.md`) y `docs/notas-implementacion.md` (rutas finales, ejemplos JSON de entrada/salida, códigos HTTP, versiones de librerías, cambios respecto al plan).
2. Anderson sube esos dos archivos al chat del Proyecto (o reemplaza CONTINUIDAD en el Contexto del Proyecto).
3. El chat del Proyecto convierte las notas en texto del informe y actualiza CONTINUIDAD (sección 4).

**Chats largos:** cuando un chat (del Proyecto o de Claude Code) se vuelva demasiado largo, antes de cerrarlo se genera un **prompt/resumen de continuidad**: estado actual, decisiones nuevas, qué falta y siguiente paso exacto, y se actualiza este archivo. El chat nuevo empieza leyendo CONTINUIDAD y el enunciado.
