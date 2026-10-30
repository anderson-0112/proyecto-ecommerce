# Reporte de pruebas — NodoTec

Generado automáticamente por `pruebas/ejecutar-pruebas.sh` el **2026-10-30 21:46** (hora del equipo) contra **https://nodotec.tech**.

**Total: 138 comprobaciones correctas, 0 con fallo.**

| Conjunto | Correctas | Con fallo | Estado | Salida completa |
|---|---|---|---|---|
| Bloque 1 — núcleo, JWT, permisos, compra Stripe | 41 | 0 | ✅ | [`bloque1-nucleo.txt`](salidas/bloque1-nucleo.txt) |
| Bloque 2 — replicación, caché Redis, logout | 15 | 0 | ✅ | [`bloque2-replicacion-redis.txt`](salidas/bloque2-replicacion-redis.txt) |
| Bloque 4 — EmailJS, OpenWeatherMap, Cloudinary, PayPal, QR | 16 | 0 | ✅ | [`bloque4-apis-externas.txt`](salidas/bloque4-apis-externas.txt) |
| Colección Postman (Newman, 35 peticiones) | 48 | 0 | ✅ | [`newman-coleccion.txt`](salidas/newman-coleccion.txt) |
| Tolerancia — notificaciones-service detenido | 3 | 0 | ✅ | [`tolerancia-notificaciones.txt`](salidas/tolerancia-notificaciones.txt) |
| Tolerancia — pagos-service detenido | 4 | 0 | ✅ | [`tolerancia-pagos.txt`](salidas/tolerancia-pagos.txt) |
| Tolerancia — catalogo-service detenido | 4 | 0 | ✅ | [`tolerancia-catalogo.txt`](salidas/tolerancia-catalogo.txt) |
| Tolerancia — mariadb-slave detenido | 3 | 0 | ✅ | [`tolerancia-slave.txt`](salidas/tolerancia-slave.txt) |
| Tolerancia — redis detenido | 4 | 0 | ✅ | [`tolerancia-redis.txt`](salidas/tolerancia-redis.txt) |

**Replicación Master-Slave:** ✅ Slave_IO_Running: Yes · Slave_SQL_Running: Yes

## Qué se prueba

- **Bloque 1:** catálogo sin registro, filtros y paginación; registro/login (bcrypt, JWT 2 h / 8 h); token alterado → 401; rutas internas bloqueadas en el Gateway → 403; permisos 401/403; CRUD admin; stock insuficiente → 409; compra con tarjeta 4242 (`pm_card_visa`) → pedido `pagado`, referencia `pi_…`, stock descontado, QR; acceso a pedidos y pagos ajenos → 403; pago rechazado → `pendiente` y reintento (D2); estados del pedido (D1).
- **Bloque 2:** lecturas desde el Slave, producto escrito en el Master visible en el Slave, caché Redis (HIT/MISS e invalidación), logout y token revocado → 401, reportes del admin.
- **Bloque 4:** clima (OpenWeatherMap), imágenes en Cloudinary y subida firmada, correo con EmailJS, QR (api.qrserver.com), PayPal sandbox (orden creada, sin aprobación no se cobra).
- **Tolerancia a fallos:** se detiene cada contenedor y se comprueba que el resto de la tienda sigue operando.

Pruebas manuales en el navegador (no automatizables): compra con la tarjeta 4242 escrita en Stripe Elements y compra con PayPal sandbox (ventana de PayPal) — ver `docs/notas-implementacion.md`.

Colección para Postman/Thunder Client: [`NodoTec.postman_collection.json`](NodoTec.postman_collection.json).
