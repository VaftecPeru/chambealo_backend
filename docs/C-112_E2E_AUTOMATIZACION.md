# C-112 · Automatización de Pruebas E2E (Acceso, Catálogo, Carrito, Pedidos y Checkout)

- **Proyecto:** CHAMBEALO (`VaftecPeru/chambealo_backend`)
- **Responsable QA / CI:** Julio
- **Fecha de ejecución:** 2026-11-27
- **Rama / SHA evaluado:** `feat/backend-julio` (`952fdc7`, basado en `origin/develop` `471e65c`)

---

## 1. Artefactos implementados

1. **Suite E2E PHPUnit (CI automático de Laravel):**
   `tests/Feature/E2EStorefrontJourneyC112Test.php`
   - Ejecución: `php artisan test --filter=E2EStorefrontJourneyC112Test`
   - Resultado verificado: **4/4 flujos completos aprobados (51 assertions, `exit code 0`)**.

2. **Runner E2E reproducible para local o staging (cero dependencias externas):**
   `scripts/e2e/run_c112_e2e.mjs`
   - Ejecución local:
     ```bash
     API_BASE_URL=http://127.0.0.1:8000/api PR_REF=feat/backend-julio node scripts/e2e/run_c112_e2e.mjs
     ```
   - Ejecución contra staging:
     ```bash
     API_BASE_URL=https://staging-api.chambealo.pe/api \
     FRONTEND_BASE_URL=https://staging.chambealo.pe \
     PR_REF=PR#12 \
     node scripts/e2e/run_c112_e2e.mjs
     ```
   - Genera reporte automático en JSON y Markdown vinculado al `GIT_SHA` y `PR_REF` en `storage/logs/e2e/`.
   - Resultado verificado en entorno local (`SHA=952fdc7`): **15/15 escenarios APROBADOS**.

3. **Workflow de GitHub Actions para staging:**
   `.github/workflows/e2e-c112.yml`
   - Disparable manualmente (`workflow_dispatch`) indicando `api_base_url`, `frontend_base_url` (opcional) y `pr_ref`.
   - Sube automáticamente los reportes JSON/MD como artifact `c112-e2e-report-<sha>`.

---

## 2. Matriz de escenarios ejecutados (`SHA=952fdc7`)

### 2.1 Suite E2E PHPUnit (`E2EStorefrontJourneyC112Test`)
| Escenario | Flujo cubierto | Resultado |
|---|---|---|
| `test_e2e_access_lifecycle_register_login_profile_and_logout_revocation` | Registro `201` → duplicado `422` → clave mala `401` → login `200` → perfil `200` → logout `200` → token revocado `401` | APROBADO |
| `test_e2e_catalog_search_filters_and_product_detail` | `/api/categories` `200`, `/api/products` `200` con `brand.name`, búsqueda `?q=Taladro`, detalle `200`, inexistente `404` | APROBADO |
| `test_e2e_cart_to_order_stock_reservation_validation_and_cancellation` | Stock 0 rechazado `422` → consolidación de líneas repetidas → total backend `500` → descuento atómico de stock + `inventory_movements` → cancelación `200` + restitución exacta → doble cancelación `422` | APROBADO |
| `test_e2e_checkout_paypal_session_idempotency_and_cancellation_lock` | Usuario ajeno `404` → sesión PayPal USD `200` → sesión duplicada `409` → cancelación bloqueada con pago pendiente `409` sin liberar stock | APROBADO |

### 2.2 Runner E2E en vivo (`scripts/e2e/run_c112_e2e.mjs` — `SHA=952fdc7`)
| ID | Área | Escenario | Resultado | Evidencia real |
|---|---|---|---|---|
| `E2E-AUTH-01` | Acceso | Registro de usuario nuevo | APROBADO | `HTTP 201` |
| `E2E-AUTH-02` | Acceso | Rechazo de correo duplicado | APROBADO | `HTTP 422` |
| `E2E-AUTH-03` | Acceso | Rechazo de credenciales incorrectas | APROBADO | `HTTP 401` |
| `E2E-AUTH-04` | Acceso | Inicio de sesión y emisión JWT | APROBADO | `HTTP 200`, `token=true` |
| `E2E-AUTH-05` | Acceso | Consulta de perfil autenticado | APROBADO | `HTTP 200` |
| `E2E-CAT-01` | Catálogo | Listado de categorías | APROBADO | `HTTP 200`, 7 categorías |
| `E2E-CAT-02` | Catálogo | Listado de productos con datos reales | APROBADO | `HTTP 200`, 6 productos |
| `E2E-DET-01` | Detalle | Detalle de producto existente | APROBADO | `HTTP 200`, `stock=60` |
| `E2E-DET-02` | Detalle | Producto inexistente devuelve 404 | APROBADO | `HTTP 404` |
| `E2E-ORD-01` | Pedidos | Protección de `/orders` sin Bearer | APROBADO | `HTTP 401` |
| `E2E-ORD-02` | Pedidos | Creación de pedido y reserva de stock | APROBADO | `HTTP 201` |
| `E2E-ORD-03` | Pedidos | Descuento exacto de stock tras pedido | APROBADO | `stock 60 -> 59` |
| `E2E-ORD-04` | Pedidos | Cancelación y restitución exacta de stock | APROBADO | `HTTP 200`, `stock=60` |
| `E2E-CHK-01` | Checkout | Protección de `/payment/session` sin Bearer | APROBADO | `HTTP 401` |
| `E2E-AUTH-06` | Acceso | Logout y revocación real del token | APROBADO | `logout 200 -> profile 401` |

---

## 3. Matriz de errores observados
- **Errores en esta ejecución:** `0` (`0` P0, `0` P1).
- **Pendiente de entorno externo:** repetir el workflow `.github/workflows/e2e-c112.yml` apuntando a la URL real de **staging** cuando esté provisionado (`C-114`).
