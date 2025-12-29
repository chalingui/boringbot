# boringbot (PHP + SQLite) — Bybit Spot DCA

Bot de DCA semanal para `ETHUSDT` con una venta `LIMIT` al `+5%` (configurable). Cada compra es una entidad independiente (Compra `#N`) y se vende completa (sin ventas parciales en la contabilidad del bot).

## Requisitos
- PHP 8.1+ (probado con PHP 8.2)
- Extensiones PHP: `pdo_sqlite`, `curl`
- Cuenta Bybit con API key de **Spot trading** (sin retiros)

## Configuración
1) Copia el ejemplo de entorno:
```bash
cp config/.env.example .env
```

2) Edita `.env` y define:
- `BYBIT_API_KEY`
- `BYBIT_API_SECRET`
- `BYBIT_ACCOUNT_TYPE` (recomendado `UNIFIED`)
- Opcional: `SELL_MARKUP_PCT`, `DCA_AMOUNT_USDT`, `DCA_INTERVAL_DAYS`

## Notificaciones por email (opcional)
Configurar en `.env` (no commitear credenciales):
- `NOTIFY_ENABLED=1`
- `NOTIFY_EMAIL_TO=tu@email.com`
- `NOTIFY_EMAIL_FROM=postmaster@tu-dominio.com` (o el usuario SMTP)
- `SMTP_HOST`, `SMTP_PORT` (default 587), `SMTP_ENCRYPTION` (`starttls`), `SMTP_USER`, `SMTP_PASS`

Test manual:
```bash
php bin/notify-test.php
php bin/notify-test.php --dry-run
```

Eventos notificados:
- Compra creada (cuando se coloca la orden de compra real)
- Venta ejecutada (cuando se completa la venta y la conversión de profit a USDC)
- Sin USDT suficiente para comprar (con cooldown `NOTIFY_COOLDOWN_MINUTES`)

3) Crea carpetas si hace falta (logs/lock/db se crean solos, pero recomendado):
```bash
mkdir -p db logs storage
```

## Importante: “balances” (libro contable del bot)
`balances` **NO** es el balance real del exchange. Representa los fondos autorizados para operar con el bot.

Depósitos de USDT se reflejan con reconciliación explícita:
```bash
php bin/reconcile.php
php bin/reconcile.php --dry-run
```
- El comando consulta el balance real en Bybit.
- Solo ajusta **en positivo** (si `bybit_usdt > bot_usdt`).
- Registra log y evento `RECONCILE` en `events_log`.

## Ejecución (cron)
Corre cada 5 minutos:
```cron
*/5 * * * * cd /path/to/boringbot && php bin/run.php >> logs/cron.log 2>&1
```

Incluye lock anti doble ejecución en `storage/boringbot.lock`.

Modo simulación:
```bash
php bin/run.php --dry-run
```

## Estado / reporting
```bash
php bin/status.php
php bin/status.php --id 3
```
Muestra:
- Compras `BUYING` / `HOLDING` / `OPEN` / `SOLD` (y `SOLD_PENDING_CONVERT` si falló la conversión de profit)
- Detalle de compra #N
- Gap vs target (cuando está `OPEN`)
- Balances del bot (USDT/ETH/USDC)

## Lógica de estrategia
- Cada `DCA_INTERVAL_DAYS` compra `DCA_AMOUNT_USDT` de `ETHUSDT` (market buy por monto en USDT).
- Al completarse la compra: crea una orden `LIMIT SELL` al `+SELL_MARKUP_PCT`.
- Al completarse la venta:
  - `100 USDT` vuelven a `balances.USDT` (capital_pool).
  - `profit = sell_usdt - 100` se convierte a `USDC` vía market en `USDCUSDT` y se acumula en `balances.USDC`.
  - Si falla la conversión, el profit queda temporalmente como USDT y se reintenta (`SOLD_PENDING_CONVERT`).

## Base de datos
- SQLite: `db/boringbot.sqlite`
- Schema: `db/schema.sql`

## Archivos principales
- `bin/run.php` — tick del bot (para cron)
- `bin/reconcile.php` — reconciliación USDT (depósitos)
- `bin/status.php` — estado y detalle
- `src/Exchange/BybitClient.php` — cliente Bybit v5 (Spot)

## Deploy en cPanel (nota)
Si clonás el repo dentro de `public_html`, bloqueá acceso web a `src/`, `bin/`, `config/`, `db/`, `logs/`, `storage/` (incluye `.env`). Este repo incluye `.htaccess` para eso; aun así, lo ideal es apuntar el DocumentRoot a otra carpeta.
