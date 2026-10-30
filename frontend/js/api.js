/**
 * NodoTec — utilidades compartidas por la tienda y el panel admin.
 * Todas las peticiones van al API Gateway (misma URL que la página: /api/...).
 */

const SESION_KEY = 'nodotec_sesion';
const CARRITO_KEY = 'nodotec_carrito';

/* ---------- Sesión (JWT guardado en el navegador) ---------- */
function leerSesion() {
  try { return JSON.parse(localStorage.getItem(SESION_KEY)) || null; } catch { return null; }
}
function guardarSesion(datos) { localStorage.setItem(SESION_KEY, JSON.stringify(datos)); }
function borrarSesion() { localStorage.removeItem(SESION_KEY); }

/**
 * Llama a la API. Agrega el JWT si hay sesión. Devuelve { codigo, datos }.
 * Si el token expiró o fue revocado (401), se cierra la sesión local.
 */
async function api(metodo, ruta, cuerpo) {
  const sesion = leerSesion();
  const opciones = { method: metodo, headers: { 'Content-Type': 'application/json' } };
  if (sesion?.token) opciones.headers.Authorization = 'Bearer ' + sesion.token;
  if (cuerpo !== undefined) opciones.body = JSON.stringify(cuerpo);
  let res;
  try {
    res = await fetch(ruta, opciones);
  } catch {
    return { codigo: 0, datos: { error: 'No hay conexión con el servidor' } };
  }
  const datos = res.status === 204 ? null : await res.json().catch(() => null);
  if (res.status === 401 && sesion && !ruta.startsWith('/api/auth/')) borrarSesion();
  return { codigo: res.status, datos };
}

async function cerrarSesion() {
  await api('POST', '/api/auth/logout'); // revoca el token en Redis
  borrarSesion();
}

/* ---------- Carrito (en el navegador; el servidor recalcula precios) ---------- */
function leerCarrito() {
  try { return JSON.parse(localStorage.getItem(CARRITO_KEY)) || []; } catch { return []; }
}
function guardarCarrito(items) {
  localStorage.setItem(CARRITO_KEY, JSON.stringify(items));
  document.dispatchEvent(new Event('carrito'));
}
function agregarAlCarrito(producto, cantidad = 1) {
  const items = leerCarrito();
  const existente = items.find(i => i.id === producto.id);
  if (existente) existente.cantidad = Math.min(existente.cantidad + cantidad, producto.stock);
  else items.push({ id: producto.id, nombre: producto.nombre, precio: producto.precio,
                    imagen_url: producto.imagen_url, stock: producto.stock, cantidad });
  guardarCarrito(items);
}
function cantidadEnCarrito() { return leerCarrito().reduce((s, i) => s + i.cantidad, 0); }
function totalCarrito() { return leerCarrito().reduce((s, i) => s + i.precio * i.cantidad, 0); }

/* ---------- Formato y seguridad ---------- */
const moneda = (n) => 'US$ ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Escapa texto antes de insertarlo como HTML (evita XSS con datos de la BD). */
function esc(texto) {
  return String(texto ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Las fechas de la BD están en UTC ("2026-10-30 17:11:34"); se muestran en hora local. */
const fechaUTC = (f) => new Date(f.replace(' ', 'T') + 'Z');
const fechaLocal = (f) => fechaUTC(f).toLocaleString('es-GT', { dateStyle: 'medium', timeStyle: 'short' });

const ESTADOS = ['pendiente', 'pagado', 'preparando', 'enviado', 'entregado'];
const COLOR_ESTADO = { pendiente: 'secondary', pagado: 'primary', preparando: 'warning', enviado: 'info', entregado: 'success' };
const etiquetaEstado = (e) => `<span class="badge text-bg-${COLOR_ESTADO[e] || 'light'}">${esc(e)}</span>`;

/** Mensaje flotante (toast) simple. */
function aviso(mensaje, tipo = 'success') {
  let zona = document.getElementById('zona-avisos');
  if (!zona) {
    zona = document.createElement('div');
    zona.id = 'zona-avisos';
    zona.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    document.body.appendChild(zona);
  }
  const t = document.createElement('div');
  t.className = `toast align-items-center text-bg-${tipo} border-0`;
  t.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(mensaje)}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  zona.appendChild(t);
  new bootstrap.Toast(t, { delay: 3500 }).show();
  t.addEventListener('hidden.bs.toast', () => t.remove());
}
