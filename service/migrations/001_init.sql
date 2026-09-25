-- Esquema inicial de Claudia. Todas las fechas son timestamps UNIX (segundos).

CREATE TABLE kv (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE users (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    nick               TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    pass_hash          TEXT    NOT NULL,
    apodo              TEXT,
    coins              INTEGER NOT NULL DEFAULT 0,
    total_won          INTEGER NOT NULL DEFAULT 0,   -- ganancia neta acumulada en juegos
    total_lost         INTEGER NOT NULL DEFAULT 0,   -- pérdida neta acumulada en juegos
    birthday           TEXT,                         -- 'MM-DD'
    last_birthday_year INTEGER,
    last_ip            TEXT,
    last_authid        TEXT,
    created_at         INTEGER NOT NULL,
    last_seen          INTEGER NOT NULL
);

-- Memoria de Claudia sobre cada usuario.
-- kind: 'pensamiento' | 'trato' | 'dato' | 'resumen'
CREATE TABLE memories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL,
    text       TEXT    NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_memories_user ON memories(user_id, kind);

CREATE TABLE stats (
    user_id   INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    kills     INTEGER NOT NULL DEFAULT 0,
    deaths    INTEGER NOT NULL DEFAULT 0,
    headshots INTEGER NOT NULL DEFAULT 0,
    shots     INTEGER NOT NULL DEFAULT 0,
    hits      INTEGER NOT NULL DEFAULT 0,
    playtime  INTEGER NOT NULL DEFAULT 0,  -- segundos jugados (en un equipo)
    messages  INTEGER NOT NULL DEFAULT 0
);

-- Libro contable por usuario: cada movimiento de coins deja una fila.
CREATE TABLE transactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL,   -- transfer_in, transfer_out, game_bet, game_payout, salary, bonus, loan, loan_payment, confiscation, admin, birthday
    amount        INTEGER NOT NULL,   -- positivo = entra, negativo = sale
    balance_after INTEGER NOT NULL,
    ref           TEXT,
    created_at    INTEGER NOT NULL
);
CREATE INDEX idx_transactions_user ON transactions(user_id, created_at);

CREATE TABLE banks (
    id         TEXT PRIMARY KEY,
    liquidity  INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE loans (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bank              TEXT    NOT NULL,
    principal         INTEGER NOT NULL,
    interest          INTEGER NOT NULL,
    surcharges        INTEGER NOT NULL DEFAULT 0,
    outstanding       INTEGER NOT NULL,
    rate              REAL    NOT NULL,
    late_daily_rate   REAL    NOT NULL,
    term_days         INTEGER NOT NULL,
    created_at        INTEGER NOT NULL,
    due_at            INTEGER NOT NULL,
    surcharge_days    INTEGER NOT NULL DEFAULT 0,  -- días de atraso ya recargados
    status            TEXT    NOT NULL DEFAULT 'active',  -- active | paid
    paid_at           INTEGER
);
CREATE INDEX idx_loans_user ON loans(user_id, status);

CREATE TABLE clearing (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    since   INTEGER NOT NULL
);

CREATE TABLE promotions_active (
    id         TEXT PRIMARY KEY,
    started_at INTEGER NOT NULL,
    ends_at    INTEGER NOT NULL
);

CREATE TABLE employment (
    user_id      INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    job          TEXT    NOT NULL,
    employer     TEXT    NOT NULL,
    level        INTEGER NOT NULL DEFAULT 0,
    hired_at     INTEGER NOT NULL,
    level_since  INTEGER NOT NULL,
    last_paid_at INTEGER,
    act_playtime INTEGER NOT NULL DEFAULT 0,  -- actividad desde el último cobro
    act_kills    INTEGER NOT NULL DEFAULT 0,
    act_messages INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE job_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    job        TEXT    NOT NULL,
    employer   TEXT    NOT NULL,
    level      INTEGER NOT NULL,
    reason     TEXT    NOT NULL,   -- despido | renuncia
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_job_history_user ON job_history(user_id, job);

CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    text       TEXT    NOT NULL,
    due_at     INTEGER NOT NULL,
    delivered  INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_reminders_due ON reminders(delivered, due_at);

-- Rondas de juegos. Una ronda 'open' al arrancar el servicio se reembolsa.
CREATE TABLE game_rounds (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game       TEXT    NOT NULL,
    bet        INTEGER NOT NULL,
    payout     INTEGER NOT NULL DEFAULT 0,
    status     TEXT    NOT NULL DEFAULT 'open',  -- open | settled | refunded
    detail     TEXT,
    created_at INTEGER NOT NULL,
    settled_at INTEGER
);
CREATE INDEX idx_game_rounds_status ON game_rounds(status);
