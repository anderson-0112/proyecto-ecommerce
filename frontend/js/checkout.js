/**
 * NodoTec — checkout (D-PAGO opción B):
 * 1) Stripe.js convierte la tarjeta en un PaymentMethod "pm_..." (la tarjeta no llega a NodoTec).
 * 2) POST /api/pedidos con productos + dirección + pm_...: pedidos-service verifica stock,
 *    crea el pedido y llama a pagos-service, que cobra con Stripe.
 * 3) Si el banco pide 3D Secure (202), se resuelve aquí y se confirma con POST /api/pagos/stripe.
 * PayPal: POST /api/pedidos con metodo_pago "paypal" crea el pedido y la orden (202);
 * el comprador la aprueba en la ventana de PayPal y luego POST /api/pagos/paypal la captura.
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

/** Cuerpo de POST /api/pedidos: productos del carrito + dirección + datos del método de pago. */
function datosPedido(metodo, extra = {}) {
  return {
    items: leerCarrito().map(i => ({ id_producto: i.id, cantidad: i.cantidad })),
    metodo_pago: metodo,
    ...extra,
    direccion: {
      direccion: document.getElementById('direccion').value,
      ciudad: document.getElementById('ciudad').value,
      telefono: document.getElementById('telefono').value,
    },
  };
}

/** Muestra el motivo de una compra que no terminó (stock, rechazo, servicio caído…). */
function mostrarFallo(r) {
  if (r.codigo === 401) { location.href = '/login.html?volver=/checkout.html'; return; }
  if (r.codigo === 409 && r.datos?.sin_stock) { // D3: vuelve al carrito con el aviso
    mostrarError('Stock insuficiente: ' + r.datos.sin_stock.map(s => `${s.nombre} (disponibles: ${s.disponible})`).join(', ') + '. Ajusta tu carrito.');
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('carrito')).show();
    return;
  }
  const extra = r.datos?.campos ? ' ' + Object.values(r.datos.campos).join('. ') : '';
  const pendiente = pedidoPendiente ? ` Tu pedido #${pedidoPendiente.id} quedó pendiente: puedes intentar de nuevo.` : '';
  mostrarError((r.datos?.error || 'No se pudo completar la compra.') + extra + pendiente);
}

/* ---------------- PayPal (SDK de JavaScript, botones) ---------------- */
function cargarPaypal(clientId) {
  const script = document.createElement('script');
  // disable-funding: las tarjetas se cobran con Stripe; aquí solo el botón de PayPal
  script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=USD&disable-funding=card,credit,paylater`;
  script.onload = () => paypal.Buttons({
    style: { layout: 'horizontal', label: 'pay', tagline: false },
    // Paso 1: NodoTec crea el pedido y la orden de PayPal; el SDK abre la ventana de aprobación
    createOrder: async () => {
      document.getElementById('error').classList.add('d-none');
      if (!document.getElementById('formPago').reportValidity()) throw new Error('Faltan datos de entrega');
      let r;
      if (pedidoPendiente) {
        r = await api('POST', '/api/pagos/paypal', { id_pedido: pedidoPendiente.id });
      } else {
        r = await api('POST', '/api/pedidos', datosPedido('paypal'));
        if (r.datos?.pedido) pedidoPendiente = r.datos.pedido;
      }
      if (r.codigo !== 202) { mostrarFallo(r); throw new Error('No se creó la orden'); }
      return r.datos.paypal_orden_id;
    },
    // Paso 2: el comprador aprobó; NodoTec captura el pago
    onApprove: async (data) => {
      const r = await api('POST', '/api/pagos/paypal', { id_pedido: pedidoPendiente.id, orden_id: data.orderID });
      if (r.codigo !== 200) { mostrarFallo(r); return; }
      const pedido = (await api('GET', '/api/pedidos/' + pedidoPendiente.id)).datos.pedido;
      mostrarConfirmacion(pedido, r.datos.correo_mensaje);
    },
    onCancel: () => mostrarError(`Pago con PayPal cancelado. Tu pedido #${pedidoPendiente?.id} quedó pendiente.`),
    onError: (err) => {
      console.error(err);
      if (document.getElementById('error').classList.contains('d-none')) mostrarError('PayPal no pudo procesar el pago.');
    },
  }).render('#botonesPaypal');
  document.head.appendChild(script);
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
    } else r = await api('POST', '/api/pedidos', datosPedido('stripe', { payment_method_id: paymentMethod.id }));
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
    mostrarFallo(r);
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

  const { codigo, datos } = await api('GET', '/api/pagos/config'); // datos PÚBLICOS de Stripe y PayPal
  if (codigo !== 200) { mostrarError('El servicio de pagos no está disponible. Intenta más tarde.'); return; }
  stripe = Stripe(datos.stripe_public_key);
  tarjeta = stripe.elements().create('card', { hidePostalCode: true });
  tarjeta.mount('#tarjeta');
  document.getElementById('formPago').addEventListener('submit', pagar);
  if (datos.paypal_client_id) cargarPaypal(datos.paypal_client_id);

  // Cambiar entre tarjeta (Stripe) y PayPal
  document.querySelectorAll('input[name="metodo"]').forEach(radio => radio.addEventListener('change', () => {
    const esPaypal = document.getElementById('metodoPaypal').checked;
    document.getElementById('pagoTarjeta').classList.toggle('d-none', esPaypal);
    document.getElementById('pagoPaypal').classList.toggle('d-none', !esPaypal);
    document.getElementById('error').classList.add('d-none');
  }));
});
