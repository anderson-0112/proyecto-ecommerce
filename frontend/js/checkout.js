/**
 * NodoTec — checkout (D-PAGO opción B):
 * 1) Stripe.js convierte la tarjeta en un PaymentMethod "pm_..." (la tarjeta no llega a NodoTec).
 * 2) POST /api/pedidos con productos + dirección + pm_...: pedidos-service verifica stock,
 *    crea el pedido y llama a pagos-service, que cobra con Stripe.
 * 3) Si el banco pide 3D Secure (202), se resuelve aquí y se confirma con POST /api/pagos/stripe.
 */
let stripe, tarjeta;
let pedidoPendiente = null; // si el pago fue rechazado, se reintenta sobre el mismo pedido (D2)

function pintarResumen() {
  const items = leerCarrito();
  document.getElementById('resumen').innerHTML = items.map(i => `
    <div class="d-flex gap-2 align-items-center mb-2 small">
      <img src="${esc(i.imagen_url)}" class="mini-img" alt="">
      <span class="flex-grow-1">${esc(i.nombre)} <span class="text-muted">× ${i.cantidad}</span></span>
      <span>${moneda(i.precio * i.cantidad)}</span>
    </div>`).join('');
  document.getElementById('total').textContent = moneda(totalCarrito());
}

function mostrarError(texto) {
  const caja = document.getElementById('error');
  caja.textContent = texto;
  caja.classList.remove('d-none');
}

function mostrarConfirmacion(pedido, correoMensaje) {
  guardarCarrito([]); // compra terminada: se vacía el carrito
  document.getElementById('vistaCheckout').classList.add('d-none');
  document.getElementById('vistaConfirmacion').classList.remove('d-none');
  document.getElementById('tituloConfirmacion').textContent = `¡Gracias! Tu pedido #${pedido.id} está ${pedido.estado}`;
  document.getElementById('textoConfirmacion').textContent = `Total cobrado: ${moneda(pedido.total)}. ${correoMensaje || ''}`;
  if (pedido.qr_url) document.getElementById('qr').src = pedido.qr_url; // QR de api.qrserver.com
  document.getElementById('linkSeguimiento').href = '/seguimiento.html?pedido=' + pedido.id;
}

async function pagar(e) {
  e.preventDefault();
  document.getElementById('error').classList.add('d-none');
  const boton = document.getElementById('btnPagar');
  boton.disabled = true;
  boton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando pago…';
  try {
    const { paymentMethod, error } = await stripe.createPaymentMethod({ type: 'card', card: tarjeta });
    if (error) { mostrarError(error.message); return; }

    let r;
    if (pedidoPendiente) {
      // Reintento: el pedido ya existe; solo se vuelve a cobrar con la nueva tarjeta
      r = await api('POST', '/api/pagos/stripe', { id_pedido: pedidoPendiente.id, payment_method_id: paymentMethod.id });
      r.datos = { pedido: pedidoPendiente, ...r.datos };
    } else r = await api('POST', '/api/pedidos', {
      items: leerCarrito().map(i => ({ id_producto: i.id, cantidad: i.cantidad })),
      metodo_pago: 'stripe',
      payment_method_id: paymentMethod.id,
      direccion: {
        direccion: document.getElementById('direccion').value,
        ciudad: document.getElementById('ciudad').value,
        telefono: document.getElementById('telefono').value,
      },
    });
    let pedido = r.datos?.pedido;
    if (pedido?.estado === 'pendiente') pedidoPendiente = pedido;

    if (r.codigo === 202 && r.datos.client_secret) { // 3D Secure
      const accion = await stripe.handleCardAction(r.datos.client_secret);
      if (accion.error) { mostrarError(accion.error.message + ' Tu pedido quedó pendiente de pago.'); return; }
      r = await api('POST', '/api/pagos/stripe', { id_pedido: pedido.id, payment_intent_id: accion.paymentIntent.id });
      if (r.codigo === 200) pedido = (await api('GET', '/api/pedidos/' + pedido.id)).datos.pedido;
    }

    if (r.codigo === 200 && pedido.estado === 'pendiente') { // reintento aprobado: se lee el pedido actualizado
      pedido = (await api('GET', '/api/pedidos/' + pedido.id)).datos.pedido;
    }
    if (r.codigo === 201 || r.codigo === 200) { mostrarConfirmacion(pedido, r.datos.correo_mensaje); return; }
    if (r.codigo === 401) { location.href = '/login.html?volver=/checkout.html'; return; }
    if (r.codigo === 409 && r.datos?.sin_stock) { // D3: vuelve al carrito con el aviso
      mostrarError('Stock insuficiente: ' + r.datos.sin_stock.map(s => `${s.nombre} (disponibles: ${s.disponible})`).join(', ') + '. Ajusta tu carrito.');
      bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('carrito')).show();
      return;
    }
    const extra = r.datos?.campos ? ' ' + Object.values(r.datos.campos).join('. ') : '';
    const pendiente = pedidoPendiente ? ` Tu pedido #${pedidoPendiente.id} quedó pendiente: puedes intentar con otra tarjeta.` : '';
    mostrarError((r.datos?.error || 'No se pudo completar la compra.') + extra + pendiente);
  } finally {
    boton.disabled = false;
    boton.textContent = 'Pagar';
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!leerSesion()) { location.href = '/login.html?volver=/checkout.html'; return; }
  if (leerCarrito().length === 0) { location.href = '/'; return; }
  pintarResumen();
  document.addEventListener('carrito', pintarResumen);

  const { codigo, datos } = await api('GET', '/api/pagos/config'); // clave PÚBLICA de Stripe
  if (codigo !== 200) { mostrarError('El servicio de pagos no está disponible. Intenta más tarde.'); return; }
  stripe = Stripe(datos.stripe_public_key);
  tarjeta = stripe.elements().create('card', { hidePostalCode: true });
  tarjeta.mount('#tarjeta');
  document.getElementById('formPago').addEventListener('submit', pagar);
});
