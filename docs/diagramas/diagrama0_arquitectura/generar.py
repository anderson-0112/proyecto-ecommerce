# Uso: python generar.py <ruta_sin_extension>  (requiere: pip install cairosvg)
# Genera la Fig. 1 (arquitectura) como SVG y PNG.
import sys, cairosvg

W, H = 1720, 980
INK, BORDER, MUTED = "#1f2a36", "#3a4a5c", "#5b6673"
BLUE, GREEN, PURPLE, AMBER = "#2f6b8a", "#3f7d4f", "#7a3f6e", "#8a5a00"
o = []
a = o.append

def esc(t):
    return t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

def text(x, y, t, size=11, weight="normal", fill=INK, anchor="start", style=""):
    a(f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{fill}" '
      f'text-anchor="{anchor}" {style}>{esc(t)}</text>')

def box(x, y, w, h, title, lines, color, fill="#ffffff", dash=False, tsize=13, lsize=11, center=True):
    d = 'stroke-dasharray="6 4"' if dash else ""
    a(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{fill}" stroke="{color}" stroke-width="2" {d}/>')
    a(f'<rect x="{x}" y="{y}" width="{w}" height="6" rx="3" fill="{color}"/>')
    cx = x + w / 2 if center else x + 12
    anc = "middle" if center else "start"
    text(cx, y + 26, title, tsize, "bold", color, anc)
    for i, l in enumerate(lines):
        text(cx, y + 26 + 17 * (i + 1), l, lsize, "normal", INK, anc)

def arrow(pts, color=INK, dash=False, width=1.8, marker="ink", start=False):
    d = 'stroke-dasharray="7 5"' if dash else ""
    p = " ".join(f"{x},{y}" for x, y in pts)
    m = f'marker-end="url(#m-{marker})"'
    if start:
        m += f' marker-start="url(#s-{marker})"'
    a(f'<polyline points="{p}" fill="none" stroke="{color}" stroke-width="{width}" {d} {m}/>')

a(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" '
  f'font-family="Arial, \'Liberation Sans\', Helvetica, sans-serif">')
a("<defs>")
for name, col in [("ink", INK), ("purple", PURPLE), ("green", GREEN), ("amber", AMBER), ("blue", BLUE)]:
    a(f'<marker id="m-{name}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">'
      f'<path d="M0,0 L10,5 L0,10 z" fill="{col}"/></marker>')
    a(f'<marker id="s-{name}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">'
      f'<path d="M0,0 L10,5 L0,10 z" fill="{col}"/></marker>')
a("</defs>")
a(f'<rect width="{W}" height="{H}" fill="#ffffff"/>')

# Título
text(20, 34, "Arquitectura distribuida de NodoTec (implementación desplegada)", 20, "bold")
text(20, 56, "Todos los componentes corren en contenedores Docker en un único VPS, levantados con el mismo docker-compose.yml que en el entorno local.", 12, fill=MUTED)

# ---------- Usuarios ----------
box(20, 190, 220, 100, "Visitante / Cliente", ["Navegador web", "https://nodotec.tech", "Stripe.js · SDK de PayPal"], BORDER)
box(20, 330, 220, 82, "Administrador", ["Navegador web", "https://nodotec.tech/admin/"], BORDER)
text(130, 450, "DNS: nodotec.tech → 179.197.91.34", 11, anchor="middle", fill=MUTED)
text(130, 466, "(registros A y AAAA)", 11, anchor="middle", fill=MUTED)

# ---------- VPS y red Docker ----------
a(f'<rect x="262" y="80" width="1078" height="760" rx="12" fill="#f6f8fa" stroke="{BORDER}" stroke-width="2"/>')
text(282, 104, "VPS Hostinger KVM 1 · Ubuntu 24.04 LTS · 1 vCPU · 4 GB RAM · IP 179.197.91.34", 14, "bold")
text(282, 124, "Firewall UFW: solo 22 (SSH con llave), 80 y 443 · Docker 29.9 + Docker Compose v5.6 · /opt/proyecto-ecommerce", 11.5, fill=MUTED)
a(f'<rect x="280" y="140" width="1042" height="684" rx="10" fill="#ffffff" stroke="{BLUE}" stroke-width="1.5" stroke-dasharray="8 5"/>')
text(296, 162, "Red interna de Docker «ecommerce» — solo el Gateway publica puertos hacia Internet (80 y 443)", 12, "bold", BLUE)

# Gateway y certbot
box(300, 180, 810, 92, "API Gateway — Nginx 1.27 (Alpine) · puertos 80 y 443", [
    "HTTPS con Let's Encrypt (TLS 1.2/1.3, HTTP/2) · HTTP :80 → 301 a HTTPS · www.nodotec.tech → nodotec.tech",
    "Sirve el frontend (tienda pública y /admin/) · reverse proxy por ruta · /api/*/interno/* y /api/auth/verify → 403",
], BLUE, fill="#f2f7fa")
box(1130, 180, 172, 92, "certbot v3.0.1", ["Certificado Let's Encrypt", "renovación diaria (cron)", "perfil «certbot»"], MUTED, dash=True, tsize=12)
arrow([(1130, 226), (1112, 226)], MUTED, width=1.5, marker="ink")

# Usuarios → Gateway
arrow([(240, 240), (300, 216)], INK)
arrow([(240, 371), (270, 371), (270, 252), (300, 252)], INK)
text(256, 304, "HTTPS", 10.5, "bold", anchor="middle", style='transform="rotate(-90 256 304)"')

# ---------- Microservicios ----------
a(f'<rect x="300" y="322" width="1002" height="288" rx="10" fill="#fbfbfc" stroke="{BORDER}" stroke-width="1.5"/>')

xs = [315, 517, 719, 921, 1123]
bw, by, bh = 162, 358, 112
svc = [
    ("auth-service :8081", ["BD: auth_db", "JWT HS256 (2 h / 8 h)", "registro · login · logout"]),
    ("catalogo-service :8082", ["BD: catalogo_db", "productos · stock", "lee del Slave · Cloudinary"]),
    ("pedidos-service :8083", ["BD: pedidos_db", "pedidos · estados · QR", "reportes (Slave) · clima"]),
    ("pagos-service :8084", ["BD: pagos_db", "Stripe · PayPal", "clave de idempotencia"]),
    ("notificaciones-service :8085", ["sin base de datos", "correo de confirmación", "solo ruta interna"]),
]
for x, (t, ls) in zip(xs, svc):
    box(x, by, bw, bh, t, ls, BORDER, tsize=11.5, lsize=11)

# Gateway → servicios (rutas públicas)
rutas = ["/api/auth/", "/api/catalogo/", "/api/pedidos", "/api/pagos/"]
for x, r in zip(xs[:4], rutas):
    cx = x + bw / 2
    arrow([(cx, 272), (cx, by)], INK)
    text(cx + 7, 304, r, 10.5, "bold", MUTED)

# Llamadas internas con JWT (púrpura)
def num(x, y, n):
    a(f'<circle cx="{x}" cy="{y}" r="9" fill="{PURPLE}"/>')
    text(x, y + 4, str(n), 11, "bold", "#ffffff", "middle")

# ② pedidos → catálogo (stock)
arrow([(719, 398), (679, 398)], PURPLE, width=2, marker="purple"); num(699, 384 - 2, 2)
# ③ pedidos → pagos (cobro) y ④ pagos → pedidos (pagado)
arrow([(881, 398), (921, 398)], PURPLE, width=2, marker="purple"); num(901, 382, 3)
arrow([(921, 440), (881, 440)], PURPLE, width=2, marker="purple"); num(901, 426, 4)
# ⑤ pagos → notificaciones (correo)
arrow([(1083, 418), (1123, 418)], PURPLE, width=2, marker="purple"); num(1103, 402, 5)
# ① bus de verificación hacia auth
busy = by + bh + 26
for x in xs[1:]:
    a(f'<line x1="{x + bw / 2}" y1="{by + bh}" x2="{x + bw / 2}" y2="{busy}" stroke="{PURPLE}" stroke-width="2"/>')
a(f'<line x1="{xs[4] + bw / 2}" y1="{busy}" x2="{xs[0] + bw / 2}" y2="{busy}" stroke="{PURPLE}" stroke-width="2"/>')
arrow([(xs[0] + bw / 2, busy), (xs[0] + bw / 2, by + bh)], PURPLE, width=2, marker="purple")
num(xs[0] + bw / 2 + 40, busy, 1)
text(xs[0] + bw / 2 + 56, busy + 18, "POST /api/auth/verify", 10.5, "bold", PURPLE)

text(316, 534, "Microservicios — PHP 8.3 + Apache (imagen php:8.3-apache), un contenedor por servicio.", 12, "bold", BORDER)
text(316, 554, "Llamadas internas: viajan directo por la red de Docker (sin pasar por el Gateway) y reenvían el JWT del usuario (Authorization: Bearer).", 11, fill=INK)
text(316, 572, "(1) cada servicio receptor valida el token con auth-service   (2) pedidos → catálogo: verificar y descontar stock   (3) pedidos → pagos: cobro", 11, fill=PURPLE)
text(316, 590, "(4) pagos → pedidos: marcar el pedido como pagado   (5) pagos → notificaciones: correo de confirmación (si falla, la compra continúa)", 11, fill=PURPLE)

# ---------- Datos ----------
dy, dh = 672, 132
box(315, dy, 300, dh, "MariaDB Master 10.6 · :3306", [
    "server-id=1 · binlog en formato ROW",
    "auth_db · catalogo_db · pedidos_db · pagos_db",
    "recibe todas las escrituras",
    "volumen master-data",
], GREEN, fill="#f4f9f5")
box(690, dy, 280, dh, "MariaDB Slave 10.6 · :3306", [
    "server-id=2 · read-only",
    "lecturas del catálogo y de los reportes",
    "si no responde, se lee del Master",
    "volumen slave-data",
], GREEN, fill="#f4f9f5")
box(1045, dy, 257, dh, "Redis 7 (Alpine) · :6379", [
    "contraseña · persistencia AOF",
    "caché del catálogo (30 s)",
    "tokens revocados (logout)",
    "volumen redis-data",
], GREEN, fill="#f4f9f5")

arrow([(465, 610), (465, dy)], GREEN, width=2, marker="green")
text(473, 634, "escrituras: cada servicio en su BD,", 10.5, fill=GREEN)
text(473, 648, "con su propio usuario", 10.5, fill=GREEN)
arrow([(830, 610), (830, dy)], GREEN, width=2, marker="green")
text(838, 641, "lecturas", 10.5, fill=GREEN)
arrow([(1173, 610), (1173, dy)], GREEN, width=2, marker="green")
text(1181, 634, "caché del catálogo y", 10.5, fill=GREEN)
text(1181, 648, "tokens revocados", 10.5, fill=GREEN)
# Replicación
arrow([(615, 738), (690, 738)], GREEN, dash=True, width=2.2, marker="green")
text(652, 728, "replicación", 10, "bold", GREEN, "middle")
text(652, 755, "(binlog)", 10, "bold", GREEN, "middle")

# ---------- APIs externas ----------
text(1365, 160, "APIs externas (HTTPS)", 14, "bold", AMBER)
text(1365, 176, "Las claves secretas están solo en el .env del servidor", 10.5, fill=MUTED)
apis = [
    ("Stripe (sandbox)", ["pagos-service: PaymentIntent", "navegador: Stripe.js tokeniza"]),
    ("PayPal Sandbox", ["pagos-service: crear y capturar", "navegador: aprobación PayPal"]),
    ("EmailJS", ["notificaciones-service: API REST", "correo al cliente"]),
    ("Cloudinary", ["catalogo-service: subida firmada", "navegador: imágenes del CDN"]),
    ("OpenWeatherMap", ["pedidos-service: clima del", "dashboard (caché 10 min)"]),
    ("QR Code API (api.qrserver.com)", ["pedidos-service: URL del QR", "navegador: carga la imagen"]),
]
ay = 190
for t, ls in apis:
    box(1365, ay, 335, 86, t, ls, AMBER, fill="#fdf9f1", tsize=12.5)
    ay += 86 + 12
# Servicios → APIs externas
a(f'<line x1="1352" y1="232" x2="1352" y2="726" stroke="{AMBER}" stroke-width="2"/>')
for i in range(6):
    yy = 190 + i * 98 + 43
    arrow([(1352, yy), (1365, yy)], AMBER, width=2, marker="amber")
arrow([(1302, 470), (1352, 470)], AMBER, width=2, marker="amber")

# ---------- Leyenda ----------
ly = 878
a(f'<rect x="20" y="{ly - 22}" width="1680" height="100" rx="8" fill="#f6f8fa" stroke="#e1e6eb"/>')
text(36, ly, "Leyenda", 12.5, "bold")
items = [
    (INK, False, "Petición del usuario (HTTPS) a través del Gateway"),
    (PURPLE, False, "Llamada interna entre servicios con JWT"),
    (GREEN, False, "Acceso a datos (MariaDB / Redis)"),
    (GREEN, True, "Replicación Master → Slave"),
    (AMBER, False, "Llamada a API externa"),
]
lx, row = 36, 0
for col, dash, lab in items:
    yy = ly + 24 + row * 24
    arrow([(lx, yy - 4), (lx + 46, yy - 4)], col, dash=dash, width=2,
          marker={INK: "ink", PURPLE: "purple", GREEN: "green", AMBER: "amber"}[col])
    text(lx + 56, yy, lab, 11.5)
    lx += 335
text(36, ly + 62, "Puertos de los servicios (8081–8085), de MariaDB (3306) y de Redis (6379) son internos: no se publican. Rutas internas: /api/*/interno/* y /api/auth/verify (bloqueadas en el Gateway).", 11, fill=MUTED)

a("</svg>")
svg = "\n".join(o)
out = sys.argv[1]
open(out + ".svg", "w", encoding="utf-8").write(svg)
cairosvg.svg2png(bytestring=svg.encode("utf-8"), write_to=out + ".png", output_width=W * 2)
print("ok")
