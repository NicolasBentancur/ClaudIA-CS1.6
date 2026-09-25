-- Parejas, familias, grupos e inventario de la tienda.

-- Relaciones de pareja. Activa = ended_at NULL. Un jugador tiene como mucho una activa.
CREATE TABLE relationships (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    a_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,  -- quien propuso
    b_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status     TEXT    NOT NULL DEFAULT 'pareja',   -- pareja | casados
    started_at INTEGER NOT NULL,
    married_at INTEGER,
    kisses     INTEGER NOT NULL DEFAULT 0,
    ended_at   INTEGER,
    end_reason TEXT
);
CREATE INDEX idx_relationships_a ON relationships(a_id, ended_at);
CREATE INDEX idx_relationships_b ON relationships(b_id, ended_at);

-- Últimas ex parejas de cada jugador (se guardan como mucho social.max_ex por jugador).
CREATE TABLE ex_partners (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ex_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    started_at  INTEGER NOT NULL,
    ended_at    INTEGER NOT NULL,
    was_married INTEGER NOT NULL DEFAULT 0,
    reason      TEXT
);
CREATE INDEX idx_ex_partners_user ON ex_partners(user_id, ended_at);

-- Una familia nace con un casamiento. Los padres siguen siéndolo aunque se divorcien.
CREATE TABLE families (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_a        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_b        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    relationship_id INTEGER REFERENCES relationships(id),
    surname         TEXT,
    created_at      INTEGER NOT NULL
);
CREATE INDEX idx_families_a ON families(parent_a);
CREATE INDEX idx_families_b ON families(parent_b);

-- Hijos adoptados. Cada jugador puede ser hijo de una sola familia.
CREATE TABLE family_children (
    user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    family_id  INTEGER NOT NULL REFERENCES families(id) ON DELETE CASCADE,
    adopted_at INTEGER NOT NULL
);
CREATE INDEX idx_family_children_family ON family_children(family_id);

-- Grupos (clanes). El fondo común son coins que salieron de los miembros: nunca se crean coins.
CREATE TABLE groups (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    tag          TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    description  TEXT    NOT NULL DEFAULT '',
    private      INTEGER NOT NULL DEFAULT 0,
    owner_id     INTEGER NOT NULL REFERENCES users(id),
    pool         INTEGER NOT NULL DEFAULT 0,
    kills        INTEGER NOT NULL DEFAULT 0,   -- ranking cosmético
    owner_rounds INTEGER NOT NULL DEFAULT 0,   -- rondas del dueño desde el último impuesto
    created_at   INTEGER NOT NULL
);

CREATE TABLE group_members (
    user_id   INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    group_id  INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    joined_at INTEGER NOT NULL,
    rounds    INTEGER NOT NULL DEFAULT 0,   -- rondas desde la última cuota
    kills     INTEGER NOT NULL DEFAULT 0,   -- kills aportadas al grupo
    fees_paid INTEGER NOT NULL DEFAULT 0,
    donated   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_group_members_group ON group_members(group_id);

CREATE TABLE group_requests (
    group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (group_id, user_id)
);

-- Movimientos del fondo común.
-- kind: cuota, cuota_impaga, donacion, pago, impuesto, reparto
CREATE TABLE group_ledger (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,   -- positivo = entra al fondo, negativo = sale
    user_id    INTEGER REFERENCES users(id),
    pool_after INTEGER NOT NULL,
    note       TEXT,
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_group_ledger_group ON group_ledger(group_id, id);

-- Objetos comprados en la tienda que se guardan (ej. anillos).
CREATE TABLE inventory (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    item    TEXT    NOT NULL,
    qty     INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, item)
);
