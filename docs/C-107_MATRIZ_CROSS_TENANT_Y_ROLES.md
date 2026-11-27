# C-107 · Matriz de Pruebas Cross-Tenant y Permisos por Rol

- **Proyecto:** CHAMBEALO Backend (`VaftecPeru/chambealo_backend`)
- **Responsable QA / Seguridad:** Julio
- **Fecha de ejecución:** 2026-11-27
- **Rama evaluada:** `feat/backend-julio` (SHA base `803fedc`, incluye `origin/develop` `471e65c`)
- **Suite automatizada:** `tests/Feature/CrossTenantAndRoleMatrixC107Test.php`
- **Comando de verificación:** `php artisan test --filter=CrossTenantAndRoleMatrixC107Test`
- **Resultado de ejecución:** **6 escenarios aprobados (59 assertions)** + **1 gate `INCOMPLETE` dependiente de C-106 (PR #12)** (`exit code 0`).

---

## 1. Matriz de acceso sin sesión (`401 Unauthorized`)

| Endpoint | Método | Sin sesión (Esperado) | Resultado real | Estado |
|---|---|---|---|---|
| `/api/profile` | `GET` | `401` | `401` | APROBADO |
| `/api/profile` | `PUT` | `401` | `401` | APROBADO |
| `/api/orders` | `GET` | `401` | `401` | APROBADO |
| `/api/orders` | `POST` | `401` | `401` | APROBADO |
| `/api/products` | `POST` | `401` | `401` | APROBADO |
| `/api/categories` | `POST` | `401` | `401` | APROBADO |
| `/api/inventory/movements` | `GET` | `401` | `401` | APROBADO |
| `/api/inventory/movements` | `POST` | `401` | `401` | APROBADO |
| `/api/admin/audits` | `GET` | `401` | `401` | APROBADO |
| `/api/payment/session` | `POST` | `401` | `401` | APROBADO |
| `/api/process-payment` | `POST` | `401` | `401` | APROBADO |

---

## 2. Matriz de permisos por rol (`customer` / `vendor` / `admin`)

| Recurso / Operación | `user` (customer) | `vendor` | `admin` | Estado |
|---|---|---|---|---|
| `GET /api/profile` | `200` | `200` | `200` | APROBADO |
| `GET /api/orders` (propios) | `200` | `200` | `200` | APROBADO |
| `POST /api/products` (marca propia) | `403` | `201` | `201` | APROBADO |
| `PUT /api/products/{id}` (marca propia) | `403` | `200` | `200` | APROBADO |
| `DELETE /api/products/{id}` (marca propia) | `403` | `200` | `200` | APROBADO |
| `GET /api/inventory/movements` | `403` | `200` | `200` | APROBADO |
| `POST /api/inventory/movements` | `403` | `201` | `201` | APROBADO |
| `PATCH /api/orders/{orderId}/status` | `403` | `200` | `200` | APROBADO |
| `POST /api/categories` | `403` | `403` | `201` | APROBADO |
| `PUT /api/categories/{id}` | `403` | `403` | `200` | APROBADO |
| `DELETE /api/categories/{id}` | `403` | `403` | `200` | APROBADO |
| `POST /api/products/{id}/image` | `403` | `403` | `201` | APROBADO |
| `GET /api/admin/audits` | `403` | `403` | `200` | APROBADO |

---

## 3. Matriz Cross-Tenant y Tenant Falsificado

| Escenario | Actor | Vector probado | Esperado | Real | Estado |
|---|---|---|---|---|---|
| Ver pedido de Tienda 2 falsificando tenant | Customer T1 | `X-Tenant-Id: 2` + `?tenant_id=2` | `404` | `404` | APROBADO |
| Cancelar pedido de Tienda 2 falsificando tenant | Customer T1 | `X-Tenant-Id: 2` + body `tenant_id=2` | `404` | `404` | APROBADO |
| Crear pedido con `tenant_id` falsificado | Customer T1 | Header/query/body `tenant_id=2` | Persiste `tenant_id=1` | `tenant_id=1` | APROBADO |
| Cambiar estado de pedido de Tienda 2 | Admin T1 | `X-Tenant-Id: 2` + `?tenant_id=2` | `404` | `404` | APROBADO |
| Ajustar stock de producto de Tienda 2 | Admin T1 | `X-Tenant-Id: 2` + body `tenant_id=2` | `404` | `404` | APROBADO |
| Leer auditoría individual de Tienda 2 | Admin T1 | `GET /api/admin/audits/{id}?tenant_id=2` | `404` | `404` | APROBADO |
| Crear producto bajo marca de Vendedor B | Vendor T10 | `POST /api/products` (`brand_id` T20) | `403` | `403` | APROBADO |
| Editar/eliminar producto de Vendedor B | Vendor T10 | `PUT`/`DELETE /api/products/{id}` | `403` | `403` | APROBADO |
| Mover producto propio a marca de Vendedor B | Vendor T10 | `PUT /api/products/{id}` | `403` | `403` | APROBADO |
| Consultar/ajustar inventario de Vendedor B | Vendor T10 | `GET`/`POST /api/inventory/movements` | `404` | `404` | APROBADO |
| Listar `/api/my-products` | Vendor T10 | Solo productos de su tienda | Excluye T20 | Excluye T20 | APROBADO |
| Cambio secuencial T101 → T202 en mismo proceso | Admin T101 → Admin T202 | `GET /api/admin/audits` consecutivo | Sin fuga de contexto | Sin fuga | APROBADO |

---

## 4. CRUD Administrativo Legítimo (No Regresión)

Verificado con `Admin` de `tenant_id = 30`:
- Categoría: `POST` (`201`) → `PUT` (`200`) → `DELETE` (`200`)
- Producto: `POST` (`201`) → `PUT` (`200`, stock `10 → 15`) → `DELETE` (`200`)
- Inventario: `POST /api/inventory/movements` (`201`, stock `15 → 20`)
- Auditoría: `GET /api/admin/audits?entity_type=Product&entity_id={id}` (`200`, `meta.total = 3`)

---

## 5. Gate Pendiente de C-106 (PR #12 — Rodolfo)

| Escenario | Esperado tras C-106 | Resultado actual en `803fedc` | Estado |
|---|---|---|---|
| Usuario autenticado con `tenant_id = null` en `GET /api/orders` | `403 Forbidden` (fail-closed) | `200 OK` (middleware actual permite `tenant_id = null`) | **INCOMPLETE (Pendiente C-106)** |

> **Criterio de cierre final de C-107:** cuando Rodolfo integre **C-106 (PR #12)** en `develop`, re-ejecutar `php artisan test --filter=CrossTenantAndRoleMatrixC107Test`. El test `test_authenticated_user_without_tenant_must_return_403_once_c106_is_merged` pasará automáticamente de `INCOMPLETE` a `PASS` confirmando `403 Forbidden` fail-closed.
