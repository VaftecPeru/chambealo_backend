# Productos y categorías

Todas las rutas son de `/api`. Las respuestas exitosas usan `{ "success": true, "data": ... }`. Los errores de validación devuelven `422` con `{ "message": "...", "errors": { "campo": ["..."] } }`.

## Categorías

| Método | Ruta | Acceso | Respuestas verificadas |
|---|---|---|---|
| GET | `/api/categories` | Público (60 por minuto) | `200` |
| GET | `/api/categories/{id}` | Público | `200` |
| POST | `/api/categories` | Rol `admin` | `201`, `401` sin token, `422` |
| PUT | `/api/categories/{id}` | Rol `admin` | `422` si el padre es la misma categoría |
| DELETE | `/api/categories/{id}` | Rol `admin` | `200`; `409` si tiene productos o subcategorías |

Validación: `name` obligatorio; `slug` único (se genera desde el nombre si no se envía); `parent_id` debe existir y no puede ser la propia categoría.

## Productos

| Método | Ruta | Acceso | Respuestas verificadas |
|---|---|---|---|
| GET | `/api/products` | Público (60 por minuto) | `200`, paginado |
| GET | `/api/products/{id}` | Público | `200`; `404` si fue borrado o está inactivo |
| POST | `/api/products` | Rol `vendor` o `admin` | `201`, `401` sin token, `403` con usuario normal, `422` |
| PUT | `/api/products/{id}` | Rol `vendor` o `admin` | `200` |
| DELETE | `/api/products/{id}` | Rol `vendor` o `admin` | `200` (borrado suave) |
| GET | `/api/my-products` | Con sesión | `501`: los productos aún no tienen vendedor asignado |

Listado: filtros `category_id`, `brand_id` y `q` (búsqueda por nombre); `per_page` (por defecto 12, máximo 100). Los productos `inactive` no aparecen. La respuesta incluye `meta` con `current_page`, `last_page`, `per_page` y `total`.

Validación: `brand_id` y `category_id` obligatorios y existentes; `name` obligatorio; `slug` único; `price` obligatorio, numérico y mayor o igual a 0; `stock` entero mayor o igual a 0; `status` solo `active`, `inactive` o `out_of_stock`; `specifications` arreglo.

## Forma de un producto

```json
{
  "id": 5,
  "product_id": 5,
  "name": "Producto de prueba 5",
  "slug": "producto-de-prueba-5",
  "description": null,
  "price": 10.0,
  "stock": 90,
  "status": "active",
  "specifications": null,
  "brand": { "id": 1, "name": "..." },
  "category": { "id": 1, "name": "..." }
}
```

`id` y `product_id` valen lo mismo, y `price` es numérico. El carrito del frontend envía `id` como `product_id` al crear una orden.

## Estado del frontend

- El detalle (`/producto/:id`) consume esta API a través de `productService.js` y es compatible con esta forma.
- El listado (`Products.jsx`) y el buscador de la barra usan datos fijos de `products.js` (500 productos con ids del 1 al 500, que coinciden con los de la base).
- Los precios fijos del front no coinciden con los de la base (por ejemplo 15.00 frente a 10.00). El backend recalcula el total, así que el cliente puede ver un importe distinto del cobrado. Se resuelve en C-033.
- La tabla no tiene imágenes, descuentos ni rating: el frontend muestra valores de relleno.

## Pruebas

- Categorías: 8 chequeos `OK` con un script de pruebas.
- Productos: 15 chequeos `OK` con un script de pruebas.
- `php artisan test`: 10 pasados.