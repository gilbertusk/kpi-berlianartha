#!/bin/sh
# Creates the isolated *_test database used by the PostgreSQL-backed test run.
# Runs only when the data volume is initialised for the first time.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE DATABASE kpi_test OWNER "$POSTGRES_USER";
SQL
