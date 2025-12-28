BEGIN;

CREATE TABLE IF NOT EXISTS meta (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS balances (
  asset TEXT PRIMARY KEY,
  amount REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS purchases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),

  buy_usdt REAL NOT NULL,
  buy_order_id TEXT,
  buy_price REAL,
  buy_qty REAL,
  buy_filled_at TEXT,

  status TEXT NOT NULL,

  sell_markup_pct REAL NOT NULL,
  sell_order_id TEXT,
  sell_price REAL,
  sell_qty REAL,
  sell_filled_at TEXT,
  sell_usdt REAL,

  profit_usdt REAL,
  profit_usdc REAL
);

CREATE INDEX IF NOT EXISTS idx_purchases_status ON purchases(status);
CREATE INDEX IF NOT EXISTS idx_purchases_created_at ON purchases(created_at);

CREATE TABLE IF NOT EXISTS events_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  type TEXT NOT NULL,
  payload_json TEXT NOT NULL
);

COMMIT;

