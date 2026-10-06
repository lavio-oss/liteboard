FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
RUN useradd --uid 10001 --create-home simulator \
    && mkdir -p /var/lib/liteboard \
    && chown simulator:simulator /var/lib/liteboard
USER 10001:10001

CMD ["python", "-m", "app.main"]
