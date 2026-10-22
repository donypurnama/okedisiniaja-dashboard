CREATE TABLE IF NOT EXISTS users (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    name               TEXT    NOT NULL,
    email              TEXT    UNIQUE COLLATE NOCASE,
    whatsapp           TEXT    UNIQUE,
    password_hash      TEXT    NOT NULL,
    business_name      TEXT,
    website_url        TEXT,
    instagram_handle   TEXT,
    package_active     TEXT,
    package_billing    TEXT,
    package_expires_at TEXT,
    role               TEXT    NOT NULL DEFAULT 'client' CHECK (role IN ('client', 'admin')),
    status             TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    last_login_at      TEXT,
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (email IS NOT NULL OR whatsapp IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_users_status ON users (status);

CREATE TABLE IF NOT EXISTS orders (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    order_code     TEXT UNIQUE NOT NULL,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    package_name   TEXT NOT NULL,
    billing_cycle  TEXT NOT NULL DEFAULT 'monthly' CHECK (billing_cycle IN ('monthly', 'yearly', 'custom')),
    amount         INTEGER NOT NULL,
    discount       INTEGER NOT NULL DEFAULT 0,
    total_amount   INTEGER NOT NULL,
    voucher_code   TEXT,
    notes          TEXT,
    status         TEXT NOT NULL DEFAULT 'pending_payment' CHECK (status IN ('pending_payment', 'reviewing', 'active', 'cancelled', 'expired')),
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id);

CREATE TABLE IF NOT EXISTS payments (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id         INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bank_destination TEXT NOT NULL,
    sender_bank      TEXT,
    sender_name      TEXT NOT NULL,
    transfer_amount  INTEGER NOT NULL,
    transfer_date    TEXT NOT NULL,
    proof_file       TEXT,
    notes            TEXT,
    admin_notes      TEXT,
    refund_amount    INTEGER DEFAULT 0,
    refund_bank      TEXT,
    refund_account   TEXT,
    refund_date      TEXT,
    status           TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'verified', 'rejected', 'refunded', 'returned')),
    verified_at      TEXT,
    created_at       TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_payments_user ON payments (user_id);

CREATE TABLE IF NOT EXISTS activities (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category      TEXT NOT NULL CHECK (category IN ('article', 'social_media', 'website', 'lead', 'system')),
    title         TEXT NOT NULL,
    description   TEXT,
    meta_info     TEXT,
    status        TEXT NOT NULL DEFAULT 'completed' CHECK (status IN ('draft', 'review', 'scheduled', 'published', 'completed')),
    activity_date TEXT NOT NULL DEFAULT (datetime('now')),
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_activities_user ON activities (user_id);

CREATE TABLE IF NOT EXISTS leads (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    lead_name  TEXT NOT NULL,
    lead_phone TEXT,
    source     TEXT,
    interest   TEXT,
    status     TEXT DEFAULT 'new' CHECK (status IN ('new', 'contacted', 'closed', 'lost')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_leads_user ON leads (user_id);

CREATE TABLE IF NOT EXISTS job_activities (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title             TEXT NOT NULL,
    task_type         TEXT NOT NULL DEFAULT 'article',
    upload_date       TEXT NOT NULL,
    article_url       TEXT,
    social_url        TEXT,
    check_image_opt   INTEGER NOT NULL DEFAULT 0,
    check_social_post INTEGER NOT NULL DEFAULT 0,
    check_social_link INTEGER NOT NULL DEFAULT 0,
    article_count     INTEGER NOT NULL DEFAULT 1,
    photo_count       INTEGER NOT NULL DEFAULT 0,
    social_post_count INTEGER NOT NULL DEFAULT 0,
    check_backup      INTEGER NOT NULL DEFAULT 0,
    status            TEXT NOT NULL DEFAULT 'in_progress' CHECK (status IN ('draft', 'in_progress', 'scheduled', 'completed')),
    notes             TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_job_activities_user ON job_activities (user_id);

CREATE TABLE IF NOT EXISTS notifications (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    type         TEXT NOT NULL DEFAULT 'system',
    title        TEXT NOT NULL,
    message      TEXT,
    link         TEXT,
    is_read      INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications (recipient_id, is_read, id);
