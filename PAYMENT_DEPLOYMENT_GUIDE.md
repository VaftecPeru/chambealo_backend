# 🚀 Guía de Despliegue - Sistema de Pagos

## Pre-Despliegue (Local/Staging)

### ✅ Checklist Pre-Despliegue

- [ ] Todos los archivos están en lugar correcto
- [ ] Migraciones están listas
- [ ] Variables de entorno están configuradas
- [ ] Código PHP sin errores de sintaxis
- [ ] Rutas API están funcionando
- [ ] Webhooks configurados en gateways de test

### 1. Clonar/Actualizar Código

```bash
cd /var/www/chambealo_backend
git pull origin main
```

### 2. Instalar/Actualizar Dependencias

```bash
composer install --no-dev --prefer-dist
```

### 3. Configurar Entorno

```bash
# Copiar archivo de entorno
cp .env.example .env

# Agregar variables de pago
cat >> .env << 'EOF'

# Izipay
IZIPAY_ENV=production
IZIPAY_CLIENT_ID=prod_client_id
IZIPAY_SECRET=prod_secret
IZIPAY_HASH_KEY=prod_hash_key
IZIPAY_WEBHOOK_SECRET=prod_webhook_secret
IZIPAY_URL=https://api.izipay.com
IZIPAY_PUBLIC_KEY=prod_public_key

# MercadoPago
MERCADOPAGO_ACCESS_TOKEN=prod_access_token
MERCADOPAGO_PUBLIC_KEY=prod_public_key
MERCADOPAGO_WEBHOOK_SECRET=prod_webhook_secret

# PayPal
PAYPAL_ENV=production
PAYPAL_CLIENT_ID=prod_client_id
PAYPAL_CLIENT_SECRET=prod_client_secret
EOF
```

### 4. Ejecutar Migraciones

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

### 5. Configurar Permisos

```bash
# Usuarios Laravel
sudo chown -R www-data:www-data /var/www/chambealo_backend
sudo chmod -R 755 /var/www/chambealo_backend
sudo chmod -R 777 /var/www/chambealo_backend/storage
sudo chmod -R 777 /var/www/chambealo_backend/bootstrap/cache
```

### 6. Configurar Web Server

#### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    
    root /var/www/chambealo_backend/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Restricción extra para webhooks (opcional)
    location ~ /api/payment/webhook/ {
        # Permitir solo POST
        if ($request_method !~ ^(POST)$) {
            return 405;
        }
    }
    
    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}

# Redirigir HTTP a HTTPS
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

#### Apache

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/chambealo_backend/public
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem
    
    <Directory /var/www/chambealo_backend/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/var/run/php/php8.1-fpm.sock|fcgi://localhost"
    </FilesMatch>
    
    # Security headers
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>

# Redirigir HTTP a HTTPS
<VirtualHost *:80>
    ServerName yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>
```

---

## Despliegue a Producción

### 1. Verificar Credenciales Reales

```bash
# NO usar sandbox
IZIPAY_ENV=production
PAYPAL_ENV=production

# Obtener tokens reales de cada gateway
# Izipay: https://dashboard.izipay.com
# MercadoPago: https://www.mercadopago.com.ar/developers/panel
# PayPal: https://developer.paypal.com
```

### 2. Configurar SSL/TLS

```bash
# Usar Let's Encrypt (gratuito)
sudo apt-get install certbot python3-certbot-nginx
sudo certbot certonly --nginx -d yourdomain.com

# O comprar certificado comercial
```

### 3. Registrar Webhooks en Gateways

#### Izipay
```
Dashboard → Settings → Webhooks
URL: https://yourdomain.com/api/payment/webhook/izipay
Events: payment.created, payment.updated, payment.failed
```

#### MercadoPago
```
Cuenta → Configuración → Webhooks
URL: https://yourdomain.com/api/payment/webhook/mercadopago
Topics: payment
```

#### PayPal
```
Developer Dashboard → Apps & Credentials → Webhooks
URL: https://yourdomain.com/api/payment/webhook/paypal
Events: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, etc
```

### 4. Configurar Firewall

```bash
# Permitir HTTPS
sudo ufw allow 443/tcp
sudo ufw allow 80/tcp

# Opcional: Restringir IP de gateways
sudo ufw allow from 1.2.3.4 to any port 443  # Izipay
sudo ufw allow from 5.6.7.8 to any port 443  # MercadoPago
```

### 5. Monitoreo y Logs

```bash
# Configurar rotación de logs
cat > /etc/logrotate.d/laravel << 'EOF'
/var/www/chambealo_backend/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
EOF
```

### 6. Backup y Recovery

```bash
# Script de backup
#!/bin/bash
BACKUP_DIR="/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Backup de código
tar -czf $BACKUP_DIR/chambealo_$TIMESTAMP.tar.gz /var/www/chambealo_backend

# Backup de BD
mysqldump -u root -p db_backend | gzip > $BACKUP_DIR/db_backup_$TIMESTAMP.sql.gz

# Mantener últimos 30 días
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete
```

---

## Post-Despliegue

### ✅ Verificación

```bash
# 1. Verificar que API responde
curl -X GET https://yourdomain.com/api/payment/session \
  -H "Authorization: Bearer TEST_TOKEN" \
  -w "\n%{http_code}\n"

# 2. Verificar logs sin errores
tail -f /var/www/chambealo_backend/storage/logs/laravel.log

# 3. Verificar webhooks configurados
curl -X POST https://yourdomain.com/api/payment/webhook/izipay \
  -H "X-Izipay-Signature: test" \
  -H "Content-Type: application/json" \
  -d '{"kr-answer": "test"}'
```

### 🔄 Testing de Pagos en Producción

```bash
# 1. Crear sesión de pago
curl -X POST https://yourdomain.com/api/payment/session \
  -H "Authorization: Bearer REAL_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "mercadopago",
    "order_id": 1,
    "amount": 10.00,
    "currency": "ARS",
    "email": "test@example.com"
  }'

# 2. Realizar pago a través del gateway
# (usualmente con tarjeta de test)

# 3. Confirmar pago
curl -X POST https://yourdomain.com/api/payment/confirm \
  -H "Authorization: Bearer REAL_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "mercadopago",
    "payment_id": "PAYMENT_ID_FROM_WEBHOOK"
  }'

# 4. Verificar en BD
mysql -u root -p db_backend -e "SELECT * FROM payments ORDER BY id DESC LIMIT 5;"
```

### 📊 Monitoreo Continuo

```bash
# Crear cron job para monitoreo
crontab -e

# Agregar:
# Verificar failed payments cada 15 min
*/15 * * * * curl -s https://yourdomain.com/api/health/payment-status | logger

# Backup diario a las 2 AM
0 2 * * * /usr/local/bin/backup-chambealo.sh

# Limpieza de logs antigos semanalmente
0 3 * * 0 find /var/www/chambealo_backend/storage/logs -name "*.log" -mtime +90 -delete
```

### ⚠️ Alertas

Configurar alertas para:

```bash
# Fallos de webhook
tail -f storage/logs/laravel.log | grep "Webhook.*failed"

# Fallos de pago
tail -f storage/logs/laravel.log | grep "Payment.*error"

# Timeouts
tail -f storage/logs/laravel.log | grep "timeout"

# Errores de configuración
tail -f storage/logs/laravel.log | grep "configuration missing"
```

---

## Troubleshooting de Despliegue

### "Connection refused" al webhook

**Problema**: Gateway no puede conectarse al servidor

**Solución**:
```bash
# Verificar que HTTPS está habilitado
curl -v https://yourdomain.com/api/payment/webhook/izipay

# Verificar firewall
sudo ufw status
sudo ufw allow 443/tcp

# Verificar DNS
nslookup yourdomain.com
dig yourdomain.com
```

### "Invalid signature" en webhook

**Problema**: Webhook secret no coincide

**Solución**:
```bash
# Verificar que .env tiene los valores correctos
grep WEBHOOK_SECRET .env

# Regenerar webhook secret en panel del gateway
# Actualizar en .env
# Ejecutar: php artisan config:cache
```

### "Payment not found" después de webhook

**Problema**: createSession no fue exitoso o payment_id no se guardó

**Solución**:
```bash
# Verificar que payment existe en BD
mysql -u root -p db_backend -e "
  SELECT * FROM payments WHERE payment_id = 'EXTERNAL_ID';
"

# Verificar logs
grep "createSession" storage/logs/laravel.log | tail -10

# Verificar que credenciales del gateway son correctas
echo "IZIPAY_CLIENT_ID: $IZIPAY_CLIENT_ID"
echo "MERCADOPAGO_ACCESS_TOKEN: $MERCADOPAGO_ACCESS_TOKEN"
```

### "Throttle exceeded"

**Problema**: Demasiadas requests en poco tiempo

**Solución**:
```bash
# Esperar a que se reinicie el contador (1 minuto)
# O aumentar límites en PaymentController si es necesario

# Revisar en código:
new Middleware('throttle:5,1', only: ['createSession', 'confirm']),
new Middleware('throttle:20,1', only: ['webhook']),
```

---

## Rollback de Despliegue

Si algo sale mal:

```bash
# 1. Revertir a versión anterior
git revert HEAD
git push origin main

# 2. Ejecutar migraciones en reverso
php artisan migrate:rollback

# 3. Restaurar desde backup
tar -xzf /backups/chambealo_YYYYMMDD_HHMMSS.tar.gz

# 4. Restaurar BD
gunzip < /backups/db_backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u root -p db_backend

# 5. Verificar que sistema funciona
php artisan tinker
>>> \App\Models\Payment::count()
```

---

## Checklist Post-Despliegue

- [ ] API responde a requests
- [ ] Webhooks se reciben y procesan
- [ ] Transacciones se guardan en BD
- [ ] Emails se envían correctamente
- [ ] Logs no tienen errores críticos
- [ ] Monitoreo está configurado
- [ ] Backups están funcionando
- [ ] Alertas están configuradas
- [ ] Documentación está actualizada
- [ ] Equipo capacitado en mantenimiento

---

## Contacto de Soporte

- **Izipay Support**: support@izipay.com
- **MercadoPago Support**: help.mercadopago.com
- **PayPal Support**: developer.paypal.com/support

---

**Última actualización**: 25 de mayo de 2026
**Versión**: 1.0.0
