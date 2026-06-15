#!/bin/bash
set -e

# Custom entrypoint for PostgreSQL replica.
# Takes a base backup from primary before starting PostgreSQL in standby mode.

PRIMARY_HOST="pgsql-primary"
PRIMARY_PORT="5432"
REPLICATION_USER="replicator"
REPLICATION_PASSWORD="replicator_pass"
PGDATA="/var/lib/postgresql/data"

# Wait for primary to be ready
echo "Waiting for primary PostgreSQL ($PRIMARY_HOST:$PRIMARY_PORT) to be ready..."
for i in $(seq 1 60); do
    if pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U postgres > /dev/null 2>&1; then
        echo "Primary is ready."
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "ERROR: Primary not reachable after 60 attempts. Exiting."
        exit 1
    fi
    sleep 2
done

# Check if data directory is empty (first run)
if [ ! -f "$PGDATA/PG_VERSION" ]; then
    echo "Data directory is empty. Taking base backup from primary..."
    
    # Create data directory if needed
    mkdir -p "$PGDATA"
    
    # Take base backup with -R flag to create standby.signal and postgresql.auto.conf
    PGPASSWORD="$REPLICATION_PASSWORD" pg_basebackup \
        -h "$PRIMARY_HOST" \
        -p "$PRIMARY_PORT" \
        -U "$REPLICATION_USER" \
        -D "$PGDATA" \
        -Fp \
        -Xs \
        -P \
        -v \
        -R
    
    echo "Base backup completed. Starting PostgreSQL in standby mode..."
else
    echo "Data directory already exists. Starting PostgreSQL..."
fi

# Execute the original Docker entrypoint with standby config
exec docker-entrypoint.sh postgres -c config_file=/etc/postgresql/postgresql.conf