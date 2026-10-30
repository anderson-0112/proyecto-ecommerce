# Guía de defensa — Proyecto Final No. 2

> Se actualiza al cerrar cada bloque. Objetivo: que Anderson pueda explicar **todo** el código.

## Bloque 0 — Base del repositorio

- **¿Por qué `.gitignore` excluye `.env`?** El `.env` guarda contraseñas de BD, el secreto del JWT y las claves de Stripe. Si se sube a GitHub cualquiera podría usarlas. Se entrega `.env.example` con nombres de variables y valores falsos.
- **¿Para qué `.gitattributes`?** Windows usa fin de línea CRLF y Linux (los contenedores) LF. Un script `.sh` con CRLF falla dentro del contenedor (`/bin/sh^M: bad interpreter`). Con `eol=lf` Git siempre guarda esos archivos en LF.
