#!/usr/bin/env bash
# usage: bench/lb.sh up|down|ps|logs ...  Isolated compose project "lbtest": the user's own liteboard volumes are never touched.
export MQTT_PG_HOST=postgres MQTT_PG_PORT=5432 POSTGRES_PORT=55432  # host 5432 can belong to a native PostgreSQL
R="$(cd "$(dirname "$0")/.." && pwd)"
D="docker compose -p lbtest --project-directory $R --env-file $R/.env -f $R/docker-compose.yml -f $R/bench/compose.override.yml --profile application"
case "$1" in
  up) $D up -d --build postgres redis mosquitto mosquitto-plain backend telemetry-service webhook-dispatcher ;;
  *) $D "$@" ;;
esac
