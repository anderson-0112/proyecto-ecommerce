# CONTINUIDAD — Proyecto Final No. 2: E-Commerce Distribuido (Tienda de Electrónica y Tecnología)

> Archivo de traspaso. Última actualización: **30 de octubre de 2026 (hora de Guatemala)** — cierre del Bloque 1.
> **Fuente de verdad de los requisitos:** `Proyecto2_ECommerce_Distribuido.docx` (enunciado del ingeniero).
> Si este archivo y el enunciado se contradicen, **manda el enunciado**.
> Ubicación: raíz del Proyecto en Claude (Contexto) **y** `docs/CONTINUIDAD_PROYECTO2.md` en el repositorio. Mantener ambas copias iguales (ver sección 12).

---

## 0. Estado en una línea

Análisis ✅ · Informe redactado (faltan C1 parcial, C7, C8, C10 y datos de implementación) ✅ · 7 diagramas ✅ · Arquitectura (Fig. 1) ⏳ · Decisiones D1–D21 y D-PAGO ✅ · **Bloque 0 ✅ · Bloque 1 ✅ (compra 4242 → pedido `pagado`; 41/41 pruebas)** · Hosting: **VPS de Hostinger** (A9 actualizada, 30-oct).

**Siguiente paso exacto:** Claude Code ejecuta el **Bloque 2** (replicación Master-Slave + Redis: caché de catálogo, tokens revocados y `POST /api/auth/logout`). En paralelo, el chat del Proyecto pasa al informe lo de `docs/notas-implementacion.md` (Bloque 1) y cambia Railway → VPS de Hostinger.

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
| Tienda | **Ficticia**. Nombre comercial: **NodoTec** (definido por Anderson, 30-oct) |
| Equipo | Desde el 29-oct, **Anderson realiza todo el proyecto** (código y documentación). |
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
| A9 | ~~Railway~~ → **VPS de Hostinger** (aprobado por el catedrático). Se despliega con el **mismo `docker-compose.yml`**; solo el Gateway publica puertos. URLs entre servicios y datos de conexión en variables de entorno con valores de Docker por defecto (`${VAR:-valor}`). Sigue descartado el hosting compartido | Anderson, 30-oct |
| A10 | Código con **Claude Code** + pruebas en **Docker Desktop** antes de desplegar | Anderson |
| A11 | Diagramas como código (Mermaid) cuando sea posible; PNG/SVG para el informe | Aprobado |
| A12 | **Todas las decisiones técnicas D1–D21 y D-PAGO (opción B)** de la sección 6 | Anderson, 29-oct |
| A13 | Datos semilla: 20 productos de **marcas/modelos reales**, precios en **USD**, fotos con **licencia libre** por URL pública (migración a Cloudinary en Bloque 4). Claude Code propone la lista y Anderson la revisa antes de cerrar el Bloque 1 | Anderson, 29-oct |
| A14 | División del trabajo: **Claude Code = código**; **chat del Proyecto = documentación** (informe, diagramas, guía de defensa final). Ver sección 12 | Anderson, 29-oct |
| A15 | Cuando un chat se vuelva demasiado largo, generar un **resumen/prompt de continuidad** para seguir en un chat nuevo (sección 12) | Anderson, 29-oct |
| A16 | Nombre comercial: **NodoTec** | Anderson, 30-oct |
| A17 | 20 productos semilla aprobados (lista en `db/init-master.sql`; créditos de fotos en `docs/creditos-imagenes.md`) | Anderson, 30-oct |
| A18 | Rutas adicionales aprobadas: `GET /api/catalogo/categorias`, `GET /api/pedidos` (historial), `GET /api/pagos/config` (clave pública de Stripe), `/api/<servicio>/health`; rutas internas `/api/*/interno/*` (bloqueadas en el Gateway): `catalogo/interno/verificar-stock`, `catalogo/interno/descontar-stock`, `pedidos/interno/{id}/pagado`, `notificaciones/interno/confirmacion-pedido` | Anderson, 30-oct |

---

## 4. Estado del informe (documentación — se lleva en el chat del Proyecto)

**Ubicación:** Claude Doc "Informe Proyecto Final No. 2 — E-Commerce Distribuido: Tienda de Electrónica y Tecnología" — https://claude.ai/artifact/QD8pPeaP2CxNVP8yfFWPiW (exportado también a .docx en el Proyecto).

| Sección | Estado |
|---|---|
| Portada | ⛔ **No tocar** — la hace Anderson |
| 1. Introducción | ✅ (C15 aplicada) |
| 2. Descripción | ✅ C2 aplicada · ⚠️ **C1 parcial**: falta la frase completa de negocio ficticio y cambiar "desarrollado por dos integrantes" |
| 3. Planteamiento | ✅ (C3 y C4 aplicadas) |
| 4. Justificación | ✅ · ⚠️ 4.2 "pago con tarjeta" → agregar PayPal |
| 5. Usuarios | ⚠️ 5.2: "pagar con Stripe" → "pagar con Stripe o PayPal"; quitar la nota "[PENDIENTE: confirmar con el compañero…]"; insertar Fig. 4 |
| 6. Objetivos | ✅ (C5 aplicada) |
| 7. Alcance | ✅ C6 · ⚠️ **C7** y **C8** pendientes |
| 8. Requerimientos | ✅ C9 · ⚠️ **C10 (RF-19)** pendiente |
| 9. Tecnologías | 🟡 Completable ya: notificaciones = PHP 8 (D14), hosting = VPS de Hostinger. Versiones: ver `docs/notas-implementacion.md` (Bloque 1) |
| 10. Arquitectura | 🟡 10.3 completable ya (D7) · 10.4 agregar descuento de stock (D20) y QR (D11) · 10.5 completable ya (D15) · Fig. 1 tras Bloque 2 |
| 11. Base de datos | 🟡 Campos D5 completables ya; tipos y llaves tras el SQL final; replicación tras Bloque 2 |
| 12. APIs externas | ✅ C11 aplicada · ⏳ cómo se integró cada API (tras Bloques 1 y 4) |
| 13. Endpoints | ✅ C12 · 🟡 accesos completables ya (D16), agregar `POST /api/auth/logout` (D8) · ⏳ rutas CRUD finales, JSON y códigos (de `docs/notas-implementacion.md`) |
| 14. Despliegue | ⏳ VPS de Hostinger (Docker Compose), procedimiento, URLs |
| 15. Evidencias | ✅ C13 aplicada · ⚠️ falta cerrar el corchete en "[PENDIENTE: captura" · ⏳ capturas |
| 16. Credenciales | ✅ C14 · 🟡 admin y cliente completables ya (D17) · ⏳ URLs y cuenta PayPal sandbox |
| 17. Conclusiones | ⏳ Al final |
| 18. Referencias | ⏳ Documentación oficial usada; norma de citación `[PENDIENTE — Anderson]` |
| 19. Anexos | ⏳ Opcional |

Reemplazar en todo el informe "el compañero" por el responsable actual (Anderson).

### 4.1 Correcciones aprobadas aún NO aplicadas (verificado contra el .docx el 29-oct)

- **C1 (2.2):** agregar: *"La tienda es un negocio ficticio, creado con fines académicos para este proyecto. Su contexto y sus datos, como los productos, los usuarios y los pedidos, fueron definidos para simular el funcionamiento de un comercio real de electrónica y tecnología."* Y ajustar "desarrollado por dos integrantes" → `[PENDIENTE — redacción que indique Anderson]`.
- **C7 (7.1 admin):** "consulta de detalle, cambio de estado (pendiente, preparando, enviado, entregado) y comunicación con el cliente."
- **C8 (7.2):** eliminar el punto "Pagos con PayPal: no se implementan…".
- **C10 (8.1):** nueva fila "RF-19 | Permitir al administrador comunicarse con el cliente desde la gestión de pedidos | Panel admin".
- Menores: cerrar corchete en 15.2 (PayPal); 5.2 y 4.2 mencionar PayPal.

C2, C3, C4, C5, C6, C9, C11, C12, C13, C14 y C15 **ya están aplicadas**.

---

## 5. Diagramas

Carpeta: `docs/diagramas/` en el repo (copiar desde el paquete). Cada diagrama tiene PNG, SVG, HTML y, cuando aplica, `.mmd`.

| Fig. | Diagrama | Estado | Sección del informe | Archivos |
|---|---|---|---|---|
| 1 | **Arquitectura** (obligatorio) | ⏳ Tras Bloque 2, con lo implementado | 10.1 | — |
| 2 | Secuencia: flujo de compra | ✅ Aprobado — **se mantiene sin cambios** (D-PAGO opción B respeta pedidos → pagos) | 10.4 | `diagrama1_secuencia/` (.mmd) |
| 3 | DER por microservicio | ✅ Aprobado · revisar al cerrar SQL (campos D5, `referencia_paypal` D4) | 11.1 | `diagrama2_der/` |
| 4 | Casos de uso | ✅ Aprobado | 5.2 | `diagrama3_casos_uso/` |
| 5 | Estados del pedido | ✅ Aprobado · verificar que incluya `pagado` (D1) | 10.4 | `diagrama4_estados/` (.mmd) |
| 6 | Secuencia: autenticación JWT | ✅ Aprobado · completar códigos HTTP (D18) | 10.3 | `diagrama5_autenticacion/` (.mmd) |
| 7 | Secuencia: tolerancia a fallos | ✅ Aprobado | 10.5 | `diagrama6_tolerancia_fallos/` (.mmd) |
| 8 | Actividades: checkout | ✅ Entregado | 10.4 | `diagrama7_actividades/` (.mmd) |

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
- HTTPS: `[PENDIENTE]` dominio o IP pública y certificado (Let's Encrypt) — decidir en el Bloque 6.
- Datos del VPS (plan, IP, acceso SSH): `[PENDIENTE — Anderson]`.
- ~~Railway~~ descartado el 30-oct (A9).

### 7.2 Riesgos técnicos conocidos

- **CRLF en Windows:** `.gitattributes` con `eol=lf`.
- **Secretos:** solo en `.env` (ignorado). Si una clave aparece en el chat, rotarla.
- **Stripe:** claves de prueba; nunca enviar números de tarjeta al backend.
- **Deadlock entre servicios** si se usa `php -S` (ver D-PAGO req.).
- **Retraso de replicación:** tras un cambio del admin, el Slave puede mostrar datos viejos unos instantes; se invalida la caché y se documenta.

---

## 8. Tareas de Anderson en paralelo (Claude no puede hacerlas)

1. Copiar a la carpeta `proyecto-ecommerce`: `CLAUDE.md` (texto de Instrucciones del Proyecto), `docs/CONTINUIDAD_PROYECTO2.md`, `docs/Proyecto2_ECommerce_Distribuido.docx`, `docs/diagramas/`.
2. ~~Claves de prueba de Stripe en `.env`~~ ✅ (30-oct).
3. ~~Railway~~ → Preparar el **VPS de Hostinger** (acceso SSH) antes del Bloque 6.
4. **Para Bloque 4:** PayPal Developer (app sandbox + cuenta compradora), EmailJS (service, template, public/private key), Cloudinary (cloud name, API key/secret), OpenWeatherMap (API key).
5. ~~Definir el nombre comercial~~ ✅ NodoTec.
6. ~~Revisar la lista de 20 productos~~ ✅ aprobada (30-oct).
7. Indicar la redacción de 2.2 sobre quién desarrolla el proyecto (C1).
8. Elaborar la portada del informe.
9. Comandos manuales: **uno a la vez**, pegar la salida.

---

## 9. Guía de defensa (obligatoria por la regla de "nota 0")

Claude Code mantiene `docs/guia-defensa.md` al cerrar cada bloque: qué hace cada servicio, cómo viaja el JWT y por qué se verifica, por qué `php:8.x-apache` y no `php -S`, cómo funciona y se comprueba la replicación, para qué se usa Redis, cómo se integra cada API externa, cómo se demuestra la tolerancia a fallos. Más 5 preguntas probables del catedrático con respuesta.

**Guion de demo (10 min):** catálogo sin registro → registro/login → carrito → pago Stripe (4242) → correo recibido → QR → admin: dashboard + clima + cambiar estado → detener notificaciones y repetir compra → mostrar replicación.

---

## 10. Checklist de entrega final

- [ ] URL pública de la tienda (catálogo sin registro)
- [ ] URL del panel admin + credenciales
- [ ] Repo GitHub público con los 5 servicios, `docker-compose.yml`, README, sin secretos
- [ ] Scripts SQL con 20+ productos con imágenes reales
- [ ] PDF del informe (portada de Anderson + diagramas + endpoints + capturas)
- [ ] Credenciales: admin, cliente, tarjeta Stripe, cuenta PayPal sandbox
- [ ] Evidencias de pruebas (reporte + colección)
- [ ] Guía de defensa y guion de demo
- [ ] Diagrama de arquitectura insertado

---

## 11. Registro de cambios y avances

> Agregar **una línea** al terminar cada bloque o al cambiar una decisión: fecha, qué se hizo, qué cambió y por qué.

- 2026-09-23 — Análisis del enunciado, estructura del informe aprobada, informe redactado con `[PENDIENTE]`.
- 2026-09-23 — APIs elegidas; PayPal confirmado como obligatorio; tienda ficticia.
- 2026-09/10 — Diagramas aprobados: secuencia compra, DER, casos de uso, estados, autenticación, tolerancia a fallos; actividades entregado.
- 2026-10-29 — Anderson asume todo el proyecto. Hostinger descartado; Railway elegido. Desarrollo con Claude Code. Archivo de continuidad creado.
- 2026-10-29 — Aprobadas en bloque D1–D19, D-PAGO (opción B: pedidos llama a pagos; tarjeta tokenizada en el navegador; diagrama de secuencia sin cambios), D20 (descuento de stock al pagar) y D21 (USD). Confirmado que el negocio no repite el Proyecto 1. Revisión del .docx: C2–C6, C9, C11–C15 ya aplicadas; pendientes C1 (parcial), C7, C8, C10. Definida la división código (Claude Code) / documentación (chat del Proyecto) y el protocolo de continuidad (sección 12).
- 2026-10-29 — **Bloque 0 terminado:** repo git inicializado (rama `main`), `.gitignore` (excluye `.env`, verificado con `git check-ignore`), `.gitattributes` (LF para .sh/.php/.sql/.conf/Dockerfile), `.env.example`, README inicial, estructura de carpetas de la sección 2.5; remoto `anderson-0112/proyecto-ecommerce` conectado y push hecho.
- 2026-10-30 — Decisiones nuevas: hosting final **VPS de Hostinger** con el mismo `docker-compose.yml` (A9, reemplaza a Railway; aprobado por el catedrático); nombre **NodoTec** (A16); 20 productos aprobados (A17); rutas adicionales (A18); código **202** agregado a D18.
- 2026-10-30 — **Bloque 1 terminado:** `docker compose up --build -d` levanta gateway (Nginx 1.27), 5 servicios PHP 8.3 + Apache y MariaDB 10.6 (4 BD aisladas, un usuario por servicio, 20 productos, usuarios semilla). JWT HS256 propio en auth-service, verificado por los demás vía `/api/auth/verify`. Compra con tarjeta 4242 desde el navegador (Stripe.js) → pedido `pagado`, `pi_…` guardado, stock descontado, QR generado. Pruebas automáticas `pruebas/prueba-bloque1.php`: 41/41. Tolerancia: con notificaciones detenido la compra termina (201, `pagado`, correo no enviado). Envío real de correo pendiente (Bloque 4).

---

## 12. Protocolo de trabajo y continuidad

**División del trabajo**

| Dónde | Qué se hace |
|---|---|
| **Claude Code** (carpeta `proyecto-ecommerce`) | Código, Docker, SQL, pruebas, despliegue, README, `docs/guia-defensa.md`, `docs/notas-implementacion.md` |
| **Chat del Proyecto** (claude.ai) | Informe (Claude Doc), diagramas (incluida Fig. 1), correcciones C1–C15, capturas, conclusiones, referencias, PDF |

**Sincronización entre ambos**

1. Al cerrar cada bloque, Claude Code actualiza en el repo: Registro (sección 11 de `docs/CONTINUIDAD_PROYECTO2.md`) y `docs/notas-implementacion.md` (rutas finales, ejemplos JSON de entrada/salida, códigos HTTP, versiones de librerías, cambios respecto al plan).
2. Anderson sube esos dos archivos al chat del Proyecto (o reemplaza CONTINUIDAD en el Contexto del Proyecto).
3. El chat del Proyecto convierte las notas en texto del informe y actualiza CONTINUIDAD (sección 4).

**Chats largos:** cuando un chat (del Proyecto o de Claude Code) se vuelva demasiado largo, antes de cerrarlo se genera un **prompt/resumen de continuidad**: estado actual, decisiones nuevas, qué falta y siguiente paso exacto, y se actualiza este archivo. El chat nuevo empieza leyendo CONTINUIDAD y el enunciado.
