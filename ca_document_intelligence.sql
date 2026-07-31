--
-- PostgreSQL database dump
--

\restrict 8XgabK1gVWxygUK4nidKKHusEKglaA04UWh3lHOHvOEoLYvxxG9TYKWLfLP7fYb

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4 (Ubuntu 18.4-1.pgdg22.04+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: document_chart_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_chart_points (
    id bigint NOT NULL,
    document_chart_id bigint NOT NULL,
    label character varying(255) NOT NULL,
    value numeric(20,4) NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: document_chart_points_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.document_chart_points_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_chart_points_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.document_chart_points_id_seq OWNED BY public.document_chart_points.id;


--
-- Name: document_charts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_charts (
    id bigint NOT NULL,
    document_id uuid NOT NULL,
    type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text NOT NULL,
    data json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT document_charts_type_check CHECK (((type)::text = ANY ((ARRAY['bar'::character varying, 'line'::character varying, 'pie'::character varying, 'table'::character varying])::text[])))
);


--
-- Name: document_charts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.document_charts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_charts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.document_charts_id_seq OWNED BY public.document_charts.id;


--
-- Name: document_kpis; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_kpis (
    id bigint NOT NULL,
    document_id uuid NOT NULL,
    label character varying(255) NOT NULL,
    value character varying(255) NOT NULL,
    unit character varying(255),
    trend character varying(255),
    trend_value character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    value_numeric numeric(20,4),
    CONSTRAINT document_kpis_trend_check CHECK (((trend)::text = ANY ((ARRAY['up'::character varying, 'down'::character varying, 'flat'::character varying])::text[])))
);


--
-- Name: document_kpis_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.document_kpis_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_kpis_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.document_kpis_id_seq OWNED BY public.document_kpis.id;


--
-- Name: document_page_flags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_page_flags (
    id bigint NOT NULL,
    document_id uuid NOT NULL,
    page integer NOT NULL,
    status character varying(255) NOT NULL,
    note text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT document_page_flags_status_check CHECK (((status)::text = ANY ((ARRAY['parsed'::character varying, 'partial'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: document_page_flags_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.document_page_flags_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_page_flags_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.document_page_flags_id_seq OWNED BY public.document_page_flags.id;


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    size_kb integer NOT NULL,
    status character varying(255) DEFAULT 'Processing'::character varying NOT NULL,
    classification character varying(255) NOT NULL,
    year smallint NOT NULL,
    uploaded_by uuid,
    last_updated_by uuid,
    pages integer DEFAULT 0 NOT NULL,
    has_structured_data boolean DEFAULT false NOT NULL,
    progress smallint,
    error_message text,
    power_bi_status character varying(255) DEFAULT 'not-synced'::character varying NOT NULL,
    insights json,
    file_path character varying(255),
    file_hash character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    extraction_attempts smallint DEFAULT '0'::smallint NOT NULL,
    extraction_started_at timestamp(0) without time zone,
    extraction_completed_at timestamp(0) without time zone,
    extraction_input_tokens integer,
    extraction_output_tokens integer,
    CONSTRAINT documents_classification_check CHECK (((classification)::text = ANY ((ARRAY['Public'::character varying, 'Internal'::character varying, 'Confidential'::character varying, 'Restricted'::character varying])::text[]))),
    CONSTRAINT documents_power_bi_status_check CHECK (((power_bi_status)::text = ANY ((ARRAY['synced'::character varying, 'not-synced'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT documents_status_check CHECK (((status)::text = ANY ((ARRAY['Processing'::character varying, 'Ready'::character varying, 'Needs Review'::character varying, 'Failed'::character varying])::text[]))),
    CONSTRAINT documents_type_check CHECK (((type)::text = ANY ((ARRAY['PDF'::character varying, 'DOCX'::character varying])::text[])))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
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
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: power_bi_chart_points; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.power_bi_chart_points AS
 SELECT d.id AS document_id,
    d.name AS document_name,
    d.classification,
    d.year,
    c.id AS chart_id,
    c.type AS chart_type,
    c.title AS chart_title,
    p.label,
    p.value,
    p.sort_order
   FROM ((public.document_chart_points p
     JOIN public.document_charts c ON ((c.id = p.document_chart_id)))
     JOIN public.documents d ON ((d.id = c.document_id)))
  WHERE (((d.classification)::text <> 'Restricted'::text) AND ((d.status)::text = 'Ready'::text));


--
-- Name: power_bi_kpis; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.power_bi_kpis AS
 SELECT d.id AS document_id,
    d.name AS document_name,
    d.classification,
    d.year,
    d.created_at AS document_uploaded_at,
    k.label,
    k.value AS value_display,
    k.value_numeric,
    k.unit,
    k.trend,
    k.trend_value
   FROM (public.document_kpis k
     JOIN public.documents d ON ((d.id = k.document_id)))
  WHERE (((d.classification)::text <> 'Restricted'::text) AND ((d.status)::text = 'Ready'::text));


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    full_name character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'Viewer'::character varying NOT NULL,
    email_verified_at timestamp(0) without time zone,
    active boolean DEFAULT true NOT NULL,
    last_active_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    pending_email character varying(255),
    pending_email_code character varying(255),
    pending_email_expires_at timestamp(0) without time zone,
    CONSTRAINT users_role_check CHECK (((role)::text = ANY ((ARRAY['Administrator'::character varying, 'Reviewer'::character varying, 'Analyst'::character varying, 'Viewer'::character varying])::text[])))
);


--
-- Name: verification_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.verification_codes (
    id bigint NOT NULL,
    user_id uuid NOT NULL,
    code character varying(6) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: verification_codes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.verification_codes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: verification_codes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.verification_codes_id_seq OWNED BY public.verification_codes.id;


--
-- Name: document_chart_points id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_chart_points ALTER COLUMN id SET DEFAULT nextval('public.document_chart_points_id_seq'::regclass);


--
-- Name: document_charts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_charts ALTER COLUMN id SET DEFAULT nextval('public.document_charts_id_seq'::regclass);


--
-- Name: document_kpis id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_kpis ALTER COLUMN id SET DEFAULT nextval('public.document_kpis_id_seq'::regclass);


--
-- Name: document_page_flags id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_page_flags ALTER COLUMN id SET DEFAULT nextval('public.document_page_flags_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: verification_codes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_codes ALTER COLUMN id SET DEFAULT nextval('public.verification_codes_id_seq'::regclass);


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: document_chart_points; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.document_chart_points (id, document_chart_id, label, value, sort_order, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: document_charts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.document_charts (id, document_id, type, title, description, data, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: document_kpis; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.document_kpis (id, document_id, label, value, unit, trend, trend_value, created_at, updated_at, value_numeric) FROM stdin;
\.


--
-- Data for Name: document_page_flags; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.document_page_flags (id, document_id, page, status, note, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: documents; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.documents (id, name, type, size_kb, status, classification, year, uploaded_by, last_updated_by, pages, has_structured_data, progress, error_message, power_bi_status, insights, file_path, file_hash, created_at, updated_at, extraction_attempts, extraction_started_at, extraction_completed_at, extraction_input_tokens, extraction_output_tokens) FROM stdin;
019fb2e3-7a7a-7205-911b-37b499f0d6dc	Report of Service Delivery Monitoring Q1 FY 2025-2026 Final.docx	DOCX	125	Processing	Public	2026	019fb27b-fe80-7390-87f3-5e74a0e0953c	\N	0	f	0	\N	not-synced	[]	de4bd78b-1902-42f1-9bed-d2ca98521317.docx	75358d022cf371c83b818db94ed1f29063faed9c31b938592ba52a0b8df1b3a0	2026-07-30 11:58:00	2026-07-30 11:58:00	0	\N	\N	\N	\N
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_07_21_122257_create_verification_codes_table	1
5	2026_07_21_122259_create_documents_table	1
6	2026_07_21_122302_create_document_kpis_table	1
7	2026_07_21_122304_create_document_charts_table	1
8	2026_07_21_122306_create_document_page_flags_table	1
9	2026_07_21_124923_change_uploaded_by_to_nullable_on_documents_table	1
10	2026_07_21_130457_create_personal_access_tokens_table	1
11	2026_07_23_000000_create_password_reset_tokens_table	1
12	2026_07_23_093218_add_extraction_fields_to_documents_table	1
13	2026_07_23_093452_add_extraction_fields_to_documents_table	1
14	2026_07_24_201559_add_value_numeric_to_document_kpis_table	1
15	2026_07_24_201643_create_document_chart_points_table	1
16	2026_07_24_201708_create_power_bi_reporting_views	1
17	2026_07_28_075627_make_users_email_case_insensitive	2
18	2026_07_28_081444_add_lower_email_unique_index_to_users_table	3
19	2026_07_30_052405_add_pending_email_change_to_users_table	4
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
danielkemboi462@gmail.com	$2y$12$bv2SSx6xg7Jaw5HGtTUgVuLuEHgjbJpqZkyJLJbM8Z2SLPCe0/vz.	2026-07-28 07:28:20
admin123@gmail.com	$2y$12$n0bj7ttuiCpOq1nvJEFzU.QMHpjQlLn.5KE/LF9PTwQbD0MB3m1Qu	2026-07-29 12:10:45
\.


--
-- Data for Name: personal_access_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at) FROM stdin;
2	App\\Models\\User	019fa78c-9f3e-7284-8527-190d8737fd4b	auth-token	ff74485d6b246e35aaf00b8f8380511a3986bd909c43098a9ff1c9a727e58af5	["*"]	\N	2026-07-28 09:07:19	2026-07-28 07:07:20	2026-07-28 07:07:20
3	App\\Models\\User	019fa79a-7c81-70ba-be9b-1f6d81ac6845	auth-token	631ce1de7dff3fa053f5da2102dbf9aed1a393a946a980e744d73658343da3f7	["*"]	\N	2026-07-28 09:22:28	2026-07-28 07:22:28	2026-07-28 07:22:28
4	App\\Models\\User	019fa79a-861f-73a9-9ddb-b433ac41ff99	auth-token	d603059e32fc4508cda804c580ba3d052c25ca55bb32b5a51bd67a1d59b0162a	["*"]	\N	2026-07-28 09:22:29	2026-07-28 07:22:29	2026-07-28 07:22:29
5	App\\Models\\User	019fa79a-8ca4-7025-88e5-c91cfe684a12	auth-token	993f2ff4438fbc3ff2241665e67b82e8835aabf92b108533e53c7cffbd06eaa1	["*"]	\N	2026-07-28 09:22:31	2026-07-28 07:22:31	2026-07-28 07:22:31
8	App\\Models\\User	019fa7a1-9ab4-72f5-9824-823c979e1b32	auth-token	cb5a71baf45c038fd12a287f09db132fdbc9de966483905e1338be1d5cef1006	["*"]	\N	2026-07-28 09:30:13	2026-07-28 07:30:13	2026-07-28 07:30:13
9	App\\Models\\User	019fa7a2-5163-70ec-88c4-093f3a020293	auth-token	58893ed376451c2133a089ab66bb868427d595b236349e0055ab1cad89c89576	["*"]	\N	2026-07-28 09:31:00	2026-07-28 07:31:00	2026-07-28 07:31:00
10	App\\Models\\User	019fa7a2-a05f-73ad-a279-f5d459dcc9fc	auth-token	72ea18588503723c5f3be4d3ac9b26f738291e68dbaf93ccff03109f251cc19e	["*"]	\N	2026-07-28 09:31:20	2026-07-28 07:31:20	2026-07-28 07:31:20
11	App\\Models\\User	019fa7de-5ad8-717b-b4cb-c52b5e323216	auth-token	fce89ff05bde5a2ea2e9956d4503058ad6db60068c937e8ca4db7b899d7044ee	["*"]	\N	2026-07-28 10:36:35	2026-07-28 08:36:35	2026-07-28 08:36:35
14	App\\Models\\User	019fa7e1-66a8-73e5-a608-78cf156ce217	auth-token	b038799cc4eb27703ceeca5530a6b24eed57af22f8b3496cac0eeafc19bc62bf	["*"]	\N	2026-07-28 10:39:54	2026-07-28 08:39:54	2026-07-28 08:39:54
15	App\\Models\\User	019fa7e3-b790-7096-a84a-e18859b01cce	auth-token	f722807db2fd6ad9fb790becbbad4198271581557da08d79b7883e00a09b5e4f	["*"]	\N	2026-07-28 10:42:26	2026-07-28 08:42:26	2026-07-28 08:42:26
16	App\\Models\\User	019fa7ec-195b-7029-bf5a-67e924429884	auth-token	8567cafc65e28ab62f9afe915c0bc892d2c79f1fce3a942287dd9e312fcf6bb3	["*"]	\N	2026-07-28 10:51:35	2026-07-28 08:51:35	2026-07-28 08:51:35
17	App\\Models\\User	019fa7ec-636c-733f-87a2-428fbbdf2dc0	auth-token	6cbd99048d97562317e44314ab93c028a2b3f9cfa3327d7ffa8f3049751c5e88	["*"]	\N	2026-07-28 10:51:54	2026-07-28 08:51:54	2026-07-28 08:51:54
18	App\\Models\\User	019fa7f3-6cdf-70b7-abbe-0691a33bebfd	auth-token	dc6888f7f557bbd238e4c101e612bd6fb67fc3a1fc967aef60cad6307fac3e61	["*"]	\N	2026-07-28 10:59:36	2026-07-28 08:59:36	2026-07-28 08:59:36
19	App\\Models\\User	019fa7f3-b3d6-725e-9f2f-bce1b70ce1fa	auth-token	2aaa9154fbb30b27df2409e510532c479795abcb0d3bac06161faee87dbbc109	["*"]	\N	2026-07-28 10:59:54	2026-07-28 08:59:54	2026-07-28 08:59:54
33	App\\Models\\User	019fb27b-fe80-7390-87f3-5e74a0e0953c	auth-token	aa78dc2fa59c15c5b35fe3bb2de59f28c2bd1f5f3d7b7f41b2d9f83e86982f15	["*"]	2026-07-30 13:29:02	2026-07-30 13:57:29	2026-07-30 11:57:29	2026-07-30 13:29:02
32	App\\Models\\User	019fb27b-fe80-7390-87f3-5e74a0e0953c	auth-token	a0a48a0d915c9b053dd1f63ebea32b2e35ffe06a5e7a9d27fda996198da1c7c3	["*"]	2026-07-30 11:05:31	2026-07-30 12:05:58	2026-07-30 10:05:58	2026-07-30 11:05:31
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.users (id, email, password, full_name, role, email_verified_at, active, last_active_at, remember_token, created_at, updated_at, pending_email, pending_email_code, pending_email_expires_at) FROM stdin;
019fad31-9dbf-7034-806d-5d2af5cfb6e6	admin123@gmail.com	$2y$12$cmtV18uRQrYp0t.Gm2EmZOrQverxdoTM2rS0IPp6R6IsTt77hZvVq	daniel kemboi	Viewer	2026-07-29 09:25:41	t	\N	\N	2026-07-29 09:25:37	2026-07-29 09:25:41	\N	\N	\N
019fad48-21d1-73e2-a456-7c14fd80ae5e	admin1234@gmail.com	$2y$12$QwZ6qS1FkNXGhut4N0RS.e.gf30BPsd0RniCMb4doVtBesQ46e7t6	Daniel Kemboi	Viewer	2026-07-29 09:50:17	t	\N	\N	2026-07-29 09:50:13	2026-07-29 09:50:17	\N	\N	\N
019fad30-1c0d-73ef-b7a8-eb526075c84a	danielkemboi462@gmail.com	$2y$12$.hGqMiOYG0SzzhfYD0ahL.1wtb6V.doLiHyxff1bp7sbwGwNJglGq	Daniel Kemboi	Viewer	2026-07-29 09:23:59	t	2026-07-29 17:49:23	\N	2026-07-29 09:23:59	2026-07-29 17:49:23	\N	\N	\N
019fb27c-001c-7254-bd1b-2f88c144265b	test.reviewer@ca.go.ke	$2y$12$E6t0np1q9PMoGZ1/Uxjze.THPNVWQrCyOh02vRFsMyIe8AYPoHmdC	Test Reviewer	Reviewer	2026-07-30 10:04:58	t	\N	\N	2026-07-30 10:04:58	2026-07-30 10:04:58	\N	\N	\N
019fb27c-019f-71c6-8636-6109a484a029	test.analyst@ca.go.ke	$2y$12$YvdXzDj1fhb7gSY0LBut5Oi4Ncah/dle6DMhoft.uzm.BBuYLn5r6	Test Analyst	Analyst	2026-07-30 10:04:58	t	\N	\N	2026-07-30 10:04:59	2026-07-30 10:04:59	\N	\N	\N
019fb27c-02c2-7083-a53a-d1ba22b422e7	test.viewer@ca.go.ke	$2y$12$gjRKCeYJ0GYCGF1hvKluuOZvNrx4lcx0EUt90VsWfOi42rkm/KaaW	Test Viewer	Viewer	2026-07-30 10:04:59	t	\N	\N	2026-07-30 10:04:59	2026-07-30 10:04:59	\N	\N	\N
019fb27b-fe80-7390-87f3-5e74a0e0953c	test.admin@ca.go.ke	$2y$12$muob8UE9EfEzLjf8afHmdOsddlX/1.W.AQzfce4BZxfKpmq5CrQoe	Test Administrator	Administrator	2026-07-30 10:04:57	t	2026-07-30 11:57:29	\N	2026-07-30 10:04:58	2026-07-30 11:57:29	\N	\N	\N
019fb7a3-1190-73fa-bd41-b4e3102b8b10	dannykhan614@gmail.com	$2y$12$cfVs.pPvnPHHCsS343g99.mW0w79IFH9SKwXqddir8FaM4PDngcBC	Danny Khan	Viewer	2026-07-31 10:05:45	t	2026-07-31 10:05:45	\N	2026-07-31 10:05:45	2026-07-31 10:08:59	\N	\N	\N
\.


--
-- Data for Name: verification_codes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.verification_codes (id, user_id, code, expires_at, created_at, updated_at) FROM stdin;
\.


--
-- Name: document_chart_points_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.document_chart_points_id_seq', 1, false);


--
-- Name: document_charts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.document_charts_id_seq', 1, false);


--
-- Name: document_kpis_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.document_kpis_id_seq', 1, false);


--
-- Name: document_page_flags_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.document_page_flags_id_seq', 1, false);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 19, true);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.personal_access_tokens_id_seq', 34, true);


--
-- Name: verification_codes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.verification_codes_id_seq', 4, true);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: document_chart_points document_chart_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_chart_points
    ADD CONSTRAINT document_chart_points_pkey PRIMARY KEY (id);


--
-- Name: document_charts document_charts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_charts
    ADD CONSTRAINT document_charts_pkey PRIMARY KEY (id);


--
-- Name: document_kpis document_kpis_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_kpis
    ADD CONSTRAINT document_kpis_pkey PRIMARY KEY (id);


--
-- Name: document_page_flags document_page_flags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_page_flags
    ADD CONSTRAINT document_page_flags_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: verification_codes verification_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_codes
    ADD CONSTRAINT verification_codes_pkey PRIMARY KEY (id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: document_chart_points_document_chart_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_chart_points_document_chart_id_index ON public.document_chart_points USING btree (document_chart_id);


--
-- Name: document_charts_document_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_charts_document_id_index ON public.document_charts USING btree (document_id);


--
-- Name: document_kpis_document_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_kpis_document_id_index ON public.document_kpis USING btree (document_id);


--
-- Name: document_page_flags_document_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_page_flags_document_id_index ON public.document_page_flags USING btree (document_id);


--
-- Name: documents_classification_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_classification_index ON public.documents USING btree (classification);


--
-- Name: documents_file_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_file_hash_index ON public.documents USING btree (file_hash);


--
-- Name: documents_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_status_index ON public.documents USING btree (status);


--
-- Name: documents_uploaded_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_uploaded_by_index ON public.documents USING btree (uploaded_by);


--
-- Name: documents_year_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_year_index ON public.documents USING btree (year);


--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: users_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_active_index ON public.users USING btree (active);


--
-- Name: users_email_lower_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_email_lower_unique ON public.users USING btree (lower((email)::text));


--
-- Name: users_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_role_index ON public.users USING btree (role);


--
-- Name: verification_codes_user_id_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX verification_codes_user_id_code_index ON public.verification_codes USING btree (user_id, code);


--
-- Name: document_chart_points document_chart_points_document_chart_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_chart_points
    ADD CONSTRAINT document_chart_points_document_chart_id_foreign FOREIGN KEY (document_chart_id) REFERENCES public.document_charts(id) ON DELETE CASCADE;


--
-- Name: document_charts document_charts_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_charts
    ADD CONSTRAINT document_charts_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: document_kpis document_kpis_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_kpis
    ADD CONSTRAINT document_kpis_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: document_page_flags document_page_flags_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_page_flags
    ADD CONSTRAINT document_page_flags_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: documents documents_last_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_last_updated_by_foreign FOREIGN KEY (last_updated_by) REFERENCES public.users(id);


--
-- Name: documents documents_uploaded_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: verification_codes verification_codes_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_codes
    ADD CONSTRAINT verification_codes_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 8XgabK1gVWxygUK4nidKKHusEKglaA04UWh3lHOHvOEoLYvxxG9TYKWLfLP7fYb

