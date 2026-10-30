# Fig. 3 — DER por microservicio con los campos finales de db/init-master.sql.
import sys
import os
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from svglib import *  # utilidades comunes en docs/diagramas/svglib.py

s = Svg(1720, 800)
s.text(20, 34, "Modelo de datos por microservicio (db/init-master.sql)", 20, "bold")
s.text(20, 56, "Cada servicio tiene su propia base de datos en MariaDB y un usuario con permisos solo sobre ella. "
           "Las llaves foráneas existen únicamente dentro de cada base.", 12, fill=MUTED)

HDR, ROW = 32, 23
BADGE = {"PK": ("#1f2a36", "#ffffff"), "FK": (BLUE, "#ffffff"), "UQ": ("#e1e6eb", INK), "RL": (PURPLE, "#ffffff")}


def tabla(x, y, w, nombre, filas, color=BORDER):
    """Dibuja una tabla. filas = [(badges, campo, tipo)]. Devuelve {campo: y_centro} y la altura."""
    h = HDR + ROW * len(filas)
    s.rect(x, y, w, h, color, "#ffffff", rx=6, sw=1.8)
    s.raw(f'<path d="M{x},{y + HDR} V{y + 6} Q{x},{y} {x + 6},{y} H{x + w - 6} Q{x + w},{y} {x + w},{y + 6} V{y + HDR} Z" fill="{color}"/>')
    s.text(x + 12, y + 21, nombre, 13.5, "bold", "#ffffff")
    pos = {}
    for i, (badges, campo, tipo) in enumerate(filas):
        ry = y + HDR + ROW * i
        if i:
            s.raw(f'<line x1="{x}" y1="{ry}" x2="{x + w}" y2="{ry}" stroke="{LINE}" stroke-width="1"/>')
        bx = x + 8
        for b in badges:
            bg, fg = BADGE[b]
            s.raw(f'<rect x="{bx}" y="{ry + 5}" width="22" height="13" rx="3" fill="{bg}"/>')
            s.text(bx + 11, ry + 15, b, 8.5, "bold", fg, "middle")
            bx += 25
        s.text(x + 62, ry + 16, campo, 11.5, "bold" if "PK" in badges else "normal",
               PURPLE if "RL" in badges else INK)
        s.text(x + w - 10, ry + 16, tipo, 10.5, fill=MUTED, anchor="end")
        pos[campo] = ry + ROW / 2
    return pos, h


def contenedor(x, w, titulo, sub, color):
    s.rect(x, 80, w, 492, color, "#fbfbfc", rx=10, sw=1.5, dash="8 5")
    s.text(x + 14, 104, titulo, 14, "bold", color)
    s.text(x + 14, 122, sub, 11, fill=MUTED)


def fk(pts, n_pos, uno_pos):
    s.line(pts, BLUE, width=1.8)
    s.text(*uno_pos, "1", 11, "bold", BLUE)
    s.text(*n_pos, "N", 11, "bold", BLUE)


INT = "INT UNSIGNED"
# ---- auth_db ----
contenedor(20, 330, "auth_db", "auth-service · usuario auth_user", BORDER)
tabla(36, 140, 298, "usuarios", [
    (["PK"], "id", INT + " AI"),
    ([], "nombre", "VARCHAR(100)"),
    (["UQ"], "email", "VARCHAR(150)"),
    ([], "password_hash", "VARCHAR(255) · bcrypt"),
    ([], "rol", "ENUM(cliente, admin)"),
    ([], "created_at", "DATETIME"),
])

# ---- catalogo_db ----
contenedor(365, 345, "catalogo_db", "catalogo-service · usuario catalogo_user", BORDER)
pc, _ = tabla(392, 140, 300, "categorias", [
    (["PK"], "id", INT + " AI"),
    (["UQ"], "nombre", "VARCHAR(80)"),
    ([], "descripcion", "VARCHAR(255)"),
])
pp, _ = tabla(392, 270, 300, "productos", [
    (["PK"], "id", INT + " AI"),
    ([], "nombre", "VARCHAR(150)"),
    ([], "descripcion", "TEXT"),
    ([], "precio", "DECIMAL(10,2) · USD"),
    ([], "stock", INT),
    ([], "imagen_url", "VARCHAR(500)"),
    ([], "destacado", "TINYINT(1)"),
    ([], "activo", "TINYINT(1)"),
    (["FK"], "categoria_id", INT),
])
fk([(392, pc["id"]), (378, pc["id"]), (378, pp["categoria_id"]), (392, pp["categoria_id"])],
   (381, pp["categoria_id"] - 6), (381, pc["id"] - 6))

# ---- pedidos_db ----
contenedor(725, 640, "pedidos_db", "pedidos-service · usuario pedidos_user", BORDER)
pcl, _ = tabla(752, 140, 280, "clientes", [
    (["PK"], "id", INT + " AI"),
    (["UQ", "RL"], "id_usuario", INT),
    ([], "nombre", "VARCHAR(100)"),
    ([], "email", "VARCHAR(150)"),
    ([], "telefono", "VARCHAR(30)"),
])
pdi, _ = tabla(752, 330, 280, "direcciones", [
    (["PK"], "id", INT + " AI"),
    (["FK"], "id_cliente", INT),
    ([], "direccion", "VARCHAR(255)"),
    ([], "ciudad", "VARCHAR(100)"),
    ([], "telefono", "VARCHAR(30)"),
])
ppe, _ = tabla(1072, 140, 270, "pedidos", [
    (["PK"], "id", INT + " AI"),
    (["FK"], "id_cliente", INT),
    ([], "total", "DECIMAL(10,2)"),
    ([], "estado", "ENUM (5 valores)"),
    ([], "fecha", "DATETIME"),
])
pde, _ = tabla(1072, 330, 270, "detalle_pedidos", [
    (["PK"], "id", INT + " AI"),
    (["FK"], "id_pedido", INT),
    (["RL"], "id_producto", INT),
    ([], "nombre_producto", "VARCHAR(150)"),
    ([], "cantidad", INT),
    ([], "precio_unitario", "DECIMAL(10,2)"),
    ([], "subtotal", "DECIMAL(10,2)"),
])
fk([(752, pcl["id"]), (738, pcl["id"]), (738, pdi["id_cliente"]), (752, pdi["id_cliente"])],
   (741, pdi["id_cliente"] - 6), (741, pcl["id"] - 6))
fk([(1032, pcl["id"]), (1052, pcl["id"]), (1052, ppe["id_cliente"]), (1072, ppe["id_cliente"])],
   (1060, ppe["id_cliente"] - 6), (1037, pcl["id"] - 6))
fk([(1342, ppe["id"]), (1355, ppe["id"]), (1355, pde["id_pedido"]), (1342, pde["id_pedido"])],
   (1345, pde["id_pedido"] - 6), (1345, ppe["id"] - 6))

# ---- pagos_db ----
contenedor(1380, 320, "pagos_db", "pagos-service · usuario pagos_user", BORDER)
tabla(1395, 140, 290, "pagos", [
    (["PK"], "id", INT + " AI"),
    (["RL"], "id_pedido", INT),
    ([], "monto", "DECIMAL(10,2)"),
    ([], "metodo", "ENUM(stripe, paypal)"),
    (["UQ"], "referencia_stripe", "VARCHAR(100)"),
    (["UQ"], "referencia_paypal", "VARCHAR(100)"),
    ([], "estado", "ENUM (3 valores)"),
    ([], "fecha_pago", "DATETIME"),
])

# ---- Notas y leyenda ----
fy = 592
s.rect(20, fy, 1680, 190, LINE, "#f6f8fa", rx=8, sw=1)
s.text(36, fy + 24, "Referencias lógicas entre bases (RL): no tienen llave foránea porque las tablas están en bases distintas; se validan mediante las API.", 12, "bold", PURPLE)
s.text(36, fy + 44, "clientes.id_usuario → auth_db.usuarios.id   ·   detalle_pedidos.id_producto → catalogo_db.productos.id   ·   pagos.id_pedido → pedidos_db.pedidos.id", 11.5, fill=PURPLE)
s.text(36, fy + 72, "Valores de los ENUM:  pedidos.estado = pendiente · pagado · preparando · enviado · entregado   |   pagos.estado = pendiente · aprobado · rechazado", 11.5)
s.text(36, fy + 92, "Baja lógica: productos.activo = 0.   detalle_pedidos guarda el nombre y el precio del producto al momento de la compra.", 11.5)
s.text(36, fy + 112, "Índices adicionales: productos (activo, categoria_id, precio) · pedidos (estado, fecha) · pagos (id_pedido).   notificaciones-service no tiene base de datos.", 11.5)
lx, ly = 36, fy + 150
s.text(lx, ly + 4, "Leyenda", 12.5, "bold")
lx += 80
for b, lab in [("PK", "Llave primaria"), ("FK", "Llave foránea"), ("UQ", "Valor único"), ("RL", "Referencia lógica a otra base")]:
    bg, fg = BADGE[b]
    s.raw(f'<rect x="{lx}" y="{ly - 8}" width="24" height="15" rx="3" fill="{bg}"/>')
    s.text(lx + 12, ly + 3, b, 9, "bold", fg, "middle")
    s.text(lx + 32, ly + 4, lab, 11.5)
    lx += 210
s.line([(lx, ly), (lx + 40, ly)], BLUE, width=1.8)
s.text(lx + 48, ly + 4, "Relación 1 a N (llave foránea)", 11.5)
s.text(lx + 280, ly + 4, "AI = AUTO_INCREMENT", 11.5, fill=MUTED)

s.save(sys.argv[1])
print("ok")
