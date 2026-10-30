#!/bin/bash
# =============================================================
# Crea un usuario de BD por microservicio con permisos SOLO sobre
# su propia base de datos (aislamiento entre servicios).
# Las contraseñas vienen de variables de entorno (.env), no del repo.
# Lo ejecuta el contenedor de MariaDB la primera vez que arranca.
# =============================================================
set -e

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS 'auth_user'@'%'     IDENTIFIED BY '${AUTH_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'catalogo_user'@'%' IDENTIFIED BY '${CATALOGO_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'pedidos_user'@'%'  IDENTIFIED BY '${PEDIDOS_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'pagos_user'@'%'    IDENTIFIED BY '${PAGOS_DB_PASSWORD}';

GRANT SELECT, INSERT, UPDATE, DELETE ON auth_db.*     TO 'auth_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON catalogo_db.* TO 'catalogo_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON pedidos_db.*  TO 'pedidos_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON pagos_db.*    TO 'pagos_user'@'%';
FLUSH PRIVILEGES;
SQL

echo "Usuarios por servicio creados."
