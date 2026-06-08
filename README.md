# boringbot (PHP + SQLite) — Bybit Spot DCA

Bot de DCA semanal para `ETHUSDT` y `BTCUSDT` con una venta `LIMIT` al `+5%` (configurable). Cada compra es una entidad independiente (Compra `#N`) y se vende completa (sin ventas parciales en la contabilidad del bot).

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
- Opcional: `BUY_ENABLED=0` para pausar compras nuevas sin frenar ventas/reconciliación
- Opcional: `SELL_MARKUP_PCT`, `DCA_AMOUNT_USDT`, `DCA_INTERVAL_DAYS`
- Opcional: `SYMBOL_TRADE_ETH` (default `ETHUSDT`)
- Opcional: `SYMBOL_TRADE_BTC` (default `BTCUSDT`)

## Notificaciones por email (opcional)
Configurar en `.env` (no commitear credenciales):
- `NOTIFY_ENABLED=1`
- `NOTIFY_EMAIL_TO=tu@email.com`
- `NOTIFY_EMAIL_FROM=postmaster@tu-dominio.com` (o el usuario SMTP)
- `NOTIFY_NO_FUNDS_LEAD_HOURS=24` (aviso si falta USDT dentro de esa ventana antes del próximo DCA)
- `NOTIFY_COOLDOWN_MINUTES=60` (máximo 1 aviso por hora)
- `SMTP_HOST`, `SMTP_PORT` (default 587), `SMTP_ENCRYPTION` (`starttls`), `SMTP_USER`, `SMTP_PASS`

Test manual:
```bash
php bin/notify-test.php
php bin/notify-test.php --dry-run
```

Eventos notificados:
- Compra creada (cuando se coloca la orden de compra real)
- Venta ejecutada (cuando se completa la venta y se registra el profit)
- Sin USDT suficiente para comprar (con cooldown `NOTIFY_COOLDOWN_MINUTES`)
- Sin USDT suficiente para el próximo DCA (aviso “anticipado” dentro de `NOTIFY_NO_FUNDS_LEAD_HOURS`, con frecuencia limitada por `NOTIFY_COOLDOWN_MINUTES`)

3) Crea carpetas si hace falta (logs/lock/db se crean solos, pero recomendado):
```bash
mkdir -p db logs storage
```

## Importante: “balances” (libro contable del bot)
`balances` **NO** es el balance real del exchange. Representa los fondos autorizados para operar con el bot.

Depósitos de USDT se reflejan con reconciliación (automática en `bin/run.php` y también manual si querés):
```bash
php bin/reconcile.php
php bin/reconcile.php --dry-run
```
- El comando consulta el balance real en Bybit.
- Sincroniza el ledger al saldo real de Bybit, tanto hacia arriba como hacia abajo.
- Registra log y evento `RECONCILE` en `events_log`.

## Ejecución (cron)
Cron único (órdenes + reconciliación de balances + avisos de fondos).  
Corre cada 10 minutos:
```cron
*/10 * * * * cd /path/to/boringbot && php bin/run.php >> logs/cron.log 2>&1
```

Incluye lock anti doble ejecución en `storage/boringbot.lock`.

Modo simulación:
```bash
php bin/run.php --dry-run
```
En `--dry-run` el bot **no crea compras** ni modifica el ledger; solo loguea qué haría.

## Estado / reporting
```bash
php bin/status.php
php bin/status.php --id 3
```
Muestra:
- Compras `BUYING` / `HOLDING` / `OPEN` / `SOLD`
- Detalle de compra #N
- Gap vs target (cuando está `OPEN`)
- Balances del bot (USDT/ETH/BTC)

## Lógica de estrategia
- Cada `DCA_INTERVAL_DAYS` compra `DCA_AMOUNT_USDT` de `ETHUSDT` y `BTCUSDT` (market buy por monto en USDT).
- Si `BUY_ENABLED=0`, no crea compras nuevas, pero sigue gestionando compras/ventas existentes.
- Al completarse la compra: crea una orden `LIMIT SELL` al `+SELL_MARKUP_PCT`.
- Al completarse la venta:
  - `100 USDT` vuelven a `balances.USDT` (capital_pool).
  - `profit = sell_usdt - 100` queda en `balances.USDT` y se acumula como ganancia realizada.
- Opcional: `SELL_QTY_BUFFER` (default `0`) resta un buffer de base asset al reintentar ventas en `HOLDING` para evitar "Insufficient balance".

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

## Acceso al servidor
Producción está en:
- Host: `vps04.xyu.com.ar`
- SSH port: `9022`
- User: `xyucom`
- Repo: `/home8/xyucom/public_html/boringbot.xyu.com.ar`
- SSH key local usada desde esta máquina: `~/.ssh/boringbot_github`

Conexión directa:
```bash
ssh -i ~/.ssh/boringbot_github -p 9022 -o IdentitiesOnly=yes xyucom@vps04.xyu.com.ar
```

Ejecutar un comando remoto:
```bash
ssh -i ~/.ssh/boringbot_github -p 9022 -o IdentitiesOnly=yes xyucom@vps04.xyu.com.ar \
  'cd /home8/xyucom/public_html/boringbot.xyu.com.ar && php bin/status.php'
```

Deploy manual por Git:
```bash
ssh -i ~/.ssh/boringbot_github -p 9022 -o IdentitiesOnly=yes xyucom@vps04.xyu.com.ar \
  'cd /home8/xyucom/public_html/boringbot.xyu.com.ar && git pull --ff-only origin main && php -l dashboard/index.php && php bin/status.php'
```

Notas:
- No commitear ni copiar al repo credenciales de `config/.env`.
- En producción la config efectiva vive en `config/.env`.
- Antes de ejecutar compras reales, usar `php bin/run.php --dry-run`.
- Si hay que operar compras manualmente, verificar primero `php bin/status.php` y `php bin/reconcile.php --dry-run`.

## cPanel Auto-Deploy (opcional)
Este repo incluye `.cpanel.yml` para deployments automáticos desde **cPanel → Git Version Control → Manage → Deploy**.
- Editar `DEPLOYPATH` dentro de `.cpanel.yml` para tu ruta del servidor.
- `config/.env` no se versiona; queda intacto en el servidor.

## Dashboard (opcional)
Hay un dashboard simple en `/dashboard/` (solo lectura) con HTTP Basic Auth.

Configurar en `.env`:
- `DASHBOARD_USER` (default `admin`)
- `DASHBOARD_PASS` (obligatorio)

## Timezone (opcional)
Para mostrar logs/dashboard en horario local, configurar:
- `BORINGBOT_TIMEZONE=America/Argentina/Buenos_Aires`
