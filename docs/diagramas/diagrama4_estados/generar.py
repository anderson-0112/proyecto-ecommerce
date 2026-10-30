# Fig. 5 — Diagrama de estados del pedido (D1 y D2 aplicadas).
import sys
import os
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from svglib import *  # utilidades comunes en docs/diagramas/svglib.py

s = Svg(1680, 480)
s.text(20, 34, "Estados del pedido (campo pedidos.estado)", 20, "bold")
s.text(20, 56, "Quién provoca cada transición y qué ocurre en cada estado, según la implementación de pedidos-service.", 12, fill=MUTED)

xs, y, w, h = [220, 520, 820, 1120, 1420], 180, 150, 56
names = ["Pendiente", "Pagado", "Preparando", "Enviado", "Entregado"]
for x, n in zip(xs, names):
    s.rect(x, y, w, h, BORDER, "#eef1f4", rx=10)
    s.text(x + w / 2, y + 34, n, 15, "bold", INK, "middle")
cy = y + h / 2

# Inicio y fin
s.raw(f'<circle cx="60" cy="{cy}" r="11" fill="{INK}"/>')
s.line([(71, cy), (xs[0], cy)], INK, arrow="ink")
s.text(140, cy - 30, "Cliente crea el pedido", 11, "bold", INK, "middle")
s.text(140, cy - 15, "POST /api/pedidos", 11, fill=MUTED, anchor="middle")
s.line([(xs[4] + w, cy), (1614, cy)], INK, arrow="ink")
s.raw(f'<circle cx="1630" cy="{cy}" r="13" fill="#ffffff" stroke="{INK}" stroke-width="2"/>')
s.raw(f'<circle cx="1630" cy="{cy}" r="7" fill="{INK}"/>')

# Autotransición de Pendiente (pago rechazado o no aprobado)
s.path(f"M{xs[0] + 35},{y} C{xs[0] + 35},{y - 70} {xs[0] + 115},{y - 70} {xs[0] + 115},{y - 2}", RED, width=2, arrow=None)
s.raw(f'<path d="M{xs[0] + 109},{y - 12} L{xs[0] + 115},{y - 1} L{xs[0] + 121},{y - 12} z" fill="{RED}"/>')
s.text(xs[0] + 75, y - 76, "Pago rechazado o PayPal no aprobado", 11, "bold", RED, "middle")
s.text(xs[0] + 75, y - 61, "(el pedido sigue pendiente)", 11, fill=RED, anchor="middle")

# Pendiente → Pagado (sistema)
s.line([(xs[0] + w, cy), (xs[1], cy)], PURPLE, width=2.2, arrow="purple")
s.text((xs[0] + w + xs[1]) / 2, cy - 12, "Pago confirmado", 11, "bold", PURPLE, "middle")
s.text((xs[0] + w + xs[1]) / 2, cy + 22, "(lo asigna el sistema)", 11, fill=PURPLE, anchor="middle")

# Transiciones del administrador
for i in range(1, 4):
    x1, x2 = xs[i] + w, xs[i + 1]
    s.line([(x1, cy), (x2, cy)], BLUE, width=2.2, arrow="blue")
    s.text((x1 + x2) / 2, cy - 12, "Administrador", 11, "bold", BLUE, "middle")
    s.text((x1 + x2) / 2, cy + 22, "(siguiente estado)", 11, fill=BLUE, anchor="middle")

# Notas
ny = 292
notas = [
    (150, 290, xs[0] + w / 2, [
        "Pendiente: el pedido se registró con el stock",
        "verificado, pero todavía no se ha cobrado.",
        "Si Stripe rechaza el pago o el cliente no lo",
        "aprueba en PayPal, el pedido sigue pendiente y",
        "el cliente puede reintentar el pago.",
    ]),
    (455, 300, xs[1] + w / 2, [
        "Pagado: lo asigna solo el sistema, cuando",
        "pagos-service llama a la ruta interna",
        "PUT /api/pedidos/interno/{id}/pagado.",
        "En ese momento se descuenta el stock, se",
        "genera el QR y se envía el correo.",
    ]),
]
for nx, nw, cx, ls in notas:
    s.line([(cx, y + h), (cx, ny)], MUTED, dash=True, width=1.3)
    s.note(nx, ny, nw, ls)
s.note(790, ny, 830, [
    "Preparando, Enviado y Entregado: los asigna el administrador con PUT /api/pedidos/{id}/estado, y solo",
    "puede avanzar al estado siguiente. Cualquier otro cambio (saltar o retroceder un estado, o asignar «pagado»",
    "manualmente) responde 409 Conflict. El modelo no incluye un estado «cancelado».",
])
for i in (2, 3, 4):
    cx = xs[i] + w / 2
    s.line([(cx, y + h), (cx, ny)], MUTED, dash=True, width=1.3)

# Leyenda
ly = 440
s.rect(20, ly - 22, 1640, 44, LINE, "#f6f8fa", rx=8, sw=1)
s.text(36, ly + 5, "Leyenda", 12.5, "bold")
items = [(INK, "ink", "Acción del cliente"), (PURPLE, "purple", "Transición que asigna el sistema"),
         (BLUE, "blue", "Transición que asigna el administrador"), (RED, None, "Pago no completado (el estado no cambia)")]
lx = 130
for col, mk, lab in items:
    s.line([(lx, ly), (lx + 44, ly)], col, width=2.2, arrow=mk)
    s.text(lx + 54, ly + 4, lab, 11.5)
    lx += 380

s.save(sys.argv[1])
print("ok")
