# API de pedidos y stock (C-032)

El stock se valida y se reserva al crear el pedido, dentro de una transacción
que bloquea cada producto (`lockForUpdate`). Los importes (precio, impuestos,
envío y descuento) los calcula siempre el backend: lo que envíe el cliente se ignora.

## Crear pedido

`POST /api/orders` (requiere `Authorization: Bearer <token>`)

Cuerpo:

| Campo | Tipo | Regla |
|---|---|---|
| `items` | array | obligatorio, mínimo 1 |
| `items[].product_id` | entero | obligatorio |
| `items[].quantity` | entero | obligatorio, de 1 a 999 |
| `shipping_address` | objeto | obligatorio |
| `billing_address` | objeto | obligatorio |
| `coupon_code` | texto | opcional, máximo 255 |

Si un mismo `product_id` aparece varias veces, las cantidades se suman en una sola
línea. Los productos se procesan ordenados por `product_id` para evitar bloqueos
cruzados entre pedidos simultáneos.

### Respuesta 201

```json
{
  "success": true,
  "message": "Pedido creado correctamente.",
  "order": {
    "order_id": "ORD-XXXXXXXX-...",
    "status": "checkout",
    "total_amount": 51,
    "items": [
      { "product_id": 1, "name": "Producto", "price": 25.5, "quantity": 2, "subtotal": 51 }
    ]
  }
}
```

El stock de cada producto se descuenta al crear el pedido.

### Respuesta 422: stock insuficiente

```json
{
  "success": false,
  "code": "insufficient_stock",
  "message": "Stock insuficiente para el producto Producto QA.",
  "product_id": 1,
  "requested": 11,
  "available": 10
}
```

No se descuenta stock de ningún producto: la transacción se deshace completa.

### Respuesta 422: producto no disponible

```json
{
  "success": false,
  "code": "product_unavailable",
  "message": "Uno de los productos no existe o no está disponible.",
  "product_id": 999999
}
```

### Otros errores

- `401`: sin token, token inválido o vencido.
- `422` de validación (campos faltantes o inválidos): lo devuelve Laravel con el
  detalle en `errors`.

## Cancelar pedido

`PATCH /api/orders/{orderId}/cancel`

Solo se puede cancelar un pedido en estado `cart`, `checkout` o `payment_pending`
(en otro estado responde `422`). Al cancelarlo se devuelve el stock reservado.
Cancelar dos veces no devuelve el stock dos veces: la segunda llamada responde `422`.

## Limitaciones conocidas

- Si un pago falla o vence, o el cliente abandona el checkout, el stock sigue
  reservado hasta que el pedido se cancele. Se resolverá junto con C-035 y C-036.

## Stock en el catálogo (C-031 + C-032)

`GET /api/products` y `GET /api/products/{id}` son públicos (no piden token) y
devuelven el campo `stock` real del producto. Ese valor sigue a los pedidos:

- baja al crear un pedido (`POST /api/orders`),
- se restaura al cancelarlo (`PATCH /api/orders/{orderId}/cancel`).

Esto está cubierto por `CatalogStockTest`.