-- Create the LOGIN role and set its password interactively BEFORE running this file.
-- Run as table owner on Liman's existing database. Never grant access to core tables.
BEGIN;
GRANT USAGE ON SCHEMA public TO ls_scoreboard;
GRANT SELECT, INSERT ON ls_teams, ls_services, ls_service_logs, ls_injects, ls_vulnerability_logs TO ls_scoreboard;
GRANT SELECT ON ls_vulnerabilities TO ls_scoreboard;
GRANT UPDATE (next_probe_at, lease_token, lease_until) ON ls_services TO ls_scoreboard;
GRANT UPDATE (status, last_checked_at, lease_token, lease_until) ON ls_vulnerabilities TO ls_scoreboard;
GRANT USAGE, SELECT ON SEQUENCE ls_teams_id_seq, ls_services_id_seq, ls_service_logs_id_seq, ls_injects_id_seq, ls_vulnerability_logs_id_seq TO ls_scoreboard;
COMMIT;
