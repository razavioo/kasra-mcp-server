FROM python:3.13-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /service
COPY requirements.lock ./
RUN pip install --no-cache-dir -r requirements.lock
COPY . ./
RUN pip install --no-deps . \
    && groupadd --gid 10001 kasra \
    && useradd --uid 10001 --gid 10001 --create-home kasra \
    && mkdir /data && chown kasra:kasra /data
USER 10001:10001
ENV KASRA_MCP_MODE=http KASRA_MCP_HOST=0.0.0.0 KASRA_DECISION_DB=/data/decisions.sqlite
EXPOSE 8000
CMD ["python", "main.py"]
