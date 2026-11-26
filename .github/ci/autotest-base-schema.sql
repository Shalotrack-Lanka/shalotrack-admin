-- Base schema for the CI test database (schema "autotest"). Schema only: NO customer or business data.
-- The Laravel migrations cannot build the database from nothing (the first one already expects the "Admins" table that
-- was created in Supabase), so CI loads this file first and then runs `php artisan migrate` for anything newer.
-- Refresh it only if the base tables change outside Laravel: pg_dump -n autotest -s --no-owner --no-privileges
--
-- PostgreSQL database dump
--

\restrict hBfLDtChMAnW1zo9PwFIurB6TXZrcwz7XCMCFHOAIO9Zqmyn1Ecmc69g6TEJIJF

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: autotest; Type: SCHEMA; Schema: -; Owner: -
--



--
-- Name: billing_forbid_change(); Type: FUNCTION; Schema: autotest; Owner: -
--

CREATE FUNCTION autotest.billing_forbid_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'Issued billing record in % cannot be % (correct it with a credit note or a reversal)',
        TG_TABLE_NAME, lower(TG_OP) USING ERRCODE = 'integrity_constraint_violation';
END;
$$;


--
-- Name: billing_guard_receipt(); Type: FUNCTION; Schema: autotest; Owner: -
--

CREATE FUNCTION autotest.billing_guard_receipt() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A payment receipt cannot be deleted (reverse it instead)'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF (OLD.id, OLD.number, OLD.issuer_party_id, OLD.buyer_dealer_id, OLD.amount, OLD.method, OLD.reference,
        OLD.received_date, OLD.target_invoice_id, OLD.notes, OLD.recorded_by, OLD.recorded_by_name,
        OLD.snapshot::text, OLD.snapshot_sha256, OLD.previous_hash, OLD.idempotency_key, OLD.created_at)
       IS DISTINCT FROM
       (NEW.id, NEW.number, NEW.issuer_party_id, NEW.buyer_dealer_id, NEW.amount, NEW.method, NEW.reference,
        NEW.received_date, NEW.target_invoice_id, NEW.notes, NEW.recorded_by, NEW.recorded_by_name,
        NEW.snapshot::text, NEW.snapshot_sha256, NEW.previous_hash, NEW.idempotency_key, NEW.created_at)
    THEN
        RAISE EXCEPTION 'Only the status of a payment receipt can change'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('valid', 'rejected'))
        OR (OLD.status = 'valid'   AND NEW.status = 'reversed')
    ) THEN
        RAISE EXCEPTION 'Receipt status cannot move from % to %', OLD.status, NEW.status
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    -- once reversed or rejected the row is final
    IF OLD.status IN ('reversed', 'rejected') AND (
        OLD.status, OLD.approved_by, OLD.approved_by_name, OLD.approved_at, OLD.rejection_reason,
        OLD.reversed_at, OLD.reversed_by, OLD.reversal_reason)
       IS DISTINCT FROM (
        NEW.status, NEW.approved_by, NEW.approved_by_name, NEW.approved_at, NEW.rejection_reason,
        NEW.reversed_at, NEW.reversed_by, NEW.reversal_reason) THEN
        RAISE EXCEPTION 'A % receipt is final', OLD.status USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: Admins; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest."Admins" (
    admin_id uuid DEFAULT gen_random_uuid() NOT NULL,
    username character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    full_name character varying(255),
    email character varying(255),
    phone_number character varying(30),
    role character varying(30) NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    dealer_id bigint,
    supplier_id bigint,
    must_change_password boolean DEFAULT false NOT NULL,
    password_changed_at timestamp(0) without time zone,
    remember_token character varying(100),
    CONSTRAINT admins_role_check CHECK (((role)::text = ANY (ARRAY['ADMIN'::text, 'DEALER'::text, 'FINANCE'::text, 'TECHNICIAN'::text, 'SUPPLIER'::text, 'DISTRIBUTOR'::text]))),
    CONSTRAINT admins_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'INACTIVE'::character varying])::text[])))
);


--
-- Name: Customer-ad; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest."Customer-ad" (
    customer_id uuid NOT NULL,
    full_name character varying(255),
    email character varying(255),
    phone_number character varying(255),
    nic_number character varying(255),
    address text,
    profile_image text,
    vehicle_count integer DEFAULT 0 NOT NULL,
    source_account_status integer,
    last_synced_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    cus_status character varying(255) DEFAULT 'not_verified'::character varying NOT NULL
);


--
-- Name: activated_devices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.activated_devices (
    activated_device_id bigint NOT NULL,
    vehicle_id uuid NOT NULL,
    customer_id uuid,
    customer_name character varying(255),
    vehicle_number character varying(255),
    model character varying(255),
    has_gps_device boolean DEFAULT false NOT NULL,
    imei_number character varying(255) NOT NULL,
    sim_number character varying(255),
    device_category character varying(255),
    payment_status character varying(255) DEFAULT 'not-Paid'::character varying NOT NULL,
    subscription_model character varying(255),
    bank_invoice character varying(255),
    bank_slip character varying(255),
    status character varying(255) DEFAULT 'Not Activated'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    subscription_start_date timestamp(0) without time zone,
    subscription_end_date timestamp(0) without time zone,
    installed_on date,
    installed_by character varying(100),
    install_notes character varying(500),
    original_activated_at timestamp(0) without time zone,
    warranty_months smallint DEFAULT '0'::smallint NOT NULL
);


--
-- Name: activated_devices_activated_device_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.activated_devices_activated_device_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activated_devices_activated_device_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.activated_devices_activated_device_id_seq OWNED BY autotest.activated_devices.activated_device_id;


--
-- Name: audit_logs; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.audit_logs (
    id bigint NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    actor_id character varying(64),
    actor_name character varying(150),
    actor_role character varying(20),
    category character varying(20) NOT NULL,
    action character varying(60) NOT NULL,
    subject_type character varying(40),
    subject_id character varying(100),
    subject_label character varying(150),
    changes jsonb,
    meta jsonb,
    ip character varying(45),
    user_agent character varying(200)
);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.audit_logs_id_seq OWNED BY autotest.audit_logs.id;


--
-- Name: billing_parties; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.billing_parties (
    id bigint NOT NULL,
    kind character varying(10) NOT NULL,
    dealer_id bigint,
    legal_name character varying(200) NOT NULL,
    address text,
    tax_number character varying(60),
    phone character varying(40),
    email character varying(150),
    bank_details text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: billing_parties_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.billing_parties_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: billing_parties_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.billing_parties_id_seq OWNED BY autotest.billing_parties.id;


--
-- Name: billing_settings; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.billing_settings (
    key character varying(40) NOT NULL,
    value text,
    updated_by character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cache; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: commission_entries; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.commission_entries (
    id bigint NOT NULL,
    payment_id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    role character varying(12) NOT NULL,
    entry_type character varying(10) NOT NULL,
    amount numeric(12,2) NOT NULL,
    period_month character varying(7) NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    payout_id bigint,
    reason character varying(255),
    created_at timestamp(0) without time zone
);


--
-- Name: commission_entries_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.commission_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: commission_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.commission_entries_id_seq OWNED BY autotest.commission_entries.id;


--
-- Name: credit_note_lines; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.credit_note_lines (
    id bigint NOT NULL,
    credit_note_id bigint NOT NULL,
    invoice_line_id bigint NOT NULL,
    quantity integer NOT NULL,
    unit_price numeric(14,2) NOT NULL,
    line_total numeric(14,2) NOT NULL,
    created_at timestamp(0) without time zone,
    CONSTRAINT credit_note_lines_ok CHECK (((quantity > 0) AND (unit_price >= (0)::numeric) AND (line_total = ((quantity)::numeric * unit_price))))
);


--
-- Name: credit_note_lines_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.credit_note_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: credit_note_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.credit_note_lines_id_seq OWNED BY autotest.credit_note_lines.id;


--
-- Name: credit_notes; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.credit_notes (
    id bigint NOT NULL,
    number character varying(30) NOT NULL,
    invoice_id bigint NOT NULL,
    issuer_party_id bigint NOT NULL,
    buyer_dealer_id bigint NOT NULL,
    issue_date date NOT NULL,
    subtotal numeric(14,2) NOT NULL,
    tax_amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(14,2) NOT NULL,
    reason character varying(255) NOT NULL,
    issued_by character varying(64),
    issued_by_name character varying(100),
    snapshot json NOT NULL,
    snapshot_sha256 character(64) NOT NULL,
    previous_hash character(64),
    idempotency_key character varying(64),
    created_at timestamp(0) without time zone,
    CONSTRAINT credit_notes_amounts_ok CHECK (((subtotal > (0)::numeric) AND (tax_amount >= (0)::numeric) AND (total = (subtotal + tax_amount))))
);


--
-- Name: credit_notes_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.credit_notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: credit_notes_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.credit_notes_id_seq OWNED BY autotest.credit_notes.id;


--
-- Name: dashboard_metric_snapshots; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dashboard_metric_snapshots (
    id bigint NOT NULL,
    snapshot_date date NOT NULL,
    open_complaints_count integer DEFAULT 0 NOT NULL,
    devices_online integer DEFAULT 0 NOT NULL,
    devices_offline integer DEFAULT 0 NOT NULL,
    stock_units_available integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dashboard_metric_snapshots_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dashboard_metric_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_metric_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dashboard_metric_snapshots_id_seq OWNED BY autotest.dashboard_metric_snapshots.id;


--
-- Name: dealer_commission_ledger; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_commission_ledger (
    id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    shdevice_id bigint NOT NULL,
    device_type_id bigint,
    dealer_status_at_time character varying(255),
    rate_applied numeric(10,2) NOT NULL,
    amount numeric(10,2) NOT NULL,
    entry_type character varying(255) NOT NULL,
    reason character varying(255),
    reversed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    payout_id bigint,
    CONSTRAINT dealer_commission_ledger_entry_type_check CHECK (((entry_type)::text = ANY ((ARRAY['earned'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: dealer_commission_ledger_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_commission_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_commission_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_commission_ledger_id_seq OWNED BY autotest.dealer_commission_ledger.id;


--
-- Name: dealer_commission_payouts; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_commission_payouts (
    id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    period character varying(7) NOT NULL,
    period_end timestamp(0) without time zone NOT NULL,
    payable_after_days smallint NOT NULL,
    gross_amount numeric(12,2) NOT NULL,
    clawback_amount numeric(12,2) NOT NULL,
    net_amount numeric(12,2) NOT NULL,
    line_count integer NOT NULL,
    reference character varying(100) NOT NULL,
    paid_on date NOT NULL,
    paid_by_admin_id character varying(255) NOT NULL,
    paid_by_name character varying(255) NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dealer_commission_payouts_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_commission_payouts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_commission_payouts_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_commission_payouts_id_seq OWNED BY autotest.dealer_commission_payouts.id;


--
-- Name: dealer_commission_rates; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_commission_rates (
    id bigint NOT NULL,
    dealer_status character varying(255),
    device_type_id bigint,
    rate numeric(10,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dealer_commission_rates_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_commission_rates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_commission_rates_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_commission_rates_id_seq OWNED BY autotest.dealer_commission_rates.id;


--
-- Name: dealer_customer_ads; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_customer_ads (
    id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    nic_or_id character varying(255),
    contact character varying(255) NOT NULL,
    no_of_devices integer DEFAULT 1 NOT NULL,
    address text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    imei_numbers json,
    email character varying(255),
    deleted_at timestamp(0) without time zone
);


--
-- Name: dealer_customer_ads_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_customer_ads_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_customer_ads_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_customer_ads_id_seq OWNED BY autotest.dealer_customer_ads.id;


--
-- Name: dealer_transfer_items; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_transfer_items (
    id bigint NOT NULL,
    ledger_id bigint NOT NULL,
    device_id bigint NOT NULL,
    imei_number character varying(255),
    sim_number character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dealer_transfer_items_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_transfer_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_transfer_items_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_transfer_items_id_seq OWNED BY autotest.dealer_transfer_items.id;


--
-- Name: dealer_transfer_ledgers; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealer_transfer_ledgers (
    id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    device_category character varying(255) NOT NULL,
    quantity integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    from_dealer_id bigint,
    created_by_admin_id character varying(64)
);


--
-- Name: dealer_transfer_ledgers_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealer_transfer_ledgers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealer_transfer_ledgers_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealer_transfer_ledgers_id_seq OWNED BY autotest.dealer_transfer_ledgers.id;


--
-- Name: dealers; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.dealers (
    id bigint NOT NULL,
    full_name character varying(255) NOT NULL,
    address text,
    qualification character varying(255),
    dealer_status character varying(255) NOT NULL,
    region character varying(255) NOT NULL,
    country character varying(255) DEFAULT 'Sri Lanka'::character varying NOT NULL,
    pin_code character varying(255),
    contact_email character varying(255),
    tax_pan character varying(255),
    cst_no character varying(255),
    vat_tin character varying(255),
    gst_pan character varying(255),
    security_deposit numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    deposit_date date,
    network character varying(255),
    login_id character varying(255),
    password character varying(255),
    payment_modes json,
    profile_photo character varying(255),
    copy_of_ma character varying(255),
    passport_front character varying(255),
    passport_last character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    distributor_id bigint
);


--
-- Name: dealers_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.dealers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dealers_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.dealers_id_seq OWNED BY autotest.dealers.id;


--
-- Name: device_prices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.device_prices (
    id bigint NOT NULL,
    device_type_id bigint NOT NULL,
    landing_cost numeric(14,2) NOT NULL,
    company_margin numeric(14,2) NOT NULL,
    distributor_margin numeric(14,2) NOT NULL,
    retailer_margin numeric(14,2) NOT NULL,
    effective_from date NOT NULL,
    updated_by character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT device_prices_amounts_chk CHECK (((landing_cost > (0)::numeric) AND (company_margin >= (0)::numeric) AND (distributor_margin >= (0)::numeric) AND (retailer_margin >= (0)::numeric)))
);


--
-- Name: device_prices_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.device_prices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: device_prices_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.device_prices_id_seq OWNED BY autotest.device_prices.id;


--
-- Name: device_sync_state; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.device_sync_state (
    shdevice_id bigint NOT NULL,
    imei_number character varying(32) NOT NULL,
    pending boolean DEFAULT true NOT NULL,
    pending_since timestamp(6) without time zone,
    attempts integer DEFAULT 0 NOT NULL,
    next_attempt_at timestamp(0) without time zone,
    last_error character varying(300),
    last_synced_hash character varying(40),
    last_synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: device_types; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.device_types (
    id bigint NOT NULL,
    device_category character varying(255) NOT NULL,
    model character varying(255) NOT NULL,
    protocol character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    features json
);


--
-- Name: device_types_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.device_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: device_types_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.device_types_id_seq OWNED BY autotest.device_types.id;


--
-- Name: document_sequences; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.document_sequences (
    id bigint NOT NULL,
    issuer_party_id bigint NOT NULL,
    doc_type character varying(3) NOT NULL,
    year smallint NOT NULL,
    last_number integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT document_sequences_ok CHECK ((((doc_type)::text = ANY ((ARRAY['INV'::character varying, 'RCT'::character varying, 'CRN'::character varying])::text[])) AND (last_number >= 0)))
);


--
-- Name: document_sequences_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.document_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.document_sequences_id_seq OWNED BY autotest.document_sequences.id;


--
-- Name: expired_devices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.expired_devices (
    expired_device_id bigint NOT NULL,
    vehicle_id uuid NOT NULL,
    customer_id uuid,
    customer_name character varying(255),
    vehicle_number character varying(255),
    model character varying(255),
    has_gps_device boolean DEFAULT false NOT NULL,
    imei_number character varying(255) NOT NULL,
    sim_number character varying(255),
    device_category character varying(255),
    payment_status character varying(255) DEFAULT 'not-Paid'::character varying NOT NULL,
    subscription_model character varying(255),
    subscription_start_date timestamp(0) without time zone,
    subscription_end_date timestamp(0) without time zone,
    bank_invoice character varying(255),
    bank_slip character varying(255),
    status character varying(255) DEFAULT 'Expired'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expired_date timestamp(0) without time zone
);


--
-- Name: expired_devices_expired_device_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.expired_devices_expired_device_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: expired_devices_expired_device_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.expired_devices_expired_device_id_seq OWNED BY autotest.expired_devices.expired_device_id;


--
-- Name: failed_jobs; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.failed_jobs_id_seq OWNED BY autotest.failed_jobs.id;


--
-- Name: features; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.features (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: features_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.features_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: features_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.features_id_seq OWNED BY autotest.features.id;


--
-- Name: gateway_status_events; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.gateway_status_events (
    id bigint NOT NULL,
    state character varying(10) NOT NULL,
    reasons jsonb,
    devices_connected integer,
    public_path_ok boolean,
    recorded_at timestamp(0) without time zone NOT NULL,
    CONSTRAINT gateway_status_events_state_check CHECK (((state)::text = ANY ((ARRAY['ok'::character varying, 'degraded'::character varying, 'down'::character varying])::text[])))
);


--
-- Name: gateway_status_events_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.gateway_status_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gateway_status_events_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.gateway_status_events_id_seq OWNED BY autotest.gateway_status_events.id;


--
-- Name: invoice_lines; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.invoice_lines (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    transfer_ledger_id bigint NOT NULL,
    description character varying(200) NOT NULL,
    device_category character varying(100) NOT NULL,
    quantity integer NOT NULL,
    unit_price numeric(14,2) NOT NULL,
    line_total numeric(14,2) NOT NULL,
    created_at timestamp(0) without time zone,
    CONSTRAINT invoice_lines_ok CHECK (((quantity > 0) AND (unit_price >= (0)::numeric) AND (line_total = ((quantity)::numeric * unit_price))))
);


--
-- Name: invoice_lines_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.invoice_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoice_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.invoice_lines_id_seq OWNED BY autotest.invoice_lines.id;


--
-- Name: invoices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.invoices (
    id bigint NOT NULL,
    number character varying(30) NOT NULL,
    issuer_party_id bigint NOT NULL,
    buyer_party_id bigint NOT NULL,
    buyer_dealer_id bigint NOT NULL,
    issue_date date NOT NULL,
    due_date date NOT NULL,
    currency character varying(3) DEFAULT 'LKR'::character varying NOT NULL,
    subtotal numeric(14,2) NOT NULL,
    tax_rate numeric(5,2),
    tax_label character varying(30),
    tax_amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(14,2) NOT NULL,
    notes text,
    zero_reason character varying(255),
    issued_by character varying(64),
    issued_by_name character varying(100),
    snapshot json NOT NULL,
    snapshot_sha256 character(64) NOT NULL,
    previous_hash character(64),
    idempotency_key character varying(64),
    created_at timestamp(0) without time zone,
    CONSTRAINT invoices_amounts_ok CHECK (((subtotal >= (0)::numeric) AND (tax_amount >= (0)::numeric) AND (total = (subtotal + tax_amount))))
);


--
-- Name: invoices_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.invoices_id_seq OWNED BY autotest.invoices.id;


--
-- Name: job_batches; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.jobs_id_seq OWNED BY autotest.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.migrations_id_seq OWNED BY autotest.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- Name: package_payments; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.package_payments (
    id bigint NOT NULL,
    event_key character varying(64),
    activated_device_id bigint,
    imei_number character varying(32) NOT NULL,
    vehicle_id character varying(255),
    vehicle_number character varying(255),
    customer_id character varying(255),
    customer_name character varying(255),
    source character varying(20) NOT NULL,
    package_model character varying(20) NOT NULL,
    package_label character varying(40),
    months smallint,
    customer_price numeric(10,2),
    company_margin numeric(10,2),
    distributor_margin numeric(10,2),
    retailer_margin numeric(10,2),
    retailer_dealer_id bigint,
    distributor_dealer_id bigint,
    subscription_start timestamp(0) without time zone,
    subscription_end timestamp(0) without time zone,
    previous_expiry timestamp(0) without time zone,
    bank_invoice character varying(255),
    recorded_by character varying(64),
    recorded_by_name character varying(100),
    status character varying(10) DEFAULT 'valid'::character varying NOT NULL,
    reversed_at timestamp(0) without time zone,
    reversed_by character varying(64),
    reversal_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: package_payments_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.package_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: package_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.package_payments_id_seq OWNED BY autotest.package_payments.id;


--
-- Name: password_reset_requests; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.password_reset_requests (
    id bigint NOT NULL,
    admin_id character varying(64) NOT NULL,
    username character varying(100) NOT NULL,
    status character varying(12) DEFAULT 'PENDING'::character varying NOT NULL,
    requested_ip character varying(45),
    requested_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    resolved_by character varying(64),
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: password_reset_requests_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.password_reset_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: password_reset_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.password_reset_requests_id_seq OWNED BY autotest.password_reset_requests.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: payment_allocations; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.payment_allocations (
    id bigint NOT NULL,
    receipt_id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    amount numeric(14,2) NOT NULL,
    created_at timestamp(0) without time zone,
    CONSTRAINT payment_allocations_ok CHECK ((amount > (0)::numeric))
);


--
-- Name: payment_allocations_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.payment_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: payment_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.payment_allocations_id_seq OWNED BY autotest.payment_allocations.id;


--
-- Name: payment_receipts; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.payment_receipts (
    id bigint NOT NULL,
    number character varying(30) NOT NULL,
    issuer_party_id bigint NOT NULL,
    buyer_dealer_id bigint NOT NULL,
    amount numeric(14,2) NOT NULL,
    method character varying(20) NOT NULL,
    reference character varying(120),
    received_date date NOT NULL,
    target_invoice_id bigint,
    notes text,
    status character varying(10) NOT NULL,
    recorded_by character varying(64),
    recorded_by_name character varying(100),
    approved_by character varying(64),
    approved_by_name character varying(100),
    approved_at timestamp(0) without time zone,
    rejection_reason character varying(255),
    reversed_at timestamp(0) without time zone,
    reversed_by character varying(64),
    reversal_reason character varying(255),
    snapshot json NOT NULL,
    snapshot_sha256 character(64) NOT NULL,
    previous_hash character(64),
    idempotency_key character varying(64),
    created_at timestamp(0) without time zone,
    CONSTRAINT payment_receipts_ok CHECK (((amount > (0)::numeric) AND ((status)::text = ANY ((ARRAY['pending'::character varying, 'valid'::character varying, 'rejected'::character varying, 'reversed'::character varying])::text[])) AND ((method)::text = ANY ((ARRAY['bank_transfer'::character varying, 'cash'::character varying, 'cheque'::character varying, 'online'::character varying])::text[]))))
);


--
-- Name: payment_receipts_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.payment_receipts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: payment_receipts_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.payment_receipts_id_seq OWNED BY autotest.payment_receipts.id;


--
-- Name: permissions; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.permissions_id_seq OWNED BY autotest.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.personal_access_tokens_id_seq OWNED BY autotest.personal_access_tokens.id;


--
-- Name: products; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.products (
    id bigint NOT NULL,
    product_name character varying(255) NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    device_type_id bigint
);


--
-- Name: products_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.products_id_seq OWNED BY autotest.products.id;


--
-- Name: renewal_packages; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.renewal_packages (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    model character varying(20) NOT NULL,
    label character varying(40) NOT NULL,
    positioning character varying(60),
    months smallint NOT NULL,
    customer_price numeric(10,2),
    company_margin numeric(10,2),
    distributor_margin numeric(10,2),
    retailer_margin numeric(10,2),
    warranty_months smallint DEFAULT '0'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    effective_from date,
    updated_by character varying(64),
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: renewal_packages_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.renewal_packages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: renewal_packages_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.renewal_packages_id_seq OWNED BY autotest.renewal_packages.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.roles_id_seq OWNED BY autotest.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: setup_shalotrack_devices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.setup_shalotrack_devices (
    shdevice_id bigint NOT NULL,
    device_category character varying(255) NOT NULL,
    imei_number character varying(255) NOT NULL,
    sim_number character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'Not Activated'::character varying NOT NULL,
    cancel_reason character varying(255),
    canceled_date timestamp(0) without time zone,
    dealer_id bigint,
    allocated_at timestamp(0) without time zone,
    transfer_id bigint,
    iccid character varying(20),
    imsi character varying(15),
    registered_by character varying(255),
    intake_source character varying(16),
    device_type_id bigint,
    assigned_customer_id bigint
);


--
-- Name: setup_shalotrack_devices_shdevice_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.setup_shalotrack_devices_shdevice_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: setup_shalotrack_devices_shdevice_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.setup_shalotrack_devices_shdevice_id_seq OWNED BY autotest.setup_shalotrack_devices.shdevice_id;


--
-- Name: sims; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.sims (
    id bigint NOT NULL,
    sim_number character varying(10) NOT NULL,
    sim_type character varying(255) NOT NULL,
    imsi character varying(15),
    iccid character varying(20) NOT NULL,
    activation_required boolean DEFAULT false NOT NULL,
    sim_status character varying(255) DEFAULT 'Not Activated'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sims_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.sims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sims_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.sims_id_seq OWNED BY autotest.sims.id;


--
-- Name: stock_transfer_ledgers; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.stock_transfer_ledgers (
    id bigint NOT NULL,
    stock_id bigint NOT NULL,
    supplier_id bigint,
    supplier character varying(255),
    stock_in integer NOT NULL,
    description text,
    stocked_in_date date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    device_category_type character varying(255),
    deleted_at timestamp(0) without time zone
);


--
-- Name: stock_transfer_ledgers_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.stock_transfer_ledgers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_transfer_ledgers_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.stock_transfer_ledgers_id_seq OWNED BY autotest.stock_transfer_ledgers.id;


--
-- Name: stock_transfers; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.stock_transfers (
    id bigint NOT NULL,
    stock_id bigint NOT NULL,
    dealer_id bigint NOT NULL,
    quantity integer NOT NULL,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: stock_transfers_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.stock_transfers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_transfers_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.stock_transfers_id_seq OWNED BY autotest.stock_transfers.id;


--
-- Name: stocks; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.stocks (
    id bigint NOT NULL,
    company_available_stock integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    device_type_id bigint NOT NULL,
    device_category_type character varying(255)
);


--
-- Name: stocks_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.stocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stocks_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.stocks_id_seq OWNED BY autotest.stocks.id;


--
-- Name: supplier_deliveries; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_deliveries (
    id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    kind character varying(6) NOT NULL,
    device_type_id bigint,
    reference character varying(100),
    received_on date NOT NULL,
    note character varying(500),
    received_by character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    stocked_in_at timestamp(0) without time zone,
    stock_ledger_id bigint,
    supplier_invoice_id bigint,
    CONSTRAINT supplier_deliveries_kind_chk CHECK (((((kind)::text = 'DEVICE'::text) AND (device_type_id IS NOT NULL)) OR (((kind)::text = 'SIM'::text) AND (device_type_id IS NULL)))),
    CONSTRAINT supplier_deliveries_stocked_chk CHECK (((stocked_in_at IS NULL) = (stock_ledger_id IS NULL)))
);


--
-- Name: supplier_deliveries_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_deliveries_id_seq OWNED BY autotest.supplier_deliveries.id;


--
-- Name: supplier_delivery_units; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_delivery_units (
    id bigint NOT NULL,
    supplier_delivery_id bigint NOT NULL,
    kind character varying(6) NOT NULL,
    identifier character varying(20) NOT NULL,
    registered_at timestamp(0) without time zone,
    registered_ref character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sim_number character varying(10),
    CONSTRAINT supplier_delivery_units_id_chk CHECK (((((kind)::text = 'DEVICE'::text) AND ((identifier)::text ~ '^[0-9]{15}$'::text)) OR (((kind)::text = 'SIM'::text) AND ((identifier)::text ~ '^[0-9]{19,20}$'::text)))),
    CONSTRAINT supplier_delivery_units_sim_number_chk CHECK (((sim_number IS NULL) OR (((kind)::text = 'SIM'::text) AND ((sim_number)::text ~ '^[0-9]{10}$'::text))))
);


--
-- Name: supplier_delivery_units_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_delivery_units_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_delivery_units_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_delivery_units_id_seq OWNED BY autotest.supplier_delivery_units.id;


--
-- Name: supplier_invoice_items; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_invoice_items (
    id bigint NOT NULL,
    supplier_invoice_id bigint NOT NULL,
    product_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    order_qty integer NOT NULL,
    unit_price numeric(12,2) NOT NULL,
    discount numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    face_value numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    net_amount numeric(12,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: supplier_invoice_items_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_invoice_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_invoice_items_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_invoice_items_id_seq OWNED BY autotest.supplier_invoice_items.id;


--
-- Name: supplier_invoices; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_invoices (
    id bigint NOT NULL,
    invoice_number character varying(255) NOT NULL,
    supplier_id bigint NOT NULL,
    invoice_date date NOT NULL,
    grand_total numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(10) DEFAULT 'ORDERED'::character varying NOT NULL,
    cancelled_at timestamp(0) without time zone,
    cancel_reason character varying(200),
    updated_by character varying(64),
    CONSTRAINT supplier_invoices_status_chk CHECK (((((status)::text = 'ORDERED'::text) AND (cancelled_at IS NULL)) OR (((status)::text = 'CANCELLED'::text) AND (cancelled_at IS NOT NULL))))
);


--
-- Name: supplier_invoices_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_invoices_id_seq OWNED BY autotest.supplier_invoices.id;


--
-- Name: supplier_payments; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_payments (
    id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    supplier_invoice_id bigint NOT NULL,
    amount numeric(12,2) NOT NULL,
    paid_on date NOT NULL,
    method character varying(12) NOT NULL,
    reference character varying(100),
    note character varying(500),
    recorded_by character varying(64),
    voided_at timestamp(0) without time zone,
    void_reason character varying(200),
    voided_by character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT supplier_payments_amount_chk CHECK ((amount > (0)::numeric)),
    CONSTRAINT supplier_payments_method_chk CHECK (((method)::text = ANY ((ARRAY['CASH'::character varying, 'BANK'::character varying, 'CHEQUE'::character varying, 'OTHER'::character varying])::text[]))),
    CONSTRAINT supplier_payments_void_chk CHECK ((((voided_at IS NULL) AND (void_reason IS NULL)) OR ((voided_at IS NOT NULL) AND (void_reason IS NOT NULL))))
);


--
-- Name: supplier_payments_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_payments_id_seq OWNED BY autotest.supplier_payments.id;


--
-- Name: supplier_products; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.supplier_products (
    id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    product_id bigint NOT NULL,
    price numeric(10,2) NOT NULL,
    discount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    qty integer DEFAULT 0 NOT NULL
);


--
-- Name: supplier_products_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.supplier_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_products_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.supplier_products_id_seq OWNED BY autotest.supplier_products.id;


--
-- Name: suppliers; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.suppliers (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    address text,
    country character varying(255),
    state character varying(255),
    phone_number character varying(255),
    email character varying(255),
    website character varying(255),
    gstin_number character varying(255),
    status character varying(255) DEFAULT 'Active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.suppliers_id_seq OWNED BY autotest.suppliers.id;


--
-- Name: technician_jobs; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.technician_jobs (
    id bigint NOT NULL,
    job_type character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'scheduled'::character varying NOT NULL,
    vehicle_id character varying(255) NOT NULL,
    vehicle_number character varying(255),
    customer_id character varying(255),
    customer_name character varying(255),
    imei_number character varying(255),
    technician_id bigint NOT NULL,
    scheduled_for date NOT NULL,
    time_slot character varying(20),
    location_note character varying(255),
    instructions character varying(500),
    completed_on date,
    completion_notes character varying(500),
    cancel_reason character varying(255),
    created_by character varying(64),
    decided_by character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: technician_jobs_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.technician_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: technician_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.technician_jobs_id_seq OWNED BY autotest.technician_jobs.id;


--
-- Name: technicians; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.technicians (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    phone character varying(20),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: technicians_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.technicians_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: technicians_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.technicians_id_seq OWNED BY autotest.technicians.id;


--
-- Name: users; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: autotest; Owner: -
--

CREATE SEQUENCE autotest.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: autotest; Owner: -
--

ALTER SEQUENCE autotest.users_id_seq OWNED BY autotest.users.id;


--
-- Name: vehicle_ad; Type: TABLE; Schema: autotest; Owner: -
--

CREATE TABLE autotest.vehicle_ad (
    vehicle_id uuid NOT NULL,
    customer_id uuid,
    customer_name character varying(255),
    vehicle_number character varying(255),
    chassis_number character varying(255),
    engine_number character varying(255),
    make character varying(255),
    model character varying(255),
    year integer,
    color character varying(255),
    vehicle_type character varying(255),
    fuel_type character varying(255),
    has_gps_device boolean DEFAULT false NOT NULL,
    imei character varying(255),
    last_synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: activated_devices activated_device_id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.activated_devices ALTER COLUMN activated_device_id SET DEFAULT nextval('autotest.activated_devices_activated_device_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.audit_logs ALTER COLUMN id SET DEFAULT nextval('autotest.audit_logs_id_seq'::regclass);


--
-- Name: billing_parties id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.billing_parties ALTER COLUMN id SET DEFAULT nextval('autotest.billing_parties_id_seq'::regclass);


--
-- Name: commission_entries id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries ALTER COLUMN id SET DEFAULT nextval('autotest.commission_entries_id_seq'::regclass);


--
-- Name: credit_note_lines id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_note_lines ALTER COLUMN id SET DEFAULT nextval('autotest.credit_note_lines_id_seq'::regclass);


--
-- Name: credit_notes id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes ALTER COLUMN id SET DEFAULT nextval('autotest.credit_notes_id_seq'::regclass);


--
-- Name: dashboard_metric_snapshots id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dashboard_metric_snapshots ALTER COLUMN id SET DEFAULT nextval('autotest.dashboard_metric_snapshots_id_seq'::regclass);


--
-- Name: dealer_commission_ledger id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_commission_ledger_id_seq'::regclass);


--
-- Name: dealer_commission_payouts id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_payouts ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_commission_payouts_id_seq'::regclass);


--
-- Name: dealer_commission_rates id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_rates ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_commission_rates_id_seq'::regclass);


--
-- Name: dealer_customer_ads id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_customer_ads ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_customer_ads_id_seq'::regclass);


--
-- Name: dealer_transfer_items id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_items ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_transfer_items_id_seq'::regclass);


--
-- Name: dealer_transfer_ledgers id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_ledgers ALTER COLUMN id SET DEFAULT nextval('autotest.dealer_transfer_ledgers_id_seq'::regclass);


--
-- Name: dealers id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealers ALTER COLUMN id SET DEFAULT nextval('autotest.dealers_id_seq'::regclass);


--
-- Name: device_prices id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_prices ALTER COLUMN id SET DEFAULT nextval('autotest.device_prices_id_seq'::regclass);


--
-- Name: device_types id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_types ALTER COLUMN id SET DEFAULT nextval('autotest.device_types_id_seq'::regclass);


--
-- Name: document_sequences id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.document_sequences ALTER COLUMN id SET DEFAULT nextval('autotest.document_sequences_id_seq'::regclass);


--
-- Name: expired_devices expired_device_id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.expired_devices ALTER COLUMN expired_device_id SET DEFAULT nextval('autotest.expired_devices_expired_device_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.failed_jobs ALTER COLUMN id SET DEFAULT nextval('autotest.failed_jobs_id_seq'::regclass);


--
-- Name: features id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.features ALTER COLUMN id SET DEFAULT nextval('autotest.features_id_seq'::regclass);


--
-- Name: gateway_status_events id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.gateway_status_events ALTER COLUMN id SET DEFAULT nextval('autotest.gateway_status_events_id_seq'::regclass);


--
-- Name: invoice_lines id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoice_lines ALTER COLUMN id SET DEFAULT nextval('autotest.invoice_lines_id_seq'::regclass);


--
-- Name: invoices id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices ALTER COLUMN id SET DEFAULT nextval('autotest.invoices_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.jobs ALTER COLUMN id SET DEFAULT nextval('autotest.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.migrations ALTER COLUMN id SET DEFAULT nextval('autotest.migrations_id_seq'::regclass);


--
-- Name: package_payments id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.package_payments ALTER COLUMN id SET DEFAULT nextval('autotest.package_payments_id_seq'::regclass);


--
-- Name: password_reset_requests id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.password_reset_requests ALTER COLUMN id SET DEFAULT nextval('autotest.password_reset_requests_id_seq'::regclass);


--
-- Name: payment_allocations id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_allocations ALTER COLUMN id SET DEFAULT nextval('autotest.payment_allocations_id_seq'::regclass);


--
-- Name: payment_receipts id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts ALTER COLUMN id SET DEFAULT nextval('autotest.payment_receipts_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.permissions ALTER COLUMN id SET DEFAULT nextval('autotest.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('autotest.personal_access_tokens_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.products ALTER COLUMN id SET DEFAULT nextval('autotest.products_id_seq'::regclass);


--
-- Name: renewal_packages id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.renewal_packages ALTER COLUMN id SET DEFAULT nextval('autotest.renewal_packages_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.roles ALTER COLUMN id SET DEFAULT nextval('autotest.roles_id_seq'::regclass);


--
-- Name: setup_shalotrack_devices shdevice_id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices ALTER COLUMN shdevice_id SET DEFAULT nextval('autotest.setup_shalotrack_devices_shdevice_id_seq'::regclass);


--
-- Name: sims id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sims ALTER COLUMN id SET DEFAULT nextval('autotest.sims_id_seq'::regclass);


--
-- Name: stock_transfer_ledgers id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfer_ledgers ALTER COLUMN id SET DEFAULT nextval('autotest.stock_transfer_ledgers_id_seq'::regclass);


--
-- Name: stock_transfers id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfers ALTER COLUMN id SET DEFAULT nextval('autotest.stock_transfers_id_seq'::regclass);


--
-- Name: stocks id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stocks ALTER COLUMN id SET DEFAULT nextval('autotest.stocks_id_seq'::regclass);


--
-- Name: supplier_deliveries id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_deliveries_id_seq'::regclass);


--
-- Name: supplier_delivery_units id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_delivery_units ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_delivery_units_id_seq'::regclass);


--
-- Name: supplier_invoice_items id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoice_items ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_invoice_items_id_seq'::regclass);


--
-- Name: supplier_invoices id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoices ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_invoices_id_seq'::regclass);


--
-- Name: supplier_payments id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_payments ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_payments_id_seq'::regclass);


--
-- Name: supplier_products id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_products ALTER COLUMN id SET DEFAULT nextval('autotest.supplier_products_id_seq'::regclass);


--
-- Name: suppliers id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.suppliers ALTER COLUMN id SET DEFAULT nextval('autotest.suppliers_id_seq'::regclass);


--
-- Name: technician_jobs id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.technician_jobs ALTER COLUMN id SET DEFAULT nextval('autotest.technician_jobs_id_seq'::regclass);


--
-- Name: technicians id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.technicians ALTER COLUMN id SET DEFAULT nextval('autotest.technicians_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.users ALTER COLUMN id SET DEFAULT nextval('autotest.users_id_seq'::regclass);


--
-- Name: Admins Admins_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest."Admins"
    ADD CONSTRAINT "Admins_pkey" PRIMARY KEY (admin_id);


--
-- Name: Admins Admins_username_key; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest."Admins"
    ADD CONSTRAINT "Admins_username_key" UNIQUE (username);


--
-- Name: Customer-ad Customer-ad_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest."Customer-ad"
    ADD CONSTRAINT "Customer-ad_pkey" PRIMARY KEY (customer_id);


--
-- Name: activated_devices activated_devices_bank_invoice_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.activated_devices
    ADD CONSTRAINT activated_devices_bank_invoice_unique UNIQUE (bank_invoice);


--
-- Name: activated_devices activated_devices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.activated_devices
    ADD CONSTRAINT activated_devices_pkey PRIMARY KEY (activated_device_id);


--
-- Name: activated_devices activated_devices_vehicle_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.activated_devices
    ADD CONSTRAINT activated_devices_vehicle_id_unique UNIQUE (vehicle_id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: billing_parties billing_parties_dealer_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.billing_parties
    ADD CONSTRAINT billing_parties_dealer_id_unique UNIQUE (dealer_id);


--
-- Name: billing_parties billing_parties_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.billing_parties
    ADD CONSTRAINT billing_parties_pkey PRIMARY KEY (id);


--
-- Name: billing_settings billing_settings_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.billing_settings
    ADD CONSTRAINT billing_settings_pkey PRIMARY KEY (key);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: commission_entries commission_entries_once; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries
    ADD CONSTRAINT commission_entries_once UNIQUE (payment_id, dealer_id, role, entry_type);


--
-- Name: commission_entries commission_entries_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries
    ADD CONSTRAINT commission_entries_pkey PRIMARY KEY (id);


--
-- Name: credit_note_lines credit_note_lines_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_note_lines
    ADD CONSTRAINT credit_note_lines_pkey PRIMARY KEY (id);


--
-- Name: credit_notes credit_notes_idempotency_key_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: credit_notes credit_notes_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_number_unique UNIQUE (number);


--
-- Name: credit_notes credit_notes_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_pkey PRIMARY KEY (id);


--
-- Name: dashboard_metric_snapshots dashboard_metric_snapshots_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dashboard_metric_snapshots
    ADD CONSTRAINT dashboard_metric_snapshots_pkey PRIMARY KEY (id);


--
-- Name: dashboard_metric_snapshots dashboard_metric_snapshots_snapshot_date_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dashboard_metric_snapshots
    ADD CONSTRAINT dashboard_metric_snapshots_snapshot_date_unique UNIQUE (snapshot_date);


--
-- Name: dealer_commission_ledger dealer_commission_ledger_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger
    ADD CONSTRAINT dealer_commission_ledger_pkey PRIMARY KEY (id);


--
-- Name: dealer_commission_payouts dealer_commission_payouts_dealer_id_period_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_payouts
    ADD CONSTRAINT dealer_commission_payouts_dealer_id_period_unique UNIQUE (dealer_id, period);


--
-- Name: dealer_commission_payouts dealer_commission_payouts_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_payouts
    ADD CONSTRAINT dealer_commission_payouts_pkey PRIMARY KEY (id);


--
-- Name: dealer_commission_rates dealer_commission_rates_combo_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_rates
    ADD CONSTRAINT dealer_commission_rates_combo_unique UNIQUE (dealer_status, device_type_id);


--
-- Name: dealer_commission_rates dealer_commission_rates_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_rates
    ADD CONSTRAINT dealer_commission_rates_pkey PRIMARY KEY (id);


--
-- Name: dealer_customer_ads dealer_customer_ads_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_customer_ads
    ADD CONSTRAINT dealer_customer_ads_pkey PRIMARY KEY (id);


--
-- Name: dealer_transfer_items dealer_transfer_items_ledger_id_device_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_items
    ADD CONSTRAINT dealer_transfer_items_ledger_id_device_id_unique UNIQUE (ledger_id, device_id);


--
-- Name: dealer_transfer_items dealer_transfer_items_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_items
    ADD CONSTRAINT dealer_transfer_items_pkey PRIMARY KEY (id);


--
-- Name: dealer_transfer_ledgers dealer_transfer_ledgers_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_ledgers
    ADD CONSTRAINT dealer_transfer_ledgers_pkey PRIMARY KEY (id);


--
-- Name: dealers dealers_contact_email_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealers
    ADD CONSTRAINT dealers_contact_email_unique UNIQUE (contact_email);


--
-- Name: dealers dealers_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealers
    ADD CONSTRAINT dealers_pkey PRIMARY KEY (id);


--
-- Name: device_prices device_prices_device_type_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_prices
    ADD CONSTRAINT device_prices_device_type_id_unique UNIQUE (device_type_id);


--
-- Name: device_prices device_prices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_prices
    ADD CONSTRAINT device_prices_pkey PRIMARY KEY (id);


--
-- Name: device_sync_state device_sync_state_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_sync_state
    ADD CONSTRAINT device_sync_state_pkey PRIMARY KEY (shdevice_id);


--
-- Name: device_types device_types_category_model_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_types
    ADD CONSTRAINT device_types_category_model_unique UNIQUE (device_category, model);


--
-- Name: device_types device_types_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_types
    ADD CONSTRAINT device_types_pkey PRIMARY KEY (id);


--
-- Name: document_sequences document_sequences_issuer_party_id_doc_type_year_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.document_sequences
    ADD CONSTRAINT document_sequences_issuer_party_id_doc_type_year_unique UNIQUE (issuer_party_id, doc_type, year);


--
-- Name: document_sequences document_sequences_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.document_sequences
    ADD CONSTRAINT document_sequences_pkey PRIMARY KEY (id);


--
-- Name: expired_devices expired_devices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.expired_devices
    ADD CONSTRAINT expired_devices_pkey PRIMARY KEY (expired_device_id);


--
-- Name: expired_devices expired_devices_vehicle_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.expired_devices
    ADD CONSTRAINT expired_devices_vehicle_id_unique UNIQUE (vehicle_id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: features features_name_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.features
    ADD CONSTRAINT features_name_unique UNIQUE (name);


--
-- Name: features features_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.features
    ADD CONSTRAINT features_pkey PRIMARY KEY (id);


--
-- Name: gateway_status_events gateway_status_events_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.gateway_status_events
    ADD CONSTRAINT gateway_status_events_pkey PRIMARY KEY (id);


--
-- Name: invoice_lines invoice_lines_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoice_lines
    ADD CONSTRAINT invoice_lines_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_idempotency_key_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: invoices invoices_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_number_unique UNIQUE (number);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: package_payments package_payments_event_key_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.package_payments
    ADD CONSTRAINT package_payments_event_key_unique UNIQUE (event_key);


--
-- Name: package_payments package_payments_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.package_payments
    ADD CONSTRAINT package_payments_pkey PRIMARY KEY (id);


--
-- Name: password_reset_requests password_reset_requests_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.password_reset_requests
    ADD CONSTRAINT password_reset_requests_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: payment_allocations payment_allocations_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_allocations
    ADD CONSTRAINT payment_allocations_pkey PRIMARY KEY (id);


--
-- Name: payment_allocations payment_allocations_receipt_id_invoice_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_allocations
    ADD CONSTRAINT payment_allocations_receipt_id_invoice_id_unique UNIQUE (receipt_id, invoice_id);


--
-- Name: payment_receipts payment_receipts_idempotency_key_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: payment_receipts payment_receipts_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_number_unique UNIQUE (number);


--
-- Name: payment_receipts payment_receipts_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: renewal_packages renewal_packages_code_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.renewal_packages
    ADD CONSTRAINT renewal_packages_code_unique UNIQUE (code);


--
-- Name: renewal_packages renewal_packages_model_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.renewal_packages
    ADD CONSTRAINT renewal_packages_model_unique UNIQUE (model);


--
-- Name: renewal_packages renewal_packages_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.renewal_packages
    ADD CONSTRAINT renewal_packages_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_iccid_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_iccid_unique UNIQUE (iccid);


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_imei_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_imei_number_unique UNIQUE (imei_number);


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_pkey PRIMARY KEY (shdevice_id);


--
-- Name: sims sims_iccid_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sims
    ADD CONSTRAINT sims_iccid_unique UNIQUE (iccid);


--
-- Name: sims sims_imsi_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sims
    ADD CONSTRAINT sims_imsi_unique UNIQUE (imsi);


--
-- Name: sims sims_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sims
    ADD CONSTRAINT sims_pkey PRIMARY KEY (id);


--
-- Name: sims sims_sim_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.sims
    ADD CONSTRAINT sims_sim_number_unique UNIQUE (sim_number);


--
-- Name: stock_transfer_ledgers stock_transfer_ledgers_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfer_ledgers
    ADD CONSTRAINT stock_transfer_ledgers_pkey PRIMARY KEY (id);


--
-- Name: stock_transfers stock_transfers_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfers
    ADD CONSTRAINT stock_transfers_pkey PRIMARY KEY (id);


--
-- Name: stocks stocks_device_type_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stocks
    ADD CONSTRAINT stocks_device_type_id_unique UNIQUE (device_type_id);


--
-- Name: stocks stocks_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stocks
    ADD CONSTRAINT stocks_pkey PRIMARY KEY (id);


--
-- Name: supplier_deliveries supplier_deliveries_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries
    ADD CONSTRAINT supplier_deliveries_pkey PRIMARY KEY (id);


--
-- Name: supplier_delivery_units supplier_delivery_units_identifier_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_delivery_units
    ADD CONSTRAINT supplier_delivery_units_identifier_unique UNIQUE (identifier);


--
-- Name: supplier_delivery_units supplier_delivery_units_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_delivery_units
    ADD CONSTRAINT supplier_delivery_units_pkey PRIMARY KEY (id);


--
-- Name: supplier_delivery_units supplier_delivery_units_sim_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_delivery_units
    ADD CONSTRAINT supplier_delivery_units_sim_number_unique UNIQUE (sim_number);


--
-- Name: supplier_invoice_items supplier_invoice_items_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoice_items
    ADD CONSTRAINT supplier_invoice_items_pkey PRIMARY KEY (id);


--
-- Name: supplier_invoices supplier_invoices_invoice_number_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoices
    ADD CONSTRAINT supplier_invoices_invoice_number_unique UNIQUE (invoice_number);


--
-- Name: supplier_invoices supplier_invoices_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoices
    ADD CONSTRAINT supplier_invoices_pkey PRIMARY KEY (id);


--
-- Name: supplier_payments supplier_payments_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_payments
    ADD CONSTRAINT supplier_payments_pkey PRIMARY KEY (id);


--
-- Name: supplier_products supplier_products_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_products
    ADD CONSTRAINT supplier_products_pkey PRIMARY KEY (id);


--
-- Name: supplier_products supplier_products_supplier_id_product_id_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_products
    ADD CONSTRAINT supplier_products_supplier_id_product_id_unique UNIQUE (supplier_id, product_id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: technician_jobs technician_jobs_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.technician_jobs
    ADD CONSTRAINT technician_jobs_pkey PRIMARY KEY (id);


--
-- Name: technicians technicians_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.technicians
    ADD CONSTRAINT technicians_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vehicle_ad vehicle_ad_pkey; Type: CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.vehicle_ad
    ADD CONSTRAINT vehicle_ad_pkey PRIMARY KEY (vehicle_id);


--
-- Name: audit_logs_action_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX audit_logs_action_index ON autotest.audit_logs USING btree (action);


--
-- Name: audit_logs_actor_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX audit_logs_actor_id_index ON autotest.audit_logs USING btree (actor_id);


--
-- Name: audit_logs_category_occurred_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX audit_logs_category_occurred_at_index ON autotest.audit_logs USING btree (category, occurred_at);


--
-- Name: audit_logs_occurred_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX audit_logs_occurred_at_index ON autotest.audit_logs USING btree (occurred_at);


--
-- Name: audit_logs_subject_type_subject_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX audit_logs_subject_type_subject_id_index ON autotest.audit_logs USING btree (subject_type, subject_id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX cache_expiration_index ON autotest.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON autotest.cache_locks USING btree (expiration);


--
-- Name: commission_entries_dealer_id_payout_id_occurred_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX commission_entries_dealer_id_payout_id_occurred_at_index ON autotest.commission_entries USING btree (dealer_id, payout_id, occurred_at);


--
-- Name: commission_entries_dealer_id_period_month_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX commission_entries_dealer_id_period_month_index ON autotest.commission_entries USING btree (dealer_id, period_month);


--
-- Name: credit_note_lines_credit_note_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX credit_note_lines_credit_note_id_index ON autotest.credit_note_lines USING btree (credit_note_id);


--
-- Name: credit_note_lines_invoice_line_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX credit_note_lines_invoice_line_id_index ON autotest.credit_note_lines USING btree (invoice_line_id);


--
-- Name: credit_notes_buyer_dealer_id_issue_date_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX credit_notes_buyer_dealer_id_issue_date_index ON autotest.credit_notes USING btree (buyer_dealer_id, issue_date);


--
-- Name: credit_notes_invoice_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX credit_notes_invoice_id_index ON autotest.credit_notes USING btree (invoice_id);


--
-- Name: dealer_commission_ledger_dealer_id_entry_type_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX dealer_commission_ledger_dealer_id_entry_type_index ON autotest.dealer_commission_ledger USING btree (dealer_id, entry_type);


--
-- Name: dealer_commission_ledger_payout_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX dealer_commission_ledger_payout_id_index ON autotest.dealer_commission_ledger USING btree (payout_id);


--
-- Name: dealer_commission_ledger_shdevice_id_reversed_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX dealer_commission_ledger_shdevice_id_reversed_at_index ON autotest.dealer_commission_ledger USING btree (shdevice_id, reversed_at);


--
-- Name: dealer_transfer_items_device_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX dealer_transfer_items_device_id_index ON autotest.dealer_transfer_items USING btree (device_id);


--
-- Name: dealer_transfer_ledgers_from_dealer_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX dealer_transfer_ledgers_from_dealer_id_index ON autotest.dealer_transfer_ledgers USING btree (from_dealer_id);


--
-- Name: device_sync_state_imei_number_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX device_sync_state_imei_number_index ON autotest.device_sync_state USING btree (imei_number);


--
-- Name: device_sync_state_pending_next_attempt_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX device_sync_state_pending_next_attempt_at_index ON autotest.device_sync_state USING btree (pending, next_attempt_at);


--
-- Name: gateway_status_events_recorded_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX gateway_status_events_recorded_at_index ON autotest.gateway_status_events USING btree (recorded_at);


--
-- Name: invoice_lines_invoice_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX invoice_lines_invoice_id_index ON autotest.invoice_lines USING btree (invoice_id);


--
-- Name: invoice_lines_transfer_ledger_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX invoice_lines_transfer_ledger_id_index ON autotest.invoice_lines USING btree (transfer_ledger_id);


--
-- Name: invoices_buyer_dealer_id_issue_date_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX invoices_buyer_dealer_id_issue_date_index ON autotest.invoices USING btree (buyer_dealer_id, issue_date);


--
-- Name: invoices_due_date_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX invoices_due_date_index ON autotest.invoices USING btree (due_date);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX jobs_queue_index ON autotest.jobs USING btree (queue);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON autotest.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON autotest.model_has_roles USING btree (model_id, model_type);


--
-- Name: package_payments_imei_number_status_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX package_payments_imei_number_status_index ON autotest.package_payments USING btree (imei_number, status);


--
-- Name: package_payments_retailer_dealer_id_created_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX package_payments_retailer_dealer_id_created_at_index ON autotest.package_payments USING btree (retailer_dealer_id, created_at);


--
-- Name: password_reset_requests_admin_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX password_reset_requests_admin_id_index ON autotest.password_reset_requests USING btree (admin_id);


--
-- Name: password_reset_requests_status_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX password_reset_requests_status_index ON autotest.password_reset_requests USING btree (status);


--
-- Name: payment_allocations_invoice_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX payment_allocations_invoice_id_index ON autotest.payment_allocations USING btree (invoice_id);


--
-- Name: payment_receipts_buyer_dealer_id_status_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX payment_receipts_buyer_dealer_id_status_index ON autotest.payment_receipts USING btree (buyer_dealer_id, status);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON autotest.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON autotest.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX sessions_last_activity_index ON autotest.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX sessions_user_id_index ON autotest.sessions USING btree (user_id);


--
-- Name: supplier_deliveries_supplier_id_kind_stocked_in_at_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_deliveries_supplier_id_kind_stocked_in_at_index ON autotest.supplier_deliveries USING btree (supplier_id, kind, stocked_in_at);


--
-- Name: supplier_deliveries_supplier_id_received_on_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_deliveries_supplier_id_received_on_index ON autotest.supplier_deliveries USING btree (supplier_id, received_on);


--
-- Name: supplier_deliveries_supplier_invoice_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_deliveries_supplier_invoice_id_index ON autotest.supplier_deliveries USING btree (supplier_invoice_id);


--
-- Name: supplier_delivery_units_supplier_delivery_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_delivery_units_supplier_delivery_id_index ON autotest.supplier_delivery_units USING btree (supplier_delivery_id);


--
-- Name: supplier_payments_supplier_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_payments_supplier_id_index ON autotest.supplier_payments USING btree (supplier_id);


--
-- Name: supplier_payments_supplier_invoice_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX supplier_payments_supplier_invoice_id_index ON autotest.supplier_payments USING btree (supplier_invoice_id);


--
-- Name: technician_jobs_one_open_per_vehicle_type; Type: INDEX; Schema: autotest; Owner: -
--

CREATE UNIQUE INDEX technician_jobs_one_open_per_vehicle_type ON autotest.technician_jobs USING btree (vehicle_id, job_type) WHERE ((status)::text = 'scheduled'::text);


--
-- Name: technician_jobs_status_scheduled_for_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX technician_jobs_status_scheduled_for_index ON autotest.technician_jobs USING btree (status, scheduled_for);


--
-- Name: technician_jobs_technician_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX technician_jobs_technician_id_index ON autotest.technician_jobs USING btree (technician_id);


--
-- Name: technician_jobs_vehicle_id_index; Type: INDEX; Schema: autotest; Owner: -
--

CREATE INDEX technician_jobs_vehicle_id_index ON autotest.technician_jobs USING btree (vehicle_id);


--
-- Name: credit_note_lines credit_note_lines_immutable; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER credit_note_lines_immutable BEFORE DELETE OR UPDATE ON autotest.credit_note_lines FOR EACH ROW EXECUTE FUNCTION autotest.billing_forbid_change();


--
-- Name: credit_notes credit_notes_immutable; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER credit_notes_immutable BEFORE DELETE OR UPDATE ON autotest.credit_notes FOR EACH ROW EXECUTE FUNCTION autotest.billing_forbid_change();


--
-- Name: invoice_lines invoice_lines_immutable; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER invoice_lines_immutable BEFORE DELETE OR UPDATE ON autotest.invoice_lines FOR EACH ROW EXECUTE FUNCTION autotest.billing_forbid_change();


--
-- Name: invoices invoices_immutable; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER invoices_immutable BEFORE DELETE OR UPDATE ON autotest.invoices FOR EACH ROW EXECUTE FUNCTION autotest.billing_forbid_change();


--
-- Name: payment_allocations payment_allocations_immutable; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER payment_allocations_immutable BEFORE DELETE OR UPDATE ON autotest.payment_allocations FOR EACH ROW EXECUTE FUNCTION autotest.billing_forbid_change();


--
-- Name: payment_receipts payment_receipts_guard; Type: TRIGGER; Schema: autotest; Owner: -
--

CREATE TRIGGER payment_receipts_guard BEFORE DELETE OR UPDATE ON autotest.payment_receipts FOR EACH ROW EXECUTE FUNCTION autotest.billing_guard_receipt();


--
-- Name: Admins admins_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest."Admins"
    ADD CONSTRAINT admins_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE SET NULL;


--
-- Name: Admins admins_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest."Admins"
    ADD CONSTRAINT admins_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE SET NULL;


--
-- Name: billing_parties billing_parties_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.billing_parties
    ADD CONSTRAINT billing_parties_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: commission_entries commission_entries_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries
    ADD CONSTRAINT commission_entries_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: commission_entries commission_entries_payment_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries
    ADD CONSTRAINT commission_entries_payment_id_foreign FOREIGN KEY (payment_id) REFERENCES autotest.package_payments(id) ON DELETE RESTRICT;


--
-- Name: commission_entries commission_entries_payout_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.commission_entries
    ADD CONSTRAINT commission_entries_payout_id_foreign FOREIGN KEY (payout_id) REFERENCES autotest.dealer_commission_payouts(id) ON DELETE RESTRICT;


--
-- Name: credit_note_lines credit_note_lines_credit_note_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_note_lines
    ADD CONSTRAINT credit_note_lines_credit_note_id_foreign FOREIGN KEY (credit_note_id) REFERENCES autotest.credit_notes(id) ON DELETE RESTRICT;


--
-- Name: credit_note_lines credit_note_lines_invoice_line_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_note_lines
    ADD CONSTRAINT credit_note_lines_invoice_line_id_foreign FOREIGN KEY (invoice_line_id) REFERENCES autotest.invoice_lines(id) ON DELETE RESTRICT;


--
-- Name: credit_notes credit_notes_buyer_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_buyer_dealer_id_foreign FOREIGN KEY (buyer_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: credit_notes credit_notes_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES autotest.invoices(id) ON DELETE RESTRICT;


--
-- Name: credit_notes credit_notes_issuer_party_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.credit_notes
    ADD CONSTRAINT credit_notes_issuer_party_id_foreign FOREIGN KEY (issuer_party_id) REFERENCES autotest.billing_parties(id) ON DELETE RESTRICT;


--
-- Name: dealer_commission_ledger dealer_commission_ledger_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger
    ADD CONSTRAINT dealer_commission_ledger_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: dealer_commission_ledger dealer_commission_ledger_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger
    ADD CONSTRAINT dealer_commission_ledger_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE SET NULL;


--
-- Name: dealer_commission_ledger dealer_commission_ledger_payout_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger
    ADD CONSTRAINT dealer_commission_ledger_payout_id_foreign FOREIGN KEY (payout_id) REFERENCES autotest.dealer_commission_payouts(id) ON DELETE RESTRICT;


--
-- Name: dealer_commission_ledger dealer_commission_ledger_shdevice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_ledger
    ADD CONSTRAINT dealer_commission_ledger_shdevice_id_foreign FOREIGN KEY (shdevice_id) REFERENCES autotest.setup_shalotrack_devices(shdevice_id) ON DELETE RESTRICT;


--
-- Name: dealer_commission_payouts dealer_commission_payouts_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_payouts
    ADD CONSTRAINT dealer_commission_payouts_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: dealer_commission_rates dealer_commission_rates_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_commission_rates
    ADD CONSTRAINT dealer_commission_rates_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE SET NULL;


--
-- Name: dealer_customer_ads dealer_customer_ads_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_customer_ads
    ADD CONSTRAINT dealer_customer_ads_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: dealer_transfer_items dealer_transfer_items_device_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_items
    ADD CONSTRAINT dealer_transfer_items_device_id_foreign FOREIGN KEY (device_id) REFERENCES autotest.setup_shalotrack_devices(shdevice_id) ON DELETE RESTRICT;


--
-- Name: dealer_transfer_items dealer_transfer_items_ledger_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_items
    ADD CONSTRAINT dealer_transfer_items_ledger_id_foreign FOREIGN KEY (ledger_id) REFERENCES autotest.dealer_transfer_ledgers(id) ON DELETE RESTRICT;


--
-- Name: dealer_transfer_ledgers dealer_transfer_ledgers_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_ledgers
    ADD CONSTRAINT dealer_transfer_ledgers_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: dealer_transfer_ledgers dealer_transfer_ledgers_from_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealer_transfer_ledgers
    ADD CONSTRAINT dealer_transfer_ledgers_from_dealer_id_foreign FOREIGN KEY (from_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: dealers dealers_distributor_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.dealers
    ADD CONSTRAINT dealers_distributor_id_foreign FOREIGN KEY (distributor_id) REFERENCES autotest.dealers(id) ON DELETE SET NULL;


--
-- Name: device_prices device_prices_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.device_prices
    ADD CONSTRAINT device_prices_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE RESTRICT;


--
-- Name: document_sequences document_sequences_issuer_party_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.document_sequences
    ADD CONSTRAINT document_sequences_issuer_party_id_foreign FOREIGN KEY (issuer_party_id) REFERENCES autotest.billing_parties(id) ON DELETE RESTRICT;


--
-- Name: invoice_lines invoice_lines_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoice_lines
    ADD CONSTRAINT invoice_lines_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES autotest.invoices(id) ON DELETE RESTRICT;


--
-- Name: invoice_lines invoice_lines_transfer_ledger_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoice_lines
    ADD CONSTRAINT invoice_lines_transfer_ledger_id_foreign FOREIGN KEY (transfer_ledger_id) REFERENCES autotest.dealer_transfer_ledgers(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_buyer_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_buyer_dealer_id_foreign FOREIGN KEY (buyer_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_buyer_party_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_buyer_party_id_foreign FOREIGN KEY (buyer_party_id) REFERENCES autotest.billing_parties(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_issuer_party_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.invoices
    ADD CONSTRAINT invoices_issuer_party_id_foreign FOREIGN KEY (issuer_party_id) REFERENCES autotest.billing_parties(id) ON DELETE RESTRICT;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES autotest.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES autotest.roles(id) ON DELETE CASCADE;


--
-- Name: package_payments package_payments_distributor_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.package_payments
    ADD CONSTRAINT package_payments_distributor_dealer_id_foreign FOREIGN KEY (distributor_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: package_payments package_payments_retailer_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.package_payments
    ADD CONSTRAINT package_payments_retailer_dealer_id_foreign FOREIGN KEY (retailer_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: payment_allocations payment_allocations_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_allocations
    ADD CONSTRAINT payment_allocations_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES autotest.invoices(id) ON DELETE RESTRICT;


--
-- Name: payment_allocations payment_allocations_receipt_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_allocations
    ADD CONSTRAINT payment_allocations_receipt_id_foreign FOREIGN KEY (receipt_id) REFERENCES autotest.payment_receipts(id) ON DELETE RESTRICT;


--
-- Name: payment_receipts payment_receipts_buyer_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_buyer_dealer_id_foreign FOREIGN KEY (buyer_dealer_id) REFERENCES autotest.dealers(id) ON DELETE RESTRICT;


--
-- Name: payment_receipts payment_receipts_issuer_party_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_issuer_party_id_foreign FOREIGN KEY (issuer_party_id) REFERENCES autotest.billing_parties(id) ON DELETE RESTRICT;


--
-- Name: payment_receipts payment_receipts_target_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.payment_receipts
    ADD CONSTRAINT payment_receipts_target_invoice_id_foreign FOREIGN KEY (target_invoice_id) REFERENCES autotest.invoices(id) ON DELETE RESTRICT;


--
-- Name: products products_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.products
    ADD CONSTRAINT products_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE SET NULL;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES autotest.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES autotest.roles(id) ON DELETE CASCADE;


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_assigned_customer_id_fkey; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_assigned_customer_id_fkey FOREIGN KEY (assigned_customer_id) REFERENCES autotest.dealer_customer_ads(id);


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE SET NULL;


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_device_type_id_fkey; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_device_type_id_fkey FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id);


--
-- Name: setup_shalotrack_devices setup_shalotrack_devices_transfer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.setup_shalotrack_devices
    ADD CONSTRAINT setup_shalotrack_devices_transfer_id_foreign FOREIGN KEY (transfer_id) REFERENCES autotest.dealer_transfer_ledgers(id) ON DELETE SET NULL;


--
-- Name: stock_transfer_ledgers stock_transfer_ledgers_stock_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfer_ledgers
    ADD CONSTRAINT stock_transfer_ledgers_stock_id_foreign FOREIGN KEY (stock_id) REFERENCES autotest.stocks(id) ON DELETE RESTRICT;


--
-- Name: stock_transfer_ledgers stock_transfer_ledgers_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfer_ledgers
    ADD CONSTRAINT stock_transfer_ledgers_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE SET NULL;


--
-- Name: stock_transfers stock_transfers_dealer_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfers
    ADD CONSTRAINT stock_transfers_dealer_id_foreign FOREIGN KEY (dealer_id) REFERENCES autotest.dealers(id) ON DELETE CASCADE;


--
-- Name: stock_transfers stock_transfers_stock_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stock_transfers
    ADD CONSTRAINT stock_transfers_stock_id_foreign FOREIGN KEY (stock_id) REFERENCES autotest.stocks(id) ON DELETE CASCADE;


--
-- Name: stocks stocks_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.stocks
    ADD CONSTRAINT stocks_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE CASCADE;


--
-- Name: supplier_deliveries supplier_deliveries_device_type_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries
    ADD CONSTRAINT supplier_deliveries_device_type_id_foreign FOREIGN KEY (device_type_id) REFERENCES autotest.device_types(id) ON DELETE RESTRICT;


--
-- Name: supplier_deliveries supplier_deliveries_stock_ledger_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries
    ADD CONSTRAINT supplier_deliveries_stock_ledger_id_foreign FOREIGN KEY (stock_ledger_id) REFERENCES autotest.stock_transfer_ledgers(id) ON DELETE RESTRICT;


--
-- Name: supplier_deliveries supplier_deliveries_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries
    ADD CONSTRAINT supplier_deliveries_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE RESTRICT;


--
-- Name: supplier_deliveries supplier_deliveries_supplier_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_deliveries
    ADD CONSTRAINT supplier_deliveries_supplier_invoice_id_foreign FOREIGN KEY (supplier_invoice_id) REFERENCES autotest.supplier_invoices(id) ON DELETE RESTRICT;


--
-- Name: supplier_delivery_units supplier_delivery_units_supplier_delivery_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_delivery_units
    ADD CONSTRAINT supplier_delivery_units_supplier_delivery_id_foreign FOREIGN KEY (supplier_delivery_id) REFERENCES autotest.supplier_deliveries(id) ON DELETE RESTRICT;


--
-- Name: supplier_invoice_items supplier_invoice_items_product_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoice_items
    ADD CONSTRAINT supplier_invoice_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES autotest.products(id) ON DELETE RESTRICT;


--
-- Name: supplier_invoice_items supplier_invoice_items_supplier_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoice_items
    ADD CONSTRAINT supplier_invoice_items_supplier_invoice_id_foreign FOREIGN KEY (supplier_invoice_id) REFERENCES autotest.supplier_invoices(id) ON DELETE CASCADE;


--
-- Name: supplier_invoices supplier_invoices_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_invoices
    ADD CONSTRAINT supplier_invoices_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE RESTRICT;


--
-- Name: supplier_payments supplier_payments_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_payments
    ADD CONSTRAINT supplier_payments_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE RESTRICT;


--
-- Name: supplier_payments supplier_payments_supplier_invoice_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_payments
    ADD CONSTRAINT supplier_payments_supplier_invoice_id_foreign FOREIGN KEY (supplier_invoice_id) REFERENCES autotest.supplier_invoices(id) ON DELETE RESTRICT;


--
-- Name: supplier_products supplier_products_product_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_products
    ADD CONSTRAINT supplier_products_product_id_foreign FOREIGN KEY (product_id) REFERENCES autotest.products(id) ON DELETE CASCADE;


--
-- Name: supplier_products supplier_products_supplier_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.supplier_products
    ADD CONSTRAINT supplier_products_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES autotest.suppliers(id) ON DELETE CASCADE;


--
-- Name: technician_jobs technician_jobs_technician_id_foreign; Type: FK CONSTRAINT; Schema: autotest; Owner: -
--

ALTER TABLE ONLY autotest.technician_jobs
    ADD CONSTRAINT technician_jobs_technician_id_foreign FOREIGN KEY (technician_id) REFERENCES autotest.technicians(id) ON DELETE RESTRICT;


--
-- PostgreSQL database dump complete
--

\unrestrict hBfLDtChMAnW1zo9PwFIurB6TXZrcwz7XCMCFHOAIO9Zqmyn1Ecmc69g6TEJIJF

--
-- PostgreSQL database dump
--

\restrict tevS4zGUafq6HwB9sLDCuPisuYV88gFQiAiN2LkAtTX04PG9vqK0fAQzuYXbaqj

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: autotest; Owner: -
--

COPY autotest.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_06_16_155835_create_permission_tables	1
5	2026_07_01_223407_create_stocks_table	1
6	2026_07_02_055615_create_devices_table	1
7	2026_07_02_081943_create_sims_table	1
8	2026_07_06_000001_create_suppliers_table	1
9	2026_07_06_000002_create_products_table	1
10	2026_07_06_000003_create_supplier_products_table	1
11	2026_07_08_165820_add_canceled_date_to_devices_table	1
12	2026_07_08_183831_add_description_and_canceled_at_to_sims_table	1
13	2026_07_08_205401_create_dealers_table	1
14	2026_07_09_120000_slim_dealers_table_to_match_form	1
15	2026_07_10_000000_create_device_types_table	1
16	2026_07_10_000000_drop_devices_create_setup_shalotrack_devices_table	1
17	2026_07_10_000100_update_device_types_unique_constraint	1
18	2026_07_10_000200_restructure_stocks_table_for_raw_devices	1
19	2026_07_10_000300_add_unique_device_type_id_to_stocks_table	1
20	2026_07_10_000400_stocks_supplier_description_reorder	1
21	2026_07_10_054237_add_sim_status_to_sims_table	1
22	2026_07_10_072233_add_dealer_transfer_to_stocks_and_create_transfers_table	1
23	2026_07_12_005033_add_status_to_setup_shalotrack_devices_table	1
24	2026_07_12_021332_add_dealer_id_to_setup_shalotrack_devices_table	1
25	2026_07_15_054830_create_personal_access_tokens_table	1
26	2026_07_21_172141_create_vehicle_ad_table	1
27	2026_07_31_000000_add_dealer_id_to_admins_table	1
28	2026_08_02_000000_add_supplier_id_to_admins_table	1
29	2026_08_05_000000_create_features_table	1
30	2026_08_05_000100_add_features_to_device_types_table	1
31	2026_08_07_000000_add_allocated_at_to_setup_shalotrack_devices_table	1
32	2026_08_07_010000_add_device_type_id_to_products_table	1
33	2026_08_07_010100_create_supplier_invoices_table	1
34	2026_08_07_010200_create_supplier_invoice_items_table	1
35	xxxx_create_customer_ad_table	1
36	2026_08_10_000000_update_customer_ad_table_for_verification_status	2
37	2026_08_10_184712_create_activated_devices_table	2
38	2026_08_10_212552_add_subscription_fields_to_activated_devices_table	2
39	2026_08_10_212839_create_expired_devices_table	2
40	2026_08_11_000000_add_expired_date_to_expired_devices_table	2
41	2026_08_11_010000_consolidate_stocks_table_to_one_row_per_device_type	2
42	2026_08_11_010100_create_stock_transfer_ledgers_table	2
43	2026_08_11_020000_add_device_category_type_to_stocks_table	2
44	2026_08_11_020100_add_device_category_type_to_stock_transfer_ledgers_table	2
45	2026_08_11_130609_create_dealer_customer_ads_table	2
46	2026_08_11_164653_add_imei_numbers_to_dealer_customer_ads_table	2
47	2026_08_12_010000_rebuild_sims_table	2
48	2026_08_12_064646_create_dealer_transfer_ledgers_table	2
49	2026_08_12_064656_add_transfer_id_to_setup_shalotrack_devices_table	2
50	2026_09_09_000001_add_email_to_dealer_customer_ads_table	2
51	2026_09_28_000001_add_qty_to_supplier_products_table	2
52	2026_09_28_000002_create_dashboard_metric_snapshots_table	2
53	2026_09_28_000003_create_dealer_commission_rates_table	2
54	2026_09_28_000004_create_dealer_commission_ledger_table	2
55	2026_09_29_000000_add_sim_identity_and_intake_audit_to_setup_shalotrack_devices_table	2
56	2026_10_01_000001_create_dealer_commission_payouts_table	2
57	2026_10_01_000002_create_audit_logs_table	2
58	2026_10_02_000001_add_install_details_to_activated_devices_table	2
59	2026_10_02_000002_create_technicians_and_technician_jobs_tables	2
60	2026_10_02_000003_create_renewal_packages_table	2
61	2026_10_02_000004_add_warranty_basis_to_activated_devices_table	2
62	2026_10_02_000005_add_distributor_to_dealers_table	2
63	2026_10_02_000006_create_package_payments_and_commission_entries_tables	2
64	2026_10_07_000001_add_soft_deletes_to_history_tables	2
65	2026_10_07_000002_restrict_deletes_on_money_and_history_tables	2
66	2026_10_14_000001_add_password_columns_to_admins_table	2
67	2026_10_14_000002_create_password_reset_requests_table	2
68	2026_10_21_000001_allow_distributor_role_on_admins	2
69	2026_10_21_000002_add_source_and_items_to_dealer_transfers	2
70	2026_10_22_000001_create_wholesale_billing_tables	2
71	2026_10_22_000002_add_postgres_guards_to_billing_tables	2
72	2026_10_29_000001_create_gateway_status_events_table	2
73	2026_11_09_000001_create_device_prices_table	3
74	2026_11_09_000002_create_supplier_delivery_tables	3
75	2026_11_09_000003_add_stock_link_to_supplier_deliveries	3
76	2026_11_09_000004_link_purchase_orders_deliveries_and_sim_numbers	3
77	2026_11_09_000005_restore_supplier_product_links_from_stock_history	3
78	2026_11_09_000006_create_supplier_payments_table	3
79	2026_11_10_000001_make_sims_imsi_optional	3
80	2026_11_11_000001_create_device_sync_state_table	4
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: autotest; Owner: -
--

SELECT pg_catalog.setval('autotest.migrations_id_seq', 80, true);


--
-- PostgreSQL database dump complete
--

\unrestrict tevS4zGUafq6HwB9sLDCuPisuYV88gFQiAiN2LkAtTX04PG9vqK0fAQzuYXbaqj

