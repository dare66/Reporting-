import pytest

from app.agents.catalog import CatalogIndex

CATALOG = [
    {
        "key": "applications",
        "name": "Application Intake",
        "dimensions": [
            {"key": "submitted_on", "label": "Submission Date", "type": "time", "synonyms": []},
            {"key": "country", "label": "Country", "type": "string", "synonyms": ["nation", "market"]},
            {"key": "country_code", "label": "Country Code", "type": "string", "synonyms": []},
            {"key": "institution", "label": "Institution", "type": "string", "synonyms": ["ihe", "university"]},
            {"key": "stage", "label": "Processing Stage", "type": "string", "synonyms": ["pipeline stage"]},
            {
                "key": "applicant_ref",
                "label": "Applicant Reference",
                "type": "string",
                "synonyms": [],
                "accessible": False,
            },
        ],
        "metrics": [
            {
                "ref": "applications.total_applications",
                "key": "total_applications",
                "label": "Applications",
                "format": "number",
                "higher_is_better": True,
                "synonyms": ["applications", "volume"],
                "is_kpi": True,
            },
            {
                "ref": "applications.high_risk_applications",
                "key": "high_risk_applications",
                "label": "High-risk Applications",
                "format": "number",
                "higher_is_better": False,
                "synonyms": ["high risk"],
                "is_kpi": True,
            },
        ],
    },
    {
        "key": "decisions",
        "name": "Application Decisions",
        "dimensions": [
            {"key": "decided_on", "label": "Decision Date", "type": "time", "synonyms": []},
            {"key": "country", "label": "Country", "type": "string", "synonyms": ["market"]},
            {"key": "institution", "label": "Institution", "type": "string", "synonyms": ["ihe", "university"]},
        ],
        "metrics": [
            {
                "ref": "decisions.sla_compliance",
                "key": "sla_compliance",
                "label": "Processing SLA",
                "format": "percent",
                "higher_is_better": True,
                "target": 0.9,
                "synonyms": ["sla", "service level"],
                "is_kpi": True,
            },
            {
                "ref": "decisions.rejection_rate",
                "key": "rejection_rate",
                "label": "Rejection Rate",
                "format": "percent",
                "higher_is_better": False,
                "synonyms": ["rejections", "refusal rate"],
                "is_kpi": True,
            },
        ],
    },
    {
        "key": "revenue",
        "name": "Revenue",
        "dimensions": [
            {"key": "paid_on", "label": "Payment Date", "type": "time", "synonyms": []},
            {"key": "country", "label": "Country", "type": "string", "synonyms": ["market"]},
        ],
        "metrics": [
            {
                "ref": "revenue.revenue",
                "key": "revenue",
                "label": "Revenue",
                "format": "currency",
                "higher_is_better": True,
                "synonyms": ["revenue", "income", "fees"],
                "is_kpi": True,
            },
        ],
    },
]


@pytest.fixture
def index() -> CatalogIndex:
    idx = CatalogIndex.from_catalog(CATALOG)
    idx.members = {
        "country": ["China", "India", "Indonesia"],
        "institution": ["Meridian University", "Klang Valley Institute"],
    }
    return idx
