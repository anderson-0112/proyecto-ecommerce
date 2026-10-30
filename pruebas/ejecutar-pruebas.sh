#!/usr/bin/env bash
# =============================================================
# Ejecuta TODAS las pruebas automáticas de NodoTec y genera el reporte.
#   bash pruebas/ejecutar-pruebas.sh            (desde la raíz del proyecto)
# Requisitos: los contenedores levantados (docker compose up --build -d).
# En Windows se ejecuta con Git Bash. Las pruebas corren dentro de un
# contenedor php:8.3-apache conectado a la red interna "ecommerce".
# Salidas: docs/pruebas/salidas/*.txt y docs/pruebas/reporte-pruebas.md
# =============================================================
set -u
export MSYS_NO_PATHCONV=1                      # Git Bash: no convertir rutas /pruebas
RAIZ_HOST=$(pwd -W 2>/dev/null || pwd)         # ruta del proyecto para montar el volumen
SALIDAS=docs/pruebas/salidas
REPORTE=docs/pruebas/reporte-pruebas.md
mkdir -p "$SALIDAS"

php_prueba() { # $1 = archivo de prueba, $2 = argumento opcional
  docker run --rm --network ecommerce -v "$RAIZ_HOST/pruebas:/pruebas" php:8.3-apache php "/pruebas/$1" ${2:-}
}

esperar_servicios() { # espera a que el Gateway responda tras arrancar un contenedor
  for _ in $(seq 1 30); do
    if docker run --rm --network ecommerce curlimages/curl:8.10.1 -sf http://gateway/api/catalogo/health >/dev/null 2>&1; then
      sleep 2; return 0
    fi
    sleep 2
  done
}

FILAS=""
TOTAL_OK=0
TOTAL_FALLOS=0

registrar() { # $1 = nombre, $2 = archivo de salida
  local linea ok fallos
  linea=$(grep -E '^Resultado:' "$2" | tail -1)
  ok=$(echo "$linea" | sed -E 's/Resultado: ([0-9]+) correctas.*/\1/')
  fallos=$(echo "$linea" | sed -E 's/.*, ([0-9]+) con fallo/\1/')
  ok=${ok:-0}; fallos=${fallos:-1}
  TOTAL_OK=$((TOTAL_OK + ok)); TOTAL_FALLOS=$((TOTAL_FALLOS + fallos))
  local estado="✅"; [ "$fallos" != "0" ] && estado="❌"
  FILAS="${FILAS}| ${1} | ${ok} | ${fallos} | ${estado} | [\`$(basename "$2")\`](salidas/$(basename "$2")) |"$'\n'
  echo "  $1: $ok correctas, $fallos con fallo"
}

echo "== Pruebas funcionales =="
php_prueba prueba-bloque1.php > "$SALIDAS/bloque1-nucleo.txt" 2>&1;           registrar "Bloque 1 — núcleo, JWT, permisos, compra Stripe" "$SALIDAS/bloque1-nucleo.txt"
php_prueba prueba-bloque2.php > "$SALIDAS/bloque2-replicacion-redis.txt" 2>&1; registrar "Bloque 2 — replicación, caché Redis, logout" "$SALIDAS/bloque2-replicacion-redis.txt"
php_prueba prueba-bloque4.php > "$SALIDAS/bloque4-apis-externas.txt" 2>&1;    registrar "Bloque 4 — EmailJS, OpenWeatherMap, Cloudinary, PayPal, QR" "$SALIDAS/bloque4-apis-externas.txt"

echo "== Colección Postman (Newman) =="
docker run --rm --network ecommerce -v "$RAIZ_HOST/docs/pruebas:/etc/newman" postman/newman:6-alpine \
  run NodoTec.postman_collection.json --env-var base_url=http://gateway --reporters cli --color off > "$SALIDAS/newman-coleccion.txt" 2>&1
# Newman resume las aserciones en una tabla: "assertions │ <ejecutadas> │ <fallidas>"
ASERCIONES=$(grep -E 'assertions' "$SALIDAS/newman-coleccion.txt" | grep -oE '[0-9]+' | head -2 | tr '\n' ' ')
set -- $ASERCIONES
echo "Resultado: $(( ${1:-0} - ${2:-1} )) correctas, ${2:-1} con fallo" >> "$SALIDAS/newman-coleccion.txt"
registrar "Colección Postman (Newman, 35 peticiones)" "$SALIDAS/newman-coleccion.txt"

echo "== Replicación (SHOW SLAVE STATUS) =="
docker exec mariadb-slave sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SHOW SLAVE STATUS\G"' 2>/dev/null \
  | grep -E 'Slave_IO_Running:|Slave_SQL_Running:|Seconds_Behind_Master|Last_IO_Error:|Last_SQL_Error:' > "$SALIDAS/replicacion-estado.txt"
cat "$SALIDAS/replicacion-estado.txt"
if grep -q 'Slave_IO_Running: Yes' "$SALIDAS/replicacion-estado.txt" && grep -q 'Slave_SQL_Running: Yes' "$SALIDAS/replicacion-estado.txt"; then
  REPLICA="✅ Slave_IO_Running: Yes · Slave_SQL_Running: Yes"
else
  REPLICA="❌ La replicación no está activa (ver salidas/replicacion-estado.txt)"; TOTAL_FALLOS=$((TOTAL_FALLOS + 1))
fi

echo "== Tolerancia a fallos =="
# escenario -> contenedor que se detiene
for par in notificaciones:notificaciones-service pagos:pagos-service catalogo:catalogo-service slave:mariadb-slave redis:redis; do
  escenario=${par%%:*}; contenedor=${par##*:}
  docker compose stop "$contenedor" >/dev/null 2>&1
  sleep 2
  php_prueba prueba-tolerancia.php "$escenario" > "$SALIDAS/tolerancia-$escenario.txt" 2>&1
  docker compose start "$contenedor" >/dev/null 2>&1
  esperar_servicios
  registrar "Tolerancia — $contenedor detenido" "$SALIDAS/tolerancia-$escenario.txt"
done

FECHA=$(date '+%Y-%m-%d %H:%M')
{
  echo "# Reporte de pruebas — NodoTec"
  echo
  echo "Generado automáticamente por \`pruebas/ejecutar-pruebas.sh\` el **$FECHA** (hora del equipo)."
  echo
  echo "**Total: $TOTAL_OK comprobaciones correctas, $TOTAL_FALLOS con fallo.**"
  echo
  echo "| Conjunto | Correctas | Con fallo | Estado | Salida completa |"
  echo "|---|---|---|---|---|"
  printf '%s' "$FILAS"
  echo
  echo "**Replicación Master-Slave:** $REPLICA"
  echo
  echo "## Qué se prueba"
  echo
  echo "- **Bloque 1:** catálogo sin registro, filtros y paginación; registro/login (bcrypt, JWT 2 h / 8 h); token alterado → 401; rutas internas bloqueadas en el Gateway → 403; permisos 401/403; CRUD admin; stock insuficiente → 409; compra con tarjeta 4242 (\`pm_card_visa\`) → pedido \`pagado\`, referencia \`pi_…\`, stock descontado, QR; acceso a pedidos y pagos ajenos → 403; pago rechazado → \`pendiente\` y reintento (D2); estados del pedido (D1)."
  echo "- **Bloque 2:** lecturas desde el Slave, producto escrito en el Master visible en el Slave, caché Redis (HIT/MISS e invalidación), logout y token revocado → 401, reportes del admin."
  echo "- **Bloque 4:** clima (OpenWeatherMap), imágenes en Cloudinary y subida firmada, correo con EmailJS, QR (api.qrserver.com), PayPal sandbox (orden creada, sin aprobación no se cobra)."
  echo "- **Tolerancia a fallos:** se detiene cada contenedor y se comprueba que el resto de la tienda sigue operando."
  echo
  echo "Pruebas manuales en el navegador (no automatizables): compra con la tarjeta 4242 escrita en Stripe Elements y compra con PayPal sandbox (ventana de PayPal) — ver \`docs/notas-implementacion.md\`."
  echo
  echo "Colección para Postman/Thunder Client: [\`NodoTec.postman_collection.json\`](NodoTec.postman_collection.json)."
} > "$REPORTE"

echo
echo "Total: $TOTAL_OK correctas, $TOTAL_FALLOS con fallo. Reporte: $REPORTE"
[ "$TOTAL_FALLOS" -eq 0 ]
