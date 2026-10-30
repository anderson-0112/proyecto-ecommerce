/**
 * NodoTec — panel de administración.
 * Todas las acciones usan el JWT del admin; cada servicio lo verifica con auth-service
 * y exige rol "admin" para escribir (403 en otro caso).
 */
const SIGUIENTE = { pagado: 'preparando', preparando: 'enviado', enviado: 'entregado' }; // D1
let pedidos = [];
let productos = [];
let categorias = [];
let graficaVentas, graficaEstados;

/* ---------------- Acceso ---------------- */
function esAdmin() { return leerSesion()?.usuario?.rol === 'admin'; }

function mostrarVista() {
  document.getElementById('vistaLogin').classList.toggle('d-none', esAdmin());
  document.getElementById('vistaPanel').classList.toggle('d-none', !esAdmin());
  if (esAdmin()) cargarTodo();
}

/** Si el token expiró o fue revocado, vuelve a la pantalla de acceso. */
function revisarSesion(r) {
  if (r.codigo === 401 || r.codigo === 403) { borrarSesion(); mostrarVista(); return false; }
  return true;
}

document.getElementById('formLogin').addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = await api('POST', '/api/auth/login', {
    email: document.getElementById('email').value, password: document.getElementById('password').value,
  });
  const error = document.getElementById('errorLogin');
  if (r.codigo !== 200) { error.textContent = r.datos?.error || 'No se pudo ingresar'; return; }
  if (r.datos.usuario.rol !== 'admin') { error.textContent = 'Esta cuenta no es de administrador.'; return; }
  guardarSesion(r.datos);
  mostrarVista();
});

document.getElementById('btnSalir').addEventListener('click', async () => { await cerrarSesion(); mostrarVista(); });

async function cargarTodo() {
  await Promise.all([cargarPedidos(), cargarCategorias()]);
  await cargarProductos();
}

/* ---------------- Dashboard (Chart.js) ---------------- */
const esVenta = (p) => p.estado !== 'pendiente';
const diaLocal = (d) => d.toLocaleDateString('en-CA'); // AAAA-MM-DD en hora local

function pintarDashboard() {
  const ahora = new Date();
  const hoy = diaLocal(ahora);
  const hace7 = new Date(ahora - 6 * 864e5); hace7.setHours(0, 0, 0, 0);
  const inicioMes = new Date(ahora.getFullYear(), ahora.getMonth(), 1);
  const ventas = pedidos.filter(esVenta);
  const suma = (lista) => lista.reduce((s, p) => s + p.total, 0);

  document.getElementById('kpiHoy').textContent = moneda(suma(ventas.filter(p => diaLocal(fechaUTC(p.fecha)) === hoy)));
  document.getElementById('kpiSemana').textContent = moneda(suma(ventas.filter(p => fechaUTC(p.fecha) >= hace7)));
  document.getElementById('kpiMes').textContent = moneda(suma(ventas.filter(p => fechaUTC(p.fecha) >= inicioMes)));
  document.getElementById('kpiPorPreparar').textContent = pedidos.filter(p => p.estado === 'pagado').length;

  // Ventas por día de los últimos 14 días
  const dias = Array.from({ length: 14 }, (_, i) => diaLocal(new Date(ahora - (13 - i) * 864e5)));
  const porDia = Object.fromEntries(dias.map(d => [d, 0]));
  ventas.forEach(p => { const d = diaLocal(fechaUTC(p.fecha)); if (d in porDia) porDia[d] += p.total; });

  graficaVentas?.destroy();
  graficaVentas = new Chart(document.getElementById('graficaVentas'), {
    type: 'bar',
    data: { labels: dias.map(d => d.slice(5)), datasets: [{ label: 'Ventas (USD)', data: dias.map(d => porDia[d]), backgroundColor: '#1f7ae0' }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
  });

  graficaEstados?.destroy();
  graficaEstados = new Chart(document.getElementById('graficaEstados'), {
    type: 'doughnut',
    data: {
      labels: ESTADOS,
      datasets: [{ data: ESTADOS.map(e => pedidos.filter(p => p.estado === e).length),
                   backgroundColor: ['#adb5bd', '#0d6efd', '#ffc107', '#0dcaf0', '#198754'] }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
  });
}

/* ---------------- Exportar reporte a PDF (jsPDF, D19) ---------------- */
document.getElementById('btnPdf').addEventListener('click', () => {
  const ahora = new Date();
  const inicioMes = new Date(ahora.getFullYear(), ahora.getMonth(), 1);
  const delMes = pedidos.filter(p => esVenta(p) && fechaUTC(p.fecha) >= inicioMes).sort((a, b) => a.id - b.id);
  const total = delMes.reduce((s, p) => s + p.total, 0);
  const mes = ahora.toLocaleDateString('es-GT', { month: 'long', year: 'numeric' });

  const doc = new jspdf.jsPDF();
  doc.setFontSize(16);
  doc.text('NodoTec — Reporte de ventas', 14, 18);
  doc.setFontSize(10);
  doc.text(`Periodo: ${mes} · Generado: ${ahora.toLocaleString('es-GT')}`, 14, 25);
  doc.autoTable({
    startY: 30,
    head: [['Pedido', 'Fecha', 'Cliente', 'Estado', 'Total (USD)']],
    body: delMes.map(p => [`#${p.id}`, fechaLocal(p.fecha), p.cliente, p.estado, p.total.toFixed(2)]),
    foot: [['', '', '', `${delMes.length} pedidos`, total.toFixed(2)]],
    headStyles: { fillColor: [15, 42, 74] },
    footStyles: { fillColor: [230, 236, 245], textColor: 20 },
  });
  doc.save(`ventas-nodotec-${diaLocal(ahora).slice(0, 7)}.pdf`);
});

/* ---------------- Pedidos ---------------- */
async function cargarPedidos() {
  const r = await api('GET', '/api/pedidos'); // admin: todos, leídos del Slave
  if (!revisarSesion(r)) return;
  pedidos = r.datos?.pedidos ?? [];
  pintarDashboard();
  pintarPedidos();
}

function pintarPedidos() {
  const filtro = document.getElementById('filtroEstado').value;
  const lista = filtro ? pedidos.filter(p => p.estado === filtro) : pedidos;
  document.getElementById('filasPedidos').innerHTML = lista.length === 0
    ? '<tr><td colspan="6" class="text-muted">Sin pedidos.</td></tr>'
    : lista.map(p => `
      <tr>
        <td>${p.id}</td><td class="small">${fechaLocal(p.fecha)}</td>
        <td>${esc(p.cliente)}<div class="small text-muted">${esc(p.email)}</div></td>
        <td>${etiquetaEstado(p.estado)}</td><td class="text-end">${moneda(p.total)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-primary ver-pedido" data-id="${p.id}">Ver</button></td>
      </tr>`).join('');
  document.querySelectorAll('.ver-pedido').forEach(b => b.addEventListener('click', () => verPedido(+b.dataset.id)));
}

async function verPedido(id) {
  const r = await api('GET', '/api/pedidos/' + id);
  if (!revisarSesion(r)) return;
  const p = r.datos.pedido;
  const siguiente = SIGUIENTE[p.estado];
  // D12: comunicarse con el cliente por correo con asunto prellenado
  const asunto = encodeURIComponent(`NodoTec — Tu pedido #${p.id}`);
  const cuerpo = encodeURIComponent(`Hola ${p.cliente.nombre},\n\nTe escribimos sobre tu pedido #${p.id} (estado: ${p.estado}).\n\n`);
  document.getElementById('contenidoPedido').innerHTML = `
    <div class="modal-header"><h2 class="modal-title h5">Pedido #${p.id} ${etiquetaEstado(p.estado)}</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-8">
          <p class="mb-1"><b>${esc(p.cliente.nombre)}</b> · ${esc(p.cliente.email)} ${p.cliente.telefono ? '· ' + esc(p.cliente.telefono) : ''}</p>
          ${p.direccion ? `<p class="small text-muted"><i class="bi bi-geo-alt"></i> ${esc(p.direccion.direccion)}, ${esc(p.direccion.ciudad)}</p>` : ''}
          <p class="small text-muted">Fecha: ${fechaLocal(p.fecha)}</p>
          <table class="table table-sm">
            <thead><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th></tr></thead>
            ${p.detalle.map(d => `<tr><td>${esc(d.nombre_producto)}</td><td class="text-end">${d.cantidad}</td>
              <td class="text-end">${moneda(d.precio_unitario)}</td><td class="text-end">${moneda(d.subtotal)}</td></tr>`).join('')}
            <tr class="fw-bold"><td colspan="3">Total</td><td class="text-end">${moneda(p.total)}</td></tr>
          </table>
        </div>
        <div class="col-md-4 text-center">${p.qr_url ? `<img src="${esc(p.qr_url)}" width="150" height="150" alt="QR del pedido">` : ''}</div>
      </div>
      <div id="errorEstado" class="text-danger small"></div>
    </div>
    <div class="modal-footer justify-content-between">
      <a class="btn btn-outline-secondary" href="mailto:${esc(p.cliente.email)}?subject=${asunto}&body=${cuerpo}"><i class="bi bi-envelope"></i> Contactar al cliente</a>
      ${siguiente
        ? `<button id="btnAvanzar" class="btn btn-nodotec">Pasar a "${siguiente}"</button>`
        : `<span class="small text-muted">${p.estado === 'pendiente' ? 'Esperando el pago del cliente' : 'Pedido entregado'}</span>`}
    </div>`;
  document.getElementById('btnAvanzar')?.addEventListener('click', async () => {
    const r2 = await api('PUT', `/api/pedidos/${p.id}/estado`, { estado: siguiente });
    if (r2.codigo !== 200) { document.getElementById('errorEstado').textContent = r2.datos?.error || 'No se pudo cambiar el estado'; return; }
    aviso(`Pedido #${p.id} ahora está "${siguiente}"`);
    // El Slave puede tardar milisegundos en recibir el cambio: se actualiza la lista local
    const local = pedidos.find(x => x.id === p.id);
    if (local) local.estado = siguiente;
    pintarDashboard();
    pintarPedidos();
    verPedido(p.id);
  });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPedido')).show();
}

document.getElementById('filtroEstado').insertAdjacentHTML('beforeend', ESTADOS.map(e => `<option value="${e}">${e}</option>`).join(''));
document.getElementById('filtroEstado').addEventListener('change', pintarPedidos);

/* ---------------- Productos (CRUD) ---------------- */
async function cargarCategorias() {
  const r = await api('GET', '/api/catalogo/categorias');
  categorias = r.datos?.categorias ?? [];
  document.getElementById('prodCategoria').innerHTML = categorias.map(c => `<option value="${c.id}">${esc(c.nombre)}</option>`).join('');
}

async function cargarProductos() {
  productos = [];
  for (let pagina = 1, total = 1; pagina <= total; pagina++) { // el API pagina de 50 en 50
    const r = await api('GET', `/api/catalogo/productos?por_pagina=50&pagina=${pagina}`);
    if (r.codigo !== 200) break;
    productos.push(...r.datos.productos);
    total = r.datos.total_paginas;
  }
  document.getElementById('filasProductos').innerHTML = productos.map(p => `
    <tr>
      <td><img src="${esc(p.imagen_url)}" class="mini-img" alt=""></td>
      <td>${esc(p.nombre)}</td><td class="small">${esc(p.categoria)}</td>
      <td class="text-end">${moneda(p.precio)}</td>
      <td class="text-end ${p.stock < 5 ? 'text-danger fw-bold' : ''}">${p.stock}</td>
      <td>${p.destacado ? '<i class="bi bi-star-fill text-warning"></i>' : ''}</td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-outline-primary editar" data-id="${p.id}" aria-label="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-danger eliminar" data-id="${p.id}" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
      </td>
    </tr>`).join('');
  document.querySelectorAll('.editar').forEach(b => b.addEventListener('click', () => abrirFormulario(productos.find(p => p.id === +b.dataset.id))));
  document.querySelectorAll('.eliminar').forEach(b => b.addEventListener('click', () => eliminarProducto(+b.dataset.id)));
}

function abrirFormulario(p = null) {
  document.getElementById('tituloProducto').textContent = p ? 'Editar producto' : 'Nuevo producto';
  document.getElementById('prodId').value = p?.id ?? '';
  document.getElementById('prodNombre').value = p?.nombre ?? '';
  document.getElementById('prodDescripcion').value = p?.descripcion ?? '';
  document.getElementById('prodPrecio').value = p?.precio ?? '';
  document.getElementById('prodStock').value = p?.stock ?? '';
  document.getElementById('prodCategoria').value = p?.categoria_id ?? categorias[0]?.id;
  document.getElementById('prodImagen').value = p?.imagen_url ?? '';
  document.getElementById('prodDestacado').checked = p?.destacado ?? false;
  document.getElementById('errorProducto').textContent = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProducto')).show();
}

document.getElementById('btnNuevo').addEventListener('click', () => abrirFormulario());

document.getElementById('formProducto').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = document.getElementById('prodId').value;
  const datos = {
    nombre: document.getElementById('prodNombre').value,
    descripcion: document.getElementById('prodDescripcion').value,
    precio: Number(document.getElementById('prodPrecio').value),
    stock: Number(document.getElementById('prodStock').value),
    categoria_id: Number(document.getElementById('prodCategoria').value),
    destacado: document.getElementById('prodDestacado').checked,
  };
  const imagen = document.getElementById('prodImagen').value.trim();
  if (imagen) datos.imagen_url = imagen;

  const r = id ? await api('PUT', '/api/catalogo/productos/' + id, datos) : await api('POST', '/api/catalogo/productos', datos);
  if (!revisarSesion(r)) return;
  if (r.codigo !== 200 && r.codigo !== 201) {
    const campos = r.datos?.campos ? ': ' + Object.entries(r.datos.campos).map(([c, m]) => `${c} (${m})`).join(', ') : '';
    document.getElementById('errorProducto').textContent = (r.datos?.error || 'No se pudo guardar') + campos;
    return;
  }
  bootstrap.Modal.getInstance(document.getElementById('modalProducto')).hide();
  aviso(id ? 'Producto actualizado' : 'Producto creado');
  cargarProductos();
});

async function eliminarProducto(id) {
  const p = productos.find(x => x.id === id);
  if (!confirm(`¿Eliminar "${p.nombre}" del catálogo?`)) return;
  const r = await api('DELETE', '/api/catalogo/productos/' + id);
  if (!revisarSesion(r)) return;
  if (r.codigo !== 204) { aviso(r.datos?.error || 'No se pudo eliminar', 'danger'); return; }
  aviso('Producto eliminado');
  cargarProductos();
}

mostrarVista();
