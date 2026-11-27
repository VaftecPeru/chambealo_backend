#!/usr/bin/env node
/**
 * C-112: Runner E2E reproducible (local o staging) — cero dependencias externas.
 *
 * Uso:
 *   API_BASE_URL=http://127.0.0.1:8000/api node scripts/e2e/run_c112_e2e.mjs
 *   API_BASE_URL=https://staging-api.chambealo.pe/api FRONTEND_BASE_URL=https://staging.chambealo.pe PR_REF=PR#12 node scripts/e2e/run_c112_e2e.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

const API_BASE = (process.env.API_BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/+$/, '');
const FRONTEND_BASE = (process.env.FRONTEND_BASE_URL || '').replace(/\/+$/, '');
const PR_REF = process.env.PR_REF || 'local';
const REPORT_DIR = process.env.E2E_REPORT_DIR || path.resolve(process.cwd(), 'storage/logs/e2e');

let GIT_SHA = process.env.GIT_SHA || 'unknown';
if (GIT_SHA === 'unknown') {
  try {
    GIT_SHA = execSync('git rev-parse --short HEAD', { encoding: 'utf8' }).trim();
  } catch {}
}

fs.mkdirSync(REPORT_DIR, { recursive: true });

const results = [];
const errors = [];

function record(id, area, description, passed, detail) {
  const status = passed ? 'APROBADO' : 'FALLIDO';
  results.push({ id, area, description, status, detail });
  if (!passed) {
    errors.push({ id, area, description, detail });
  }
  console.log(`[${status}] ${id} (${area}) - ${description}: ${detail}`);
}

async function api(method, endpoint, body = null, token = null) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${endpoint}`, {
    method,
    headers,
    body: body !== null ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}
  return { status: res.status, json, text };
}

async function main() {
  const startedAt = new Date().toISOString();
  console.log(`=== C-112 E2E Runner | SHA=${GIT_SHA} | PR=${PR_REF} | API=${API_BASE} ===`);

  // 0. Optional Frontend availability check
  if (FRONTEND_BASE) {
    try {
      const feRes = await fetch(FRONTEND_BASE);
      record('E2E-FE-01', 'Frontend', 'Disponibilidad de Home', feRes.status === 200, `HTTP ${feRes.status}`);
    } catch (err) {
      record('E2E-FE-01', 'Frontend', 'Disponibilidad de Home', false, String(err.message || err));
    }
  }

  // 1. Acceso: registro, login, perfil y logout
  const uniqEmail = `e2e_c112_${Date.now()}@chambealo.pe`;
  const password = 'E2eUser12345!';

  const reg = await api('POST', '/register', {
    name: 'E2E Runner C112',
    email: uniqEmail,
    password,
    password_confirmation: password,
  });
  record('E2E-AUTH-01', 'Acceso', 'Registro de usuario nuevo', reg.status === 201, `HTTP ${reg.status} (${uniqEmail})`);

  const dup = await api('POST', '/register', {
    name: 'Duplicado',
    email: uniqEmail,
    password,
    password_confirmation: password,
  });
  record('E2E-AUTH-02', 'Acceso', 'Rechazo de correo duplicado (422)', dup.status === 422, `HTTP ${dup.status}`);

  const badLogin = await api('POST', '/login', { email: uniqEmail, password: 'WrongPassword!' });
  record('E2E-AUTH-03', 'Acceso', 'Rechazo de credenciales incorrectas (401)', badLogin.status === 401, `HTTP ${badLogin.status}`);

  const login = await api('POST', '/login', { email: uniqEmail, password });
  const token = login.json?.access_token || null;
  record('E2E-AUTH-04', 'Acceso', 'Inicio de sesión y emisión JWT', login.status === 200 && Boolean(token), `HTTP ${login.status}, token=${Boolean(token)}`);

  const profile = await api('GET', '/profile', null, token);
  record('E2E-AUTH-05', 'Acceso', 'Consulta de perfil autenticado', profile.status === 200, `HTTP ${profile.status}`);

  // 2. Catálogo y Detalle
  const cats = await api('GET', '/categories');
  record('E2E-CAT-01', 'Catálogo', 'Listado de categorías', cats.status === 200 && cats.json?.success === true, `HTTP ${cats.status}, count=${cats.json?.data?.length ?? 0}`);

  const prods = await api('GET', '/products');
  const items = Array.isArray(prods.json?.data) ? prods.json.data : [];
  record('E2E-CAT-02', 'Catálogo', 'Listado de productos con datos reales', prods.status === 200 && items.length > 0, `HTTP ${prods.status}, count=${items.length}`);

  const inStockProduct = items.find((p) => Number(p.stock) > 0);
  if (inStockProduct) {
    const det = await api('GET', `/products/${inStockProduct.product_id}`);
    record('E2E-DET-01', 'Detalle', 'Detalle de producto existente', det.status === 200 && det.json?.data?.product_id === inStockProduct.product_id, `HTTP ${det.status}, id=${inStockProduct.product_id}, stock=${det.json?.data?.stock}`);
  } else {
    record('E2E-DET-01', 'Detalle', 'Detalle de producto existente', false, 'No se encontró producto con stock > 0 en catálogo');
  }

  const det404 = await api('GET', '/products/999999');
  record('E2E-DET-02', 'Detalle', 'Producto inexistente devuelve 404', det404.status === 404, `HTTP ${det404.status}`);

  // 3. Carrito -> Pedido, Stock y Cancelación
  const unauthOrders = await api('GET', '/orders');
  record('E2E-ORD-01', 'Pedidos', 'Protección de /orders sin Bearer (401)', unauthOrders.status === 401, `HTTP ${unauthOrders.status}`);

  let createdOrderId = null;
  if (token && inStockProduct) {
    const stockBefore = Number(inStockProduct.stock);
    const ord = await api('POST', '/orders', {
      items: [{ product_id: inStockProduct.product_id, quantity: 1 }],
      shipping_address: { city: 'Lima', street: 'Av. QA 123' },
      billing_address: { city: 'Lima', street: 'Av. QA 123' },
    }, token);
    createdOrderId = ord.json?.order?.order_id || null;
    record('E2E-ORD-02', 'Pedidos', 'Creación de pedido y reserva de stock', ord.status === 201 && Boolean(createdOrderId), `HTTP ${ord.status}, order_id=${createdOrderId}`);

    if (createdOrderId) {
      const afterOrder = await api('GET', `/products/${inStockProduct.product_id}`);
      const stockAfterOrder = Number(afterOrder.json?.data?.stock);
      record('E2E-ORD-03', 'Pedidos', 'Descuento exacto de stock tras pedido', stockAfterOrder === stockBefore - 1, `stock ${stockBefore} -> ${stockAfterOrder}`);

      const cancel = await api('PATCH', `/orders/${createdOrderId}/cancel`, {}, token);
      const afterCancel = await api('GET', `/products/${inStockProduct.product_id}`);
      const stockAfterCancel = Number(afterCancel.json?.data?.stock);
      record('E2E-ORD-04', 'Pedidos', 'Cancelación de pedido y restitución de stock', cancel.status === 200 && stockAfterCancel === stockBefore, `HTTP ${cancel.status}, stock restaurado=${stockAfterCancel}`);
    }
  }

  // 4. Checkout: protección sin sesión
  const unauthCheckout = await api('POST', '/payment/session', { gateway: 'paypal', order_id: 1 });
  record('E2E-CHK-01', 'Checkout', 'Protección de /payment/session sin Bearer (401)', unauthCheckout.status === 401, `HTTP ${unauthCheckout.status}`);

  // 5. Logout y revocación
  if (token) {
    const out = await api('POST', '/logout', {}, token);
    const afterOut = await api('GET', '/profile', null, token);
    record('E2E-AUTH-06', 'Acceso', 'Logout y revocación real del token (401)', out.status === 200 && afterOut.status === 401, `logout HTTP ${out.status} -> profile HTTP ${afterOut.status}`);
  }

  const passedCount = results.filter((r) => r.status === 'APROBADO').length;
  const summary = {
    suite: 'C-112 E2E Storefront Runner',
    started_at: startedAt,
    finished_at: new Date().toISOString(),
    git_sha: GIT_SHA,
    pr_ref: PR_REF,
    api_base_url: API_BASE,
    frontend_base_url: FRONTEND_BASE || null,
    total: results.length,
    passed: passedCount,
    failed: errors.length,
    results,
    errors,
  };

  const jsonPath = path.join(REPORT_DIR, `c112_e2e_${GIT_SHA}.json`);
  const mdPath = path.join(REPORT_DIR, `c112_e2e_${GIT_SHA}.md`);
  fs.writeFileSync(jsonPath, JSON.stringify(summary, null, 2), 'utf8');

  const mdLines = [
    `# Reporte E2E C-112`,
    `- **SHA:** \`${GIT_SHA}\``,
    `- **PR / Referencia:** \`${PR_REF}\``,
    `- **API:** \`${API_BASE}\``,
    `- **Fecha:** ${summary.finished_at}`,
    `- **Resultado:** **${passedCount}/${results.length} APROBADOS** (${errors.length} fallidos)`,
    '',
    '| ID | Área | Escenario | Resultado | Detalle |',
    '|---|---|---|---|---|',
    ...results.map((r) => `| ${r.id} | ${r.area} | ${r.description} | ${r.status} | ${r.detail} |`),
  ];
  fs.writeFileSync(mdPath, mdLines.join('\n') + '\n', 'utf8');

  console.log(`\nSUMMARY: ${passedCount}/${results.length} APROBADO`);
  console.log(`JSON Report: ${jsonPath}`);
  console.log(`MD Report:   ${mdPath}`);

  if (errors.length > 0) {
    process.exit(1);
  }
}

main().catch((err) => {
  console.error('Fatal E2E runner error:', err);
  process.exit(1);
});
