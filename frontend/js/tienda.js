/**
 * NodoTec — página principal: destacados, catálogo con filtros y paginación, detalle.
 * El catálogo se ve SIN registrarse (GET públicos de catalogo-service, leídos del Slave).
 */
const POR_PAGINA = 8;
let paginaActual = 1;
const productosCargados = new Map(); // id -> producto, para el modal y el carrito

function tarjeta(p) {
  productosCargados.set(p.id, p);
  const agotado = p.stock === 0;
  return `
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card producto h-100 shadow-sm">
      <a href="#" class="foto ver-detalle" data-id="${p.id}"><img src="${esc(p.imagen_url)}" alt="${esc(p.nombre)}" loading="lazy"></a>
      <div class="card-body d-flex flex-column">
        <span class="small text-muted">${esc(p.categoria)}</span>
        <h3 class="h6 nombre"><a href="#" class="text-reset text-decoration-none ver-detalle" data-id="${p.id}">${esc(p.nombre)}</a></h3>
        <div class="mt-auto d-flex justify-content-between align-items-center">
          <span class="precio">${moneda(p.precio)}</span>
          <button class="btn btn-sm btn-nodotec agregar" data-id="${p.id}" ${agotado ? 'disabled' : ''}>
            ${agotado ? 'Agotado' : '<i class="bi bi-cart-plus"></i>'}
          </button>
        </div>
      </div>
    </div>
  </div>`;
}

function conectarBotones(contenedor) {
  contenedor.querySelectorAll('.agregar').forEach(b => b.addEventListener('click', () => {
    agregarAlCarrito(productosCargados.get(+b.dataset.id));
    aviso('Producto agregado al carrito');
  }));
  contenedor.querySelectorAll('.ver-detalle').forEach(a => a.addEventListener('click', (e) => {
    e.preventDefault();
    mostrarDetalle(+a.dataset.id);
  }));
}

async function cargarDestacados() {
  const cont = document.getElementById('destacados');
  const { codigo, datos } = await api('GET', '/api/catalogo/destacados');
  if (codigo !== 200) { cont.innerHTML = '<p class="text-muted">No se pudieron cargar los destacados.</p>'; return; }
  cont.innerHTML = datos.productos.map(tarjeta).join('');
  conectarBotones(cont);
}

async function cargarCatalogo(pagina = 1) {
  paginaActual = pagina;
  const cont = document.getElementById('productos');
  const params = new URLSearchParams({ pagina, por_pagina: POR_PAGINA });
  const categoria = document.getElementById('filtroCategoria').value;
  const precio = document.getElementById('filtroPrecio').value;
  if (categoria) params.set('categoria', categoria);
  if (precio) params.set('precio_max', precio);

  const { codigo, datos } = await api('GET', '/api/catalogo/productos?' + params);
  if (codigo !== 200) {
    cont.innerHTML = '<div class="alert alert-warning">El catálogo no está disponible en este momento. Intenta de nuevo en unos segundos.</div>';
    return;
  }
  cont.innerHTML = datos.productos.length
    ? datos.productos.map(tarjeta).join('')
    : '<p class="text-muted">No hay productos con esos filtros.</p>';
  conectarBotones(cont);

  const pag = document.getElementById('paginacion');
  pag.innerHTML = Array.from({ length: datos.total_paginas }, (_, i) => i + 1).map(n => `
    <li class="page-item ${n === pagina ? 'active' : ''}"><a class="page-link" href="#catalogo" data-pagina="${n}">${n}</a></li>`).join('');
  pag.querySelectorAll('a').forEach(a => a.addEventListener('click', () => cargarCatalogo(+a.dataset.pagina)));
}

async function cargarCategorias() {
  const { codigo, datos } = await api('GET', '/api/catalogo/categorias');
  if (codigo !== 200) return;
  document.getElementById('filtroCategoria').insertAdjacentHTML('beforeend',
    datos.categorias.map(c => `<option value="${c.id}">${esc(c.nombre)}</option>`).join(''));
}

async function mostrarDetalle(id) {
  const { codigo, datos } = await api('GET', '/api/catalogo/productos/' + id);
  if (codigo !== 200) { aviso('Producto no disponible', 'danger'); return; }
  const p = datos.producto;
  productosCargados.set(p.id, p);
  document.getElementById('contenidoProducto').innerHTML = `
    <div class="modal-header border-0"><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body pt-0">
      <div class="row g-4 align-items-center">
        <div class="col-md-6 text-center"><img src="${esc(p.imagen_url)}" class="img-fluid detalle-img" alt="${esc(p.nombre)}"></div>
        <div class="col-md-6">
          <span class="badge text-bg-light">${esc(p.categoria)}</span>
          <h2 class="h4 mt-2">${esc(p.nombre)}</h2>
          <p class="text-muted">${esc(p.descripcion)}</p>
          <div class="h4 precio">${moneda(p.precio)}</div>
          <p class="small ${p.stock ? 'text-success' : 'text-danger'}">${p.stock ? `${p.stock} unidades disponibles` : 'Agotado'}</p>
          <div class="d-flex gap-2">
            <input id="cantDetalle" type="number" min="1" max="${p.stock}" value="1" class="form-control" style="width:90px" ${p.stock ? '' : 'disabled'} aria-label="Cantidad">
            <button id="agregarDetalle" class="btn btn-nodotec flex-grow-1" ${p.stock ? '' : 'disabled'}><i class="bi bi-cart-plus"></i> Agregar al carrito</button>
          </div>
        </div>
      </div>
    </div>`;
  document.getElementById('agregarDetalle').addEventListener('click', () => {
    const cantidad = Math.max(1, Math.min(+document.getElementById('cantDetalle').value || 1, p.stock));
    agregarAlCarrito(p, cantidad);
    bootstrap.Modal.getInstance(document.getElementById('modalProducto')).hide();
    aviso('Producto agregado al carrito');
  });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProducto')).show();
}

document.addEventListener('DOMContentLoaded', () => {
  cargarCategorias();
  cargarDestacados();
  cargarCatalogo(1);
  document.getElementById('filtroCategoria').addEventListener('change', () => cargarCatalogo(1));
  document.getElementById('filtroPrecio').addEventListener('change', () => cargarCatalogo(1));
});
