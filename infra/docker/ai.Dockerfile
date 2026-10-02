# AI service: FastAPI + LangGraph agents + statistical engine.
FROM python:3.11-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY services/ai/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY services/ai/app ./app
RUN useradd --system --uid 10001 aixbi
USER aixbi
EXPOSE 8100
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100", "--workers", "4", "--proxy-headers"]
