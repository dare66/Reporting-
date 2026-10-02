-- AIXBI demonstration dataset: Student Application Intelligence.
-- Entirely synthetic. No real persons. Deterministic for a given END_DATE and SCALE.
-- Placeholders: {{END_DATE}} (YYYY-MM-DD), {{SCALE}} (float), {{READER_PASSWORD}}

SET max_parallel_workers_per_gather = 0;
SELECT setseed(0.4242);

DROP SCHEMA IF EXISTS analytics CASCADE;
CREATE SCHEMA analytics;

CREATE TABLE analytics.countries (
    id smallint PRIMARY KEY, code char(2) NOT NULL UNIQUE, name text NOT NULL, region text NOT NULL,
    latitude numeric(8,4) NOT NULL, longitude numeric(8,4) NOT NULL,
    weight numeric NOT NULL, risk_factor numeric NOT NULL
);
INSERT INTO analytics.countries VALUES
 (1,'CN','China','East Asia',35.86,104.20,0.22,0.30),
 (2,'IN','India','South Asia',20.59,78.96,0.13,0.35),
 (3,'ID','Indonesia','Southeast Asia',-0.79,113.92,0.10,0.25),
 (4,'BD','Bangladesh','South Asia',23.68,90.36,0.08,0.45),
 (5,'NG','Nigeria','West Africa',9.08,8.68,0.07,0.55),
 (6,'PK','Pakistan','South Asia',30.38,69.35,0.06,0.45),
 (7,'EG','Egypt','North Africa',26.82,30.80,0.04,0.40),
 (8,'YE','Yemen','Middle East',15.55,48.52,0.03,0.60),
 (9,'IR','Iran','Middle East',32.43,53.69,0.03,0.40),
 (10,'VN','Vietnam','Southeast Asia',14.06,108.28,0.03,0.25),
 (11,'KZ','Kazakhstan','Central Asia',48.02,66.92,0.02,0.30),
 (12,'UZ','Uzbekistan','Central Asia',41.38,64.59,0.02,0.35),
 (13,'TH','Thailand','Southeast Asia',15.87,100.99,0.02,0.20),
 (14,'JP','Japan','East Asia',36.20,138.25,0.02,0.10),
 (15,'KR','South Korea','East Asia',35.91,127.77,0.02,0.10),
 (16,'SA','Saudi Arabia','Middle East',23.89,45.08,0.02,0.20),
 (17,'SD','Sudan','North Africa',12.86,30.22,0.02,0.60),
 (18,'SO','Somalia','East Africa',5.15,46.20,0.015,0.65),
 (19,'KE','Kenya','East Africa',-0.02,37.91,0.015,0.40),
 (20,'LY','Libya','North Africa',26.34,17.23,0.01,0.55),
 (21,'IQ','Iraq','Middle East',33.22,43.68,0.02,0.45),
 (22,'MV','Maldives','South Asia',3.20,73.22,0.01,0.20),
 (23,'TR','Turkey','Europe',38.96,35.24,0.01,0.20),
 (24,'GB','United Kingdom','Europe',55.38,-3.44,0.01,0.05),
 (25,'US','United States','North America',37.09,-95.71,0.01,0.05);

-- Fictional higher-education institutions (IHEs).
CREATE TABLE analytics.institutions (
    id smallint PRIMARY KEY, name text NOT NULL UNIQUE, short_name text NOT NULL, type text NOT NULL,
    state text NOT NULL, city text NOT NULL, latitude numeric(8,4), longitude numeric(8,4),
    weight numeric NOT NULL, ops_strain numeric NOT NULL
);
INSERT INTO analytics.institutions VALUES
 (1,'Meridian University','MERU','University','Selangor','Shah Alam',3.07,101.52,0.11,1.0),
 (2,'Straits Institute of Technology','SIT','University','Penang','George Town',5.41,100.33,0.08,0.2),
 (3,'Kinabalu University College','KUC','University College','Sabah','Kota Kinabalu',5.98,116.07,0.04,0.1),
 (4,'Capital Metropolitan University','CMU','University','Kuala Lumpur','Kuala Lumpur',3.14,101.69,0.10,1.0),
 (5,'Selatan Business School','SBS','College','Johor','Johor Bahru',1.49,103.74,0.05,0.2),
 (6,'Peninsula Medical University','PMU','University','Perak','Ipoh',4.60,101.09,0.05,0.1),
 (7,'Borneo Polytechnic University','BPU','University','Sarawak','Kuching',1.55,110.36,0.04,0.1),
 (8,'Cyberjaya Digital University','CDU','University','Selangor','Cyberjaya',2.92,101.65,0.07,0.3),
 (9,'Melaka Heritage College','MHC','College','Melaka','Melaka',2.19,102.25,0.03,0.1),
 (10,'Klang Valley Institute','KVI','University College','Selangor','Petaling Jaya',3.11,101.61,0.06,0.9),
 (11,'Pahang Coastal University','PCU','University','Pahang','Kuantan',3.82,103.33,0.03,0.1),
 (12,'Northern Gateway University','NGU','University','Kedah','Alor Setar',6.12,100.37,0.03,0.1),
 (13,'Putrajaya School of Governance','PSG','College','Putrajaya','Putrajaya',2.93,101.69,0.02,0.1),
 (14,'Twin Towers International College','TTIC','College','Kuala Lumpur','Kuala Lumpur',3.16,101.71,0.06,0.3),
 (15,'Seremban Engineering University','SEU','University','Negeri Sembilan','Seremban',2.73,101.94,0.04,0.2),
 (16,'East Coast Islamic University','ECIU','University','Terengganu','Kuala Terengganu',5.33,103.14,0.04,0.1),
 (17,'Highlands University of Science','HUS','University','Pahang','Bentong',3.52,101.91,0.03,0.1),
 (18,'Langkawi Hospitality Academy','LHA','College','Kedah','Langkawi',6.35,99.80,0.02,0.1),
 (19,'Bayan Lepas University College','BLUC','University College','Penang','Bayan Lepas',5.30,100.27,0.04,0.2),
 (20,'Johor Innovation University','JIU','University','Johor','Iskandar Puteri',1.43,103.62,0.06,0.3);

CREATE TABLE analytics.courses (
    id smallint PRIMARY KEY, name text NOT NULL, field text NOT NULL, level text NOT NULL,
    fee numeric(10,2) NOT NULL, weight numeric NOT NULL
);
INSERT INTO analytics.courses VALUES
 (1,'Computer Science','Computing','Bachelor',950,0.12),
 (2,'Software Engineering','Computing','Bachelor',950,0.06),
 (3,'Data Science','Computing','Master',1250,0.05),
 (4,'Cyber Security','Computing','Master',1250,0.03),
 (5,'Business Administration','Business','Bachelor',850,0.10),
 (6,'MBA','Business','Master',1450,0.07),
 (7,'Accounting & Finance','Business','Bachelor',850,0.06),
 (8,'International Business','Business','Diploma',600,0.03),
 (9,'Mechanical Engineering','Engineering','Bachelor',1000,0.05),
 (10,'Electrical Engineering','Engineering','Bachelor',1000,0.04),
 (11,'Civil Engineering','Engineering','Bachelor',1000,0.03),
 (12,'Petroleum Engineering','Engineering','Master',1350,0.02),
 (13,'Medicine (MBBS)','Health','Bachelor',1650,0.04),
 (14,'Nursing','Health','Diploma',700,0.03),
 (15,'Pharmacy','Health','Bachelor',1200,0.03),
 (16,'Public Health','Health','Master',1250,0.02),
 (17,'Hospitality Management','Hospitality','Diploma',600,0.03),
 (18,'Culinary Arts','Hospitality','Diploma',650,0.02),
 (19,'Architecture','Design','Bachelor',1050,0.02),
 (20,'Graphic Design','Design','Diploma',600,0.02),
 (21,'Islamic Finance','Business','Master',1300,0.02),
 (22,'English Language','Languages','Diploma',450,0.04),
 (23,'Law (LLB)','Law','Bachelor',1100,0.03),
 (24,'Education','Education','Master',1100,0.02),
 (25,'Doctor of Philosophy (Engineering)','Engineering','PhD',1800,0.015),
 (26,'Doctor of Philosophy (Computing)','Computing','PhD',1800,0.015),
 (27,'Biotechnology','Science','Bachelor',1000,0.02),
 (28,'Psychology','Social Science','Bachelor',900,0.02);

-- Cumulative weight ranges for deterministic weighted sampling.
CREATE TEMP TABLE w_country AS
  SELECT id, SUM(weight) OVER (ORDER BY id) / SUM(weight) OVER () AS hi,
         (SUM(weight) OVER (ORDER BY id) - weight) / SUM(weight) OVER () AS lo FROM analytics.countries;
CREATE TEMP TABLE w_inst AS
  SELECT id, SUM(weight) OVER (ORDER BY id) / SUM(weight) OVER () AS hi,
         (SUM(weight) OVER (ORDER BY id) - weight) / SUM(weight) OVER () AS lo FROM analytics.institutions;
CREATE TEMP TABLE w_course AS
  SELECT id, SUM(weight) OVER (ORDER BY id) / SUM(weight) OVER () AS hi,
         (SUM(weight) OVER (ORDER BY id) - weight) / SUM(weight) OVER () AS lo FROM analytics.courses;

-- Daily operating capacity (processing officers). Grows ~4% over the final quarter
-- while demand grows faster — the source of the SLA pressure story.
CREATE TABLE analytics.processing_capacity (
    capacity_date date PRIMARY KEY, officers int NOT NULL, daily_capacity int NOT NULL
);
INSERT INTO analytics.processing_capacity
SELECT d::date,
       (60 + 6 * (d::date - (DATE '{{END_DATE}}' - 730)) / 730.0 + CASE WHEN d::date >= DATE '{{END_DATE}}' - 90 THEN 2.6 ELSE 0 END)::int,
       ((60 + 6 * (d::date - (DATE '{{END_DATE}}' - 730)) / 730.0 + CASE WHEN d::date >= DATE '{{END_DATE}}' - 90 THEN 2.6 ELSE 0 END) * 6.5 * {{SCALE}})::int
FROM generate_series(DATE '{{END_DATE}}' - 730, DATE '{{END_DATE}}', interval '1 day') d;

-- Daily demand profile: growth trend × intake seasonality × weekday × recent surge.
CREATE TEMP TABLE day_volume AS
SELECT d::date AS day,
       GREATEST(1, round(380 * {{SCALE}}
         * (1 + 0.16 * (d::date - (DATE '{{END_DATE}}' - 730)) / 730.0)
         * (CASE EXTRACT(MONTH FROM d)::int WHEN 1 THEN 1.15 WHEN 2 THEN 1.05 WHEN 3 THEN 0.92 WHEN 4 THEN 0.86 WHEN 5 THEN 0.9
             WHEN 6 THEN 1.0 WHEN 7 THEN 1.18 WHEN 8 THEN 1.24 WHEN 9 THEN 1.14 WHEN 10 THEN 1.0 WHEN 11 THEN 0.9 ELSE 0.8 END)
         * (CASE EXTRACT(ISODOW FROM d)::int WHEN 6 THEN 0.55 WHEN 7 THEN 0.45 ELSE 1.1 END)
         * (CASE WHEN d::date >= DATE '{{END_DATE}}' - 75 THEN 1.14 ELSE 1 END)
         * (0.92 + random() * 0.16)))::int AS n,
       -- Operational load multiplier felt by processing teams.
       CASE WHEN d::date >= DATE '{{END_DATE}}' - 60 THEN 1 ELSE 0 END AS strained
FROM generate_series(DATE '{{END_DATE}}' - 730, DATE '{{END_DATE}}', interval '1 day') d;

CREATE TABLE analytics.applications (
    id bigint PRIMARY KEY,
    application_no text NOT NULL,
    applicant_ref text NOT NULL,          -- pseudonymous; classified sensitive
    submitted_on date NOT NULL,
    decided_on date,
    country_id smallint NOT NULL REFERENCES analytics.countries(id),
    institution_id smallint NOT NULL REFERENCES analytics.institutions(id),
    course_id smallint NOT NULL REFERENCES analytics.courses(id),
    channel text NOT NULL,
    status text NOT NULL,                 -- approved | rejected | pending | withdrawn
    stage text NOT NULL,                  -- submitted | document_review | assessment | visa | medical | completed
    document_issue text NOT NULL,         -- none | passport | transcript | financial | medical
    processing_days int,
    sla_target_days int NOT NULL DEFAULT 14,
    sla_met boolean,
    risk_score numeric(5,3) NOT NULL,
    risk_level text NOT NULL,             -- low | medium | high
    fee_amount numeric(10,2) NOT NULL,
    visa_status text NOT NULL,            -- not_started | in_progress | issued | refused
    medical_status text NOT NULL,         -- not_started | cleared | referred
    age_band text NOT NULL
);

INSERT INTO analytics.applications
WITH raw AS (
    SELECT row_number() OVER (ORDER BY dv.day, g) AS id, dv.day, dv.strained,
           random() AS rc, random() AS ri, random() AS rk, random() AS rch, random() AS rdoc,
           random() AS rrisk, random() AS rstat, random_normal(0, 2.4) AS noise, random() AS rage, random() AS rw
    FROM day_volume dv, generate_series(1, dv.n) g
), picked AS (
    SELECT r.*, c.id AS country_id, i.id AS institution_id, k.id AS course_id,
           co.code AS country_code, co.risk_factor, ins.ops_strain, crs.field, crs.fee,
           (r.day >= DATE '{{END_DATE}}' - 38 AND co.code = 'CN' AND (i.id = 1 OR crs.field = 'Computing')) AS anomaly_slice
    FROM raw r
    JOIN w_country c ON r.rc >= c.lo AND r.rc < c.hi
    JOIN w_inst i ON r.ri >= i.lo AND r.ri < i.hi
    JOIN w_course k ON r.rk >= k.lo AND r.rk < k.hi
    JOIN analytics.countries co ON co.id = c.id
    JOIN analytics.institutions ins ON ins.id = i.id
    JOIN analytics.courses crs ON crs.id = k.id
), issues AS (
    SELECT p.*,
           CASE
             WHEN p.anomaly_slice AND p.rdoc < 0.70 THEN 'passport'
             WHEN p.rdoc < 0.025 + 0.03 * p.risk_factor THEN 'passport'
             WHEN p.rdoc < 0.06 + 0.03 * p.risk_factor THEN 'transcript'
             WHEN p.rdoc < 0.09 + 0.05 * p.risk_factor THEN 'financial'
             WHEN p.rdoc < 0.10 + 0.05 * p.risk_factor THEN 'medical'
             ELSE 'none' END AS document_issue,
           LEAST(0.999, GREATEST(0.01, 0.15 + 0.55 * p.risk_factor * p.rrisk + 0.25 * random())) AS risk_score
    FROM picked p
), timed AS (
    SELECT s.*,
           GREATEST(2, round(10 + s.noise
               + CASE WHEN s.document_issue <> 'none' THEN 3 ELSE 0 END
               + s.strained * (0.3 + 1.9 * s.ops_strain)))::int AS processing_days
    FROM issues s
)
SELECT t.id,
       'APP-' || to_char(t.day, 'YYMM') || '-' || lpad(t.id::text, 7, '0'),
       'AP' || upper(substr(md5(t.id::text || 'aixbi'), 1, 10)),
       t.day,
       CASE WHEN t.rstat < 0.02 OR t.day + t.processing_days > DATE '{{END_DATE}}' THEN NULL ELSE t.day + t.processing_days END,
       t.country_id, t.institution_id, t.course_id,
       CASE WHEN t.rch < 0.58 THEN 'online' WHEN t.rch < 0.88 THEN 'agent' ELSE 'partner' END,
       CASE
         WHEN t.day + t.processing_days > DATE '{{END_DATE}}' THEN 'pending'
         WHEN t.rstat < 0.02 THEN 'withdrawn'
         WHEN t.rstat < 0.02 + 0.055
                + CASE WHEN t.risk_score > 0.62 THEN 0.09 ELSE 0 END
                + CASE WHEN t.document_issue <> 'none' THEN 0.07 ELSE 0 END
                + CASE WHEN t.anomaly_slice AND t.document_issue = 'passport' THEN 0.60 ELSE 0 END THEN 'rejected'
         ELSE 'approved' END,
       CASE
         WHEN t.day + t.processing_days <= DATE '{{END_DATE}}' THEN 'completed'
         WHEN DATE '{{END_DATE}}' - t.day < 2 THEN 'submitted'
         WHEN DATE '{{END_DATE}}' - t.day < 5 THEN 'document_review'
         WHEN DATE '{{END_DATE}}' - t.day < 9 THEN 'assessment'
         WHEN DATE '{{END_DATE}}' - t.day < 12 THEN 'visa'
         ELSE 'medical' END,
       t.document_issue,
       CASE WHEN t.day + t.processing_days > DATE '{{END_DATE}}' OR t.rstat < 0.02 THEN NULL ELSE t.processing_days END,
       14,
       CASE WHEN t.day + t.processing_days > DATE '{{END_DATE}}' OR t.rstat < 0.02 THEN NULL ELSE t.processing_days <= 14 END,
       round(t.risk_score::numeric, 3),
       CASE WHEN t.risk_score > 0.62 THEN 'high' WHEN t.risk_score > 0.40 THEN 'medium' ELSE 'low' END,
       t.fee,
       'not_started', 'not_started',
       CASE WHEN t.rage < 0.46 THEN '18-21' WHEN t.rage < 0.78 THEN '22-25' WHEN t.rage < 0.93 THEN '26-30' ELSE '31+' END
FROM timed t;

-- Downstream visa & medical progression for decided applications.
UPDATE analytics.applications SET
    visa_status = CASE WHEN status = 'approved' THEN (CASE WHEN random() < 0.94 THEN 'issued' ELSE 'refused' END)
                       WHEN status = 'pending' AND stage IN ('visa', 'medical') THEN 'in_progress' ELSE 'not_started' END,
    medical_status = CASE WHEN status = 'approved' THEN (CASE WHEN random() < 0.97 THEN 'cleared' ELSE 'referred' END)
                          ELSE 'not_started' END;

CREATE TABLE analytics.payments (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES analytics.applications(id),
    paid_on date NOT NULL,
    payment_type text NOT NULL,
    method text NOT NULL,
    amount numeric(12,2) NOT NULL,
    country_id smallint NOT NULL,
    institution_id smallint NOT NULL
);
INSERT INTO analytics.payments (application_id, paid_on, payment_type, method, amount, country_id, institution_id)
SELECT id, submitted_on, 'application_fee',
       CASE WHEN random() < 0.7 THEN 'card' WHEN random() < 0.9 THEN 'bank_transfer' ELSE 'e_wallet' END,
       fee_amount, country_id, institution_id
FROM analytics.applications WHERE status <> 'withdrawn';
INSERT INTO analytics.payments (application_id, paid_on, payment_type, method, amount, country_id, institution_id)
SELECT id, decided_on, 'visa_fee', 'card', 260, country_id, institution_id
FROM analytics.applications WHERE status = 'approved';
INSERT INTO analytics.payments (application_id, paid_on, payment_type, method, amount, country_id, institution_id)
SELECT id, decided_on, 'medical_fee', 'card', 250, country_id, institution_id
FROM analytics.applications WHERE status = 'approved' AND medical_status <> 'not_started';

CREATE TABLE analytics.attendance_monthly (
    institution_id smallint NOT NULL REFERENCES analytics.institutions(id),
    month date NOT NULL,
    enrolled int NOT NULL,
    attendance_rate numeric(5,4) NOT NULL,
    PRIMARY KEY (institution_id, month)
);
INSERT INTO analytics.attendance_monthly
SELECT i.id, m::date,
       (SELECT count(*) FROM analytics.applications a WHERE a.institution_id = i.id AND a.status = 'approved' AND a.decided_on < m::date + interval '1 month'),
       LEAST(0.99, 0.86 + 0.08 * random() - CASE WHEN i.ops_strain > 0.5 THEN 0.02 ELSE 0 END)
FROM analytics.institutions i, generate_series(date_trunc('month', DATE '{{END_DATE}}' - 700), date_trunc('month', DATE '{{END_DATE}}'), interval '1 month') m;

-- Analytical indexes (column-store engines replace these in production).
CREATE INDEX ON analytics.applications (submitted_on);
CREATE INDEX ON analytics.applications (country_id, submitted_on);
CREATE INDEX ON analytics.applications (institution_id, submitted_on);
CREATE INDEX ON analytics.applications (status);
CREATE INDEX ON analytics.payments (paid_on);
CREATE INDEX ON analytics.payments (application_id);

ALTER TABLE analytics.countries DROP COLUMN weight, DROP COLUMN risk_factor;
ALTER TABLE analytics.institutions DROP COLUMN weight, DROP COLUMN ops_strain;
ALTER TABLE analytics.courses DROP COLUMN weight;

ANALYZE analytics.applications;
ANALYZE analytics.payments;

-- Read-only role for the analytical connection.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'aixbi_reader') THEN
    CREATE ROLE aixbi_reader LOGIN PASSWORD '{{READER_PASSWORD}}';
  END IF;
END $$;
ALTER ROLE aixbi_reader SET default_transaction_read_only = on;
GRANT USAGE ON SCHEMA analytics TO aixbi_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO aixbi_reader;
