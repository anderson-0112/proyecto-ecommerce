# Utilidades comunes para dibujar los diagramas del informe en SVG (mismo estilo que la Fig. 1).
import cairosvg

INK, BORDER, MUTED, LINE = "#1f2a36", "#3a4a5c", "#5b6673", "#e1e6eb"
BLUE, GREEN, PURPLE, AMBER, RED = "#2f6b8a", "#3f7d4f", "#7a3f6e", "#8a5a00", "#b03a2e"
FONT = "Arial, 'Liberation Sans', Helvetica, sans-serif"


def esc(t):
    return str(t).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


class Svg:
    def __init__(self, w, h):
        self.w, self.h, self.o = w, h, []
        a = self.o.append
        a(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" font-family="{FONT}">')
        a("<defs>")
        for name, col in [("ink", INK), ("purple", PURPLE), ("green", GREEN), ("amber", AMBER), ("blue", BLUE), ("muted", MUTED)]:
            a(f'<marker id="m-{name}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" '
              f'orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{col}"/></marker>')
        a("</defs>")
        a(f'<rect width="{w}" height="{h}" fill="#ffffff"/>')

    def raw(self, s):
        self.o.append(s)

    def text(self, x, y, t, size=11, weight="normal", fill=INK, anchor="start", italic=False):
        st = ' font-style="italic"' if italic else ""
        self.o.append(f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{fill}" '
                      f'text-anchor="{anchor}"{st}>{esc(t)}</text>')

    def rect(self, x, y, w, h, stroke=BORDER, fill="#ffffff", rx=8, sw=2, dash=None):
        d = f' stroke-dasharray="{dash}"' if dash else ""
        self.o.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{d}/>')

    def box(self, x, y, w, h, title, lines, color, fill="#ffffff", dash=False, tsize=13, lsize=11, center=True):
        self.rect(x, y, w, h, color, fill, dash="6 4" if dash else None)
        self.o.append(f'<rect x="{x}" y="{y}" width="{w}" height="6" rx="3" fill="{color}"/>')
        cx = x + w / 2 if center else x + 12
        anc = "middle" if center else "start"
        self.text(cx, y + 26, title, tsize, "bold", color, anc)
        for i, l in enumerate(lines):
            self.text(cx, y + 26 + 17 * (i + 1), l, lsize, "normal", INK, anc)

    def line(self, pts, color=INK, dash=False, width=1.8, arrow=None):
        d = ' stroke-dasharray="7 5"' if dash else ""
        p = " ".join(f"{x},{y}" for x, y in pts)
        m = f' marker-end="url(#m-{arrow})"' if arrow else ""
        self.o.append(f'<polyline points="{p}" fill="none" stroke="{color}" stroke-width="{width}"{d}{m}/>')

    def path(self, d, color=INK, dash=False, width=1.8, arrow=None):
        da = ' stroke-dasharray="7 5"' if dash else ""
        m = f' marker-end="url(#m-{arrow})"' if arrow else ""
        self.o.append(f'<path d="{d}" fill="none" stroke="{color}" stroke-width="{width}"{da}{m}/>')

    def note(self, x, y, w, lines, size=11, color=AMBER, fill="#fdf9f1"):
        h = 14 + 16 * len(lines)
        self.rect(x, y, w, h, color, fill, rx=6, sw=1.5)
        for i, l in enumerate(lines):
            self.text(x + 10, y + 21 + 16 * i, l, size)
        return h

    def save(self, base, scale=2):
        self.o.append("</svg>")
        svg = "\n".join(self.o)
        open(base + ".svg", "w", encoding="utf-8").write(svg)
        cairosvg.svg2png(bytestring=svg.encode("utf-8"), write_to=base + ".png", output_width=self.w * scale)
        return svg
