# Autenticación de usuarios

Todas las rutas son de `/api`. Los errores de validación devuelven `422` con la forma `{ "message": "...", "errors": { "campo": ["..."] } }`. Enviar siempre `Accept: application/json`.

| Método | Ruta | Token | Respuestas verificadas |
|---|---|---|---|
| POST | `/api/register` | No | `201` (devuelve `message` y `user` con `user_id`, `name`, `email`, `role`; **no** devuelve token), `422` (campos faltantes, email repetido o datos inválidos) |
| POST | `/api/login` | No | `200` (devuelve `message`, `access_token`, `token_type`, `expires_in` y `user` con `user_id`, `name`, `email`, `role`), `401` (clave incorrecta), `422` (falta email o password) |
| GET | `/api/profile` | Sí | `200`, `401` (sin token o token falso) |
| PUT | `/api/profile` | Sí | `422` (email con formato inválido) |
| POST | `/api/logout` | Sí | `200`; después el mismo token da `401` |
| POST | `/api/logout-all` | Sí | No lo cubre ninguna prueba automatizada ni `validar-auth.ps1`. Pasa por `CheckUserStatus` (rechaza usuarios inactivos). |
| POST | `/api/refresh` | Sin middleware de autenticación (límite de 5 por minuto) | Cubierto por `AuthSecurityTest` (token refresh security). |

Notas:
- Las rutas protegidas usan el guard `api` y el middleware `CheckUserStatus`.
- El registro público no permite elegir `role=admin`; todo usuario nuevo queda con `role: user`.
- Un usuario inactivo no puede iniciar sesión y hay protección contra fuerza bruta en el login (cubierto por `AuthSecurityTest`).
- Las rutas bajo `/api/admin/*` (marcas y logs de pago) responden `403` a un usuario con rol normal. Verificado con `validar-auth.ps1`.
- **El `403` no se puede probar desde la interfaz porque aún no hay pantallas de admin.** Queda cubierto por el código del frontend y por el backend.

## Validación

- `php artisan test`: 10 pasados (19 aserciones).
- `..\validar-auth.ps1`: 12 chequeos `OK`, 0 `FAIL`.