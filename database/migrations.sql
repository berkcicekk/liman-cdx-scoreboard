-- Execute on Liman's existing PostgreSQL database as the migration owner.
BEGIN;
SELECT pg_advisory_xact_lock(741932001);

CREATE TABLE IF NOT EXISTS ls_teams (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    name VARCHAR(80) NOT NULL CHECK (length(btrim(name)) > 0),
    color CHAR(7) NOT NULL DEFAULT '#36a3ff' CHECK (color ~ '^#[0-9a-fA-F]{6}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (server_id, name),
    UNIQUE (id, server_id)
);

CREATE TABLE IF NOT EXISTS ls_services (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    team_id BIGINT NOT NULL,
    name VARCHAR(80) NOT NULL CHECK (length(btrim(name)) > 0),
    protocol VARCHAR(5) NOT NULL CHECK (protocol IN ('HTTP','HTTPS','SSH','DNS','LDAP','WINRM','PING')),
    host INET NOT NULL CHECK (masklen(host) = CASE family(host) WHEN 4 THEN 32 ELSE 128 END),
    port INTEGER NOT NULL CHECK (port BETWEEN 1 AND 65535),
    weight NUMERIC(10,2) NOT NULL CHECK (weight > 0 AND weight <= 10000),
    timeout_ms INTEGER NOT NULL DEFAULT 3000 CHECK (timeout_ms BETWEEN 100 AND 5000),
    interval_seconds INTEGER NOT NULL DEFAULT 10 CHECK (interval_seconds BETWEEN 10 AND 300),
    options JSONB NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(options) = 'object'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    starts_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    ends_at TIMESTAMPTZ,
    next_probe_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    lease_token VARCHAR(64),
    lease_until TIMESTAMPTZ,
    CHECK (ends_at IS NULL OR ends_at > starts_at),
    FOREIGN KEY (team_id, server_id) REFERENCES ls_teams(id, server_id),
    UNIQUE (team_id, name),
    UNIQUE (id, server_id)
);

CREATE TABLE IF NOT EXISTS ls_service_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    service_id BIGINT NOT NULL,
    checked_at TIMESTAMPTZ NOT NULL,
    is_up BOOLEAN NOT NULL,
    latency_ms NUMERIC(12,3) NOT NULL CHECK (latency_ms >= 0),
    detail VARCHAR(200) NOT NULL,
    run_token VARCHAR(64) NOT NULL UNIQUE,
    FOREIGN KEY (service_id, server_id) REFERENCES ls_services(id, server_id),
    UNIQUE (service_id, checked_at)
);

CREATE TABLE IF NOT EXISTS ls_injects (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    team_id BIGINT NOT NULL,
    kind VARCHAR(7) NOT NULL CHECK (kind IN ('task', 'penalty')),
    points NUMERIC(12,2) NOT NULL CHECK (points BETWEEN -100000 AND 100000 AND points <> 0),
    reason VARCHAR(500) NOT NULL CHECK (length(btrim(reason)) > 0),
    created_by UUID NOT NULL,
    request_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CHECK ((kind = 'task' AND points > 0) OR (kind = 'penalty' AND points < 0)),
    FOREIGN KEY (team_id, server_id) REFERENCES ls_teams(id, server_id),
    UNIQUE (server_id, request_id)
);

CREATE TABLE IF NOT EXISTS ls_vulnerabilities (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    team_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL CHECK (length(btrim(name)) > 0),
    check_type VARCHAR(14) NOT NULL CHECK (check_type IN ('REMOTE_EXPLOIT','SSH_AUDIT')),
    command VARCHAR(100) NOT NULL CHECK (command ~ '^[A-Za-z0-9._-]+$'),
    points NUMERIC(10,2) NOT NULL CHECK (points > 0 AND points <= 10000),
    status VARCHAR(10) NOT NULL DEFAULT 'Vulnerable' CHECK (status IN ('Patched','Vulnerable')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    last_checked_at TIMESTAMPTZ,
    lease_token VARCHAR(64),
    lease_until TIMESTAMPTZ,
    FOREIGN KEY (team_id) REFERENCES ls_teams(id) ON DELETE CASCADE,
    UNIQUE (team_id, name)
);

ALTER TABLE ls_vulnerabilities ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp();

CREATE TABLE IF NOT EXISTS ls_vulnerability_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vulnerability_id BIGINT NOT NULL REFERENCES ls_vulnerabilities(id) ON DELETE CASCADE,
    checked_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    is_patched BOOLEAN NOT NULL,
    exit_code INTEGER,
    duration_ms NUMERIC(12,3) NOT NULL CHECK (duration_ms >= 0),
    output VARCHAR(1000) NOT NULL DEFAULT '',
    CHECK (exit_code IS NULL OR exit_code BETWEEN -255 AND 255)
);

CREATE INDEX IF NOT EXISTS ls_services_due_idx ON ls_services(next_probe_at, lease_until);
CREATE INDEX IF NOT EXISTS ls_services_scope_idx ON ls_services(server_id, team_id);
CREATE INDEX IF NOT EXISTS ls_logs_timeline_idx ON ls_service_logs(server_id, service_id, checked_at) INCLUDE (is_up);
CREATE INDEX IF NOT EXISTS ls_injects_timeline_idx ON ls_injects(server_id, team_id, created_at);
CREATE INDEX IF NOT EXISTS ls_vulnerabilities_due_idx ON ls_vulnerabilities(last_checked_at, lease_until);
CREATE INDEX IF NOT EXISTS ls_vulnerability_logs_timeline_idx ON ls_vulnerability_logs(vulnerability_id, checked_at DESC);
COMMENT ON TABLE ls_service_logs IS 'Append-only measurements; a sample covers at most one service interval into the future.';
COMMENT ON TABLE ls_injects IS 'Append-only White Team audit ledger; corrections use a compensating entry.';
COMMENT ON TABLE ls_vulnerabilities IS 'Command is a config allowlist key; the database never contains executable shell text.';
COMMENT ON TABLE ls_vulnerability_logs IS 'Append-only vulnerability check evidence. Output is truncated and redacted before storage.';
COMMIT;
