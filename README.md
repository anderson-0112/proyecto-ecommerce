# NodoTec — Plataforma E-Commerce Distribuida (Tienda de Electrónica y Tecnología)

Proyecto Final No. 2 — Desarrollo de Aplicaciones Web (PHP, MySQL y Aplicaciones Distribuidas), UMG.

**En producción:** tienda https://nodotec.tech · panel de administración https://nodotec.tech/admin/

> Tienda **ficticia** con fines académicos. Microservicios en PHP 8 + MariaDB + Nginx (API Gateway), orquestados con Docker Compose. Pagos con Stripe en **modo prueba**.

## Arquitectura

| Contenedor | Puerto interno | Responsabilidad |
|---|---|---|
| `gateway` (Nginx 1.27) | 80 y 443 — **únicos puertos publicados** | Entrada única: frontend + proxy a `/api/*`; bloquea rutas internas |
| `auth-service` | 8081 | Registro, login, JWT (2 h cliente / 8 h admin), verificación |
| `catalogo-service` | 8082 | Productos, categorías, filtros, paginación, stock, CRUD admin |
| `pedidos-service` | 8083 | Pedidos, estados, historial, QR de seguimiento |
| `pagos-service` | 8084 | Cobro con Stripe y PayPal (sandbox), estado de pagos |
| `notificaciones-service` | 8085 | Correo de confirmación con EmailJS |
| `mariadb-master` (MariaDB 10.6) | 3306 | Escrituras. `auth_db`, `catalogo_db`, `pedidos_db`, `pagos_db` (aisladas) |
| `mariadb-slave` (MariaDB 10.6) | 3306 | Réplica de solo lectura: catálogo y reportes del admin |
| `redis` (Redis 7) | 6379 | Caché del catálogo y tokens JWT revocados (logout) |

APIs externas: Stripe, PayPal Sandbox, EmailJS, Cloudinary, OpenWeatherMap y QR Code API (api.qrserver.com).

## Producción (VPS de Hostinger)

| | URL |
|---|---|
| Tienda (catálogo sin registro) | https://nodotec.tech |
| Panel de administración | https://nodotec.tech/admin/ |

VPS Ubuntu 24.04 con Docker y el **mismo `docker-compose.yml`**; solo cambia el `.env` del servidor (claves propias, `APP_URL=https://nodotec.tech`, `NGINX_CONF=nginx-https.conf`). HTTPS con Let's Encrypt (renovación automática), firewall con solo 22/80/443 y SSH solo con llave. Pruebas en producción: **138/138** ([`docs/pruebas/produccion/reporte-pruebas.md`](docs/pruebas/produccion/reporte-pruebas.md)).

Actualizar el servidor tras un `git push`:
```bash
ssh root@179.197.91.34 "cd /opt/proyecto-ecommerce && git pull && docker compose up --build -d"
```
Procedimiento completo de instalación y del certificado: [`docs/notas-implementacion.md`](docs/notas-implementacion.md) (Bloque 6).

## Puesta en marcha

1. Copiar `.env.example` a `.env` y completar los valores (Stripe y PayPal **de prueba/sandbox**, EmailJS, Cloudinary, OpenWeatherMap).
2. Levantar todo:
   ```bash
   docker compose up --build -d
   ```
3. Abrir la tienda en `http://localhost` y el panel de administración en `http://localhost/admin/`.
4. Pruebas automáticas (todas, con tolerancia a fallos; en Windows usar Git Bash):
   ```bash
   bash pruebas/ejecutar-pruebas.sh
   ```
   Reporte en [`docs/pruebas/reporte-pruebas.md`](docs/pruebas/reporte-pruebas.md); colección Postman en `docs/pruebas/NodoTec.postman_collection.json`.
5. Ver la replicación: `docker exec -it mariadb-slave mariadb -uroot -p -e "SHOW SLAVE STATUS\G"`.

Las URLs entre servicios y los datos de conexión a la BD son variables de entorno con los valores de Docker por defecto (ver `docker-compose.yml` y `.env.example`); el mismo archivo se usa en local y en el VPS.

## Credenciales de prueba

| Rol | Usuario | Contraseña |
|---|---|---|
| Administrador | admin@mitienda.com | Admin2026! |
| Cliente | cliente@test.com | Test2026! |
| Tarjeta Stripe (prueba) | 4242 4242 4242 4242 | 12/29 · CVC 123 |

## Endpoints

Lista completa con accesos, códigos HTTP y ejemplos JSON: [`docs/notas-implementacion.md`](docs/notas-implementacion.md).

## Estructura

```
proyecto-ecommerce/
├── docker-compose.yml
├── nginx/nginx.conf            # API Gateway (HTTP, local)
├── nginx/nginx-https.conf      # API Gateway con HTTPS (VPS)
├── nginx/rutas.conf            # rutas del Gateway (compartidas)
├── auth-service/               # Dockerfile + PHP (src/jwt.php)
├── catalogo-service/
├── pedidos-service/
├── pagos-service/              # src/stripe.php
├── notificaciones-service/
├── db/init-master.sql          # esquema + 20 productos + usuarios semilla
├── db/crear-usuarios.sh        # un usuario de BD por servicio
├── frontend/                   # tienda pública (index, login, checkout, mis-pedidos, seguimiento) + admin/
├── pruebas/                    # pruebas automáticas (ejecutar-pruebas.sh)
└── docs/                       # enunciado, continuidad, diagramas, guía de defensa
```

Créditos de las imágenes de productos: [`docs/creditos-imagenes.md`](docs/creditos-imagenes.md).
