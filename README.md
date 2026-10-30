# NodoTec — Plataforma E-Commerce Distribuida (Tienda de Electrónica y Tecnología)

Proyecto Final No. 2 — Desarrollo de Aplicaciones Web (PHP, MySQL y Aplicaciones Distribuidas), UMG.

> Tienda **ficticia** con fines académicos. Microservicios en PHP 8 + MariaDB + Nginx (API Gateway), orquestados con Docker Compose. Pagos con Stripe en **modo prueba**.

## Arquitectura

| Contenedor | Puerto interno | Responsabilidad |
|---|---|---|
| `gateway` (Nginx 1.27) | 80 — **único puerto publicado** | Entrada única: frontend + proxy a `/api/*`; bloquea rutas internas |
| `auth-service` | 8081 | Registro, login, JWT (2 h cliente / 8 h admin), verificación |
| `catalogo-service` | 8082 | Productos, categorías, filtros, paginación, stock, CRUD admin |
| `pedidos-service` | 8083 | Pedidos, estados, historial, QR de seguimiento |
| `pagos-service` | 8084 | Cobro con Stripe, estado de pagos |
| `notificaciones-service` | 8085 | Confirmación de pedido (correo real con EmailJS en el Bloque 4) |
| `mariadb-master` (MariaDB 10.6) | 3306 | Escrituras. `auth_db`, `catalogo_db`, `pedidos_db`, `pagos_db` (aisladas) |
| `mariadb-slave` (MariaDB 10.6) | 3306 | Réplica de solo lectura: catálogo y reportes del admin |
| `redis` (Redis 7) | 6379 | Caché del catálogo y tokens JWT revocados (logout) |

Pendiente: PayPal, EmailJS, Cloudinary, OpenWeatherMap (Bloque 4), despliegue en VPS de Hostinger (Bloque 6).

## Puesta en marcha

1. Copiar `.env.example` a `.env` y completar los valores (claves de Stripe **de prueba**).
2. Levantar todo:
   ```bash
   docker compose up --build -d
   ```
3. Abrir la tienda en `http://localhost` y el panel de administración en `http://localhost/admin/`.
4. Pruebas automáticas:
   ```bash
   docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque1.php
   docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque2.php
   ```
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
├── nginx/nginx.conf            # API Gateway
├── auth-service/               # Dockerfile + PHP (src/jwt.php)
├── catalogo-service/
├── pedidos-service/
├── pagos-service/              # src/stripe.php
├── notificaciones-service/
├── db/init-master.sql          # esquema + 20 productos + usuarios semilla
├── db/crear-usuarios.sh        # un usuario de BD por servicio
├── frontend/                   # tienda pública (index, login, checkout, mis-pedidos, seguimiento) + admin/
├── pruebas/                    # pruebas automáticas
└── docs/                       # enunciado, continuidad, diagramas, guía de defensa
```

Créditos de las imágenes de productos: [`docs/creditos-imagenes.md`](docs/creditos-imagenes.md).
