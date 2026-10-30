# Plataforma E-Commerce Distribuida — Tienda de Electrónica y Tecnología

Proyecto Final No. 2 — Desarrollo de Aplicaciones Web (PHP, MySQL y Aplicaciones Distribuidas), UMG.

> Tienda **ficticia** con fines académicos. Arquitectura de microservicios en PHP 8 + MariaDB Master-Slave + Redis + Nginx (API Gateway), orquestada con Docker Compose.

## Estructura

```
proyecto-ecommerce/
├── docker-compose.yml
├── nginx/nginx.conf            # API Gateway
├── auth-service/               # 8081 — registro, login, JWT
├── catalogo-service/           # 8082 — productos, categorías, stock
├── pedidos-service/            # 8083 — pedidos, estados, historial
├── pagos-service/              # 8084 — Stripe / PayPal (sandbox)
├── notificaciones-service/     # 8085 — correos (EmailJS)
├── db/init-master.sql          # esquema + datos semilla (20+ productos)
├── frontend/                   # tienda pública + panel admin
└── docs/                       # enunciado, continuidad, diagramas, guías
```

## Estado

Ver `docs/CONTINUIDAD_PROYECTO2.md` (sección 7: plan por bloques).
