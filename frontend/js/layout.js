/**
 * NodoTec — barra de navegación, carrito lateral y pie de página comunes a la tienda.
 * Cada página incluye <header id="cabecera"></header> y <footer id="pie"></footer>.
 */

function pintarCabecera() {
  const sesion = leerSesion();
  const usuario = sesion?.usuario;
  document.getElementById('cabecera').innerHTML = `
  <nav class="navbar navbar-expand-lg navbar-dark bg-nodotec sticky-top shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="/"><i class="bi bi-cpu"></i> NodoTec</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-label="Menú">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="menu">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link" href="/#catalogo">Catálogo</a></li>
          ${usuario ? '<li class="nav-item"><a class="nav-link" href="/mis-pedidos.html">Mis pedidos</a></li>' : ''}
          ${usuario?.rol === 'admin' ? '<li class="nav-item"><a class="nav-link" href="/admin/">Panel admin</a></li>' : ''}
        </ul>
        <div class="d-flex align-items-center gap-2">
          ${usuario
            ? `<span class="text-white-50 small d-none d-lg-inline">Hola, ${esc(usuario.nombre)}</span>
               <button id="btnSalir" class="btn btn-sm btn-outline-light">Salir</button>`
            : `<a href="/login.html" class="btn btn-sm btn-outline-light">Ingresar</a>`}
          <button class="btn btn-sm btn-warning position-relative" data-bs-toggle="offcanvas" data-bs-target="#carrito">
            <i class="bi bi-cart3"></i> Carrito
            <span id="contadorCarrito" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">0</span>
          </button>
        </div>
      </div>
    </div>
  </nav>

  <div class="offcanvas offcanvas-end" tabindex="-1" id="carrito">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title"><i class="bi bi-cart3"></i> Tu carrito</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
      <div id="itemsCarrito" class="flex-grow-1"></div>
      <div class="border-top pt-3">
        <div class="d-flex justify-content-between fw-bold mb-3"><span>Total</span><span id="totalCarrito"></span></div>
        <a href="/checkout.html" id="btnIrCheckout" class="btn btn-nodotec w-100">Ir a pagar</a>
      </div>
    </div>
  </div>`;

  document.getElementById('btnSalir')?.addEventListener('click', async () => {
    await cerrarSesion();
    location.href = '/';
  });
  pintarCarrito();
}

function pintarCarrito() {
  const items = leerCarrito();
  const contador = document.getElementById('contadorCarrito');
  contador.textContent = cantidadEnCarrito();
  contador.classList.toggle('d-none', cantidadEnCarrito() === 0);
  document.getElementById('totalCarrito').textContent = moneda(totalCarrito());
  document.getElementById('btnIrCheckout').classList.toggle('disabled', items.length === 0);
  document.getElementById('itemsCarrito').innerHTML = items.length === 0
    ? '<p class="text-muted text-center mt-4">Tu carrito está vacío.</p>'
    : items.map(i => `
      <div class="d-flex gap-2 align-items-center mb-3">
        <img src="${esc(i.imagen_url)}" class="mini-img" alt="">
        <div class="flex-grow-1 small">
          <div class="fw-semibold">${esc(i.nombre)}</div>
          <div class="text-muted">${moneda(i.precio)}</div>
        </div>
        <input type="number" min="1" max="${i.stock}" value="${i.cantidad}" data-id="${i.id}"
               class="form-control form-control-sm cant-carrito" style="width:64px" aria-label="Cantidad">
        <button class="btn btn-sm btn-link text-danger quitar" data-id="${i.id}" aria-label="Quitar"><i class="bi bi-trash"></i></button>
      </div>`).join('');

  document.querySelectorAll('.cant-carrito').forEach(input => input.addEventListener('change', () => {
    const items = leerCarrito();
    const item = items.find(i => i.id === +input.dataset.id);
    item.cantidad = Math.max(1, Math.min(+input.value || 1, item.stock));
    guardarCarrito(items);
  }));
  document.querySelectorAll('.quitar').forEach(b => b.addEventListener('click', () => {
    guardarCarrito(leerCarrito().filter(i => i.id !== +b.dataset.id));
  }));
}

function pintarPie() {
  document.getElementById('pie').innerHTML = `
  <div class="container py-4 small text-white-50 d-flex flex-wrap justify-content-between gap-2">
    <span><b class="text-white">NodoTec</b> · Tienda de Electrónica y Tecnología (tienda ficticia con fines académicos)</span>
    <span>Pagos de prueba con Stripe y PayPal · Imágenes en Cloudinary (fotos de <a class="link-light" href="https://commons.wikimedia.org" target="_blank" rel="noopener">Wikimedia Commons</a>)</span>
  </div>`;
}

document.addEventListener('carrito', pintarCarrito);
document.addEventListener('DOMContentLoaded', () => { pintarCabecera(); pintarPie(); });
