-- Requested deployment profile: full privileges ONLY on extension objects.
-- PostgreSQL role "liman" is distinct from the Linux system account.
BEGIN;
GRANT USAGE ON SCHEMA public TO liman;
GRANT ALL PRIVILEGES ON TABLE public.ls_teams, public.ls_services,
    public.ls_service_logs, public.ls_injects TO liman;
GRANT ALL PRIVILEGES ON SEQUENCE public.ls_teams_id_seq, public.ls_services_id_seq,
    public.ls_service_logs_id_seq, public.ls_injects_id_seq TO liman;
COMMIT;
