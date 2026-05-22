# 🚀 QUICK START - Panel Admin de Logs de Pagos

## Acceso Rápido

### URL
```
http://localhost:8000/admin/payment-logs
```

### Credenciales Requeridas
- Email de usuario autenticado
- Rol: ADMIN

---

## 📊 Vista Principal (Index)

### Elementos
1. **Barra Superior** - Título + Botón Actualizar
2. **4 Tarjetas de Estadísticas** (Total hoy, Fallidos, Seguridad, Por Gateway)
3. **Panel de Filtros** - 6 campos de búsqueda
4. **Tabla Paginada** - 8 columnas de información
5. **Links de Navegación** - Ir a detalle

### Columnas de la Tabla
| Columna | Contenido |
|---------|-----------|
| ID | Número único del log |
| Transacción | ID de la transacción vinculada |
| Fecha | Fecha y hora del evento |
| Gateway | PayPal / Izipay / Mercado Pago |
| Evento | Tipo de evento (webhook, payment, security) |
| Estado | Success / Failed / Pending / Processing |
| HTTPS | ✅ / ❌ verificado |
| Acciones | Botón "Ver Detalle" |

---

## 🔍 Filtros Disponibles

### 1. Gateway
```
Opciones:
- Todos
- PayPal
- Izipay
- Mercado Pago
```

### 2. Evento
```
Opciones:
- Todos
- webhook.received
- webhook.verification
- webhook.processed
- payment.completed
- payment.failed
```

### 3. Estado
```
Opciones:
- Todos
- success
- failed
- pending
- processing
- retry
```

### 4. Rango de Fechas
```
De: YYYY-MM-DD
Hasta: YYYY-MM-DD
```

### 5. Búsqueda
```
Busca en:
- ID del log
- Transaction ID
- Webhook ID
```

---

## 📝 Vista Detalle (Show)

Acceso: Clic en "Ver Detalle" en la tabla

### Secciones

#### 1. Información General
```
ID del log
Transaction ID (con link)
Gateway
Tipo de Evento
Estado
Fecha/Hora
Webhook ID
```

#### 2. Información de Seguridad
```
HTTPS Verificado: ✅/❌
Versión TLS
Firma Verificada: ✅/❌
Método de Firma
Timestamp Validado: ✅/❌
IP Address
User Agent
```

#### 3. Payloads (JSON)
```
Request Payload - Datos enviados
Response Payload - Respuesta recibida
Headers - Encabezados HTTP
```

#### 4. Errores
```
Mensaje de error (si existe)
```

---

## 🛠️ Funcionalidades

### Filtrar Logs
1. Seleccionar criterios en panel de filtros
2. Hacer clic en "Filtrar"
3. Tabla se actualiza automáticamente

### Ver Detalle
1. Hacer clic en "Ver Detalle"
2. Se abre nueva página con información completa
3. Hacer clic en "← Volver" para regresar

### Ir a Página
```
Links de paginación en la parte inferior
- Anterior
- Números de página
- Siguiente
```

### Buscar Rápido
```
Usar campo "Búsqueda" si no necesitas filtros avanzados
```

---

## 📱 Responsive Design

### Desktop
- Todos los elementos visibles
- Diseño de 8 columnas en tabla
- Panel de filtros horizontal

### Tablet
- Tabla se adapta
- Panel de filtros se reorganiza

### Mobile
- Tabla scrollable
- Filtros en forma vertical
- Botones adaptados

---

## ⚙️ Configuración

### Por Página
Default: 50 items
Editable en URL: `?per_page=100`

### Orden
Default: Más recientes primero
Fixed en: `ORDER BY created_at DESC`

---

## 🔒 Seguridad

### Acceso
- ✅ Autenticación requerida
- ✅ Solo usuarios admin
- ✅ HTTPS en producción

### Datos Visibles
- ✅ Payloads completos
- ✅ IPs
- ✅ Headers
- ✅ Información de firma

---

## 📞 Troubleshooting

### "No tienes acceso"
→ Verifica que eres admin

### "Tabla vacía"
→ Primero ejecuta algún pago para generar logs

### "Página cargando lentamente"
→ Ajusta filtros para limitar resultados

### "Los filtros no funcionan"
→ Verifica sintaxis de fechas (YYYY-MM-DD)

---

## 🎯 Casos de Uso Comunes

### Auditar transacciones del día
1. Gateway: [Seleccionar]
2. Fecha desde: [Hoy]
3. Fecha hasta: [Hoy]
4. Filtrar

### Revisar errores
1. Estado: failed
2. Hacer clic en "Ver Detalle"
3. Revisar "Error Message"

### Verificar seguridad
1. Evento: security.*
2. Revisar columna "HTTPS"
3. Hacer clic para ver "Firma Verificada"

### Investigar webhook específico
1. Búsqueda: [webhook_id]
2. Clic en resultado
3. Ver JSON completo

---

## 📊 Estadísticas

### Total Hoy
Suma de todos los eventos registrados en 24 horas

### Fallidos Hoy
Eventos con `status = 'failed'` en 24 horas

### Eventos Seguridad
Eventos con `event_type LIKE 'security.%'` en 24 horas

### Por Gateway
Desglose de eventos por cada gateway activo

---

**Versión**: 1.0
**Última actualización**: 2024
**Estado**: ✅ Listo para usar
