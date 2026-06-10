CREATE TABLE IF NOT EXISTS notification_batches (
    id UUID PRIMARY KEY,
    idempotency_key VARCHAR(128) NOT NULL UNIQUE,
    channel VARCHAR(16) NOT NULL,
    priority VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS notifications (
    id UUID PRIMARY KEY,
    batch_id UUID NOT NULL REFERENCES notification_batches(id) ON DELETE CASCADE,
    recipient_id VARCHAR(128) NOT NULL,
    channel VARCHAR(16) NOT NULL,
    priority VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    provider_message_id VARCHAR(128),
    error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notifications_recipient_id ON notifications(recipient_id);
CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status);
CREATE INDEX IF NOT EXISTS idx_notifications_batch_id ON notifications(batch_id);
