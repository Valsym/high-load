#!/bin/bash
set -e

# This script runs as the postgres user after the entrypoint has initialized the data directory.
# It sets up streaming replication by taking a base backup from the primary.

PRIMARY_HOST="pgsql-primary"
PRIMARY_PORT="5432"
REPLICATION_USER="replicator"
REPLICATION_PASSWORD="replicator_pass"
PGDATA="/var/lib/postgresql/data"

# Wait for primary to be ready
echo "Waiting for primary PostgreSQL ($PRIMARY_HOST:$PRIMARY_PORT) to be ready..."
for i in $(seq 1 30); do
    if pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U postgres > /dev/null 2>&1; then
        echo "Primary is ready."
        break
    fi
    sleep 2
done

# Check if this is a fresh data directory (not already a replica)
if [ ! -f "$PGDATA/standby.signal" ]; then
    echo "Taking base backup from primary..."
    
    # Stop PostgreSQL if it was started by entrypoint
    pg_ctl -D "$PGDATA" -m fast stop 2>/dev/null || true
    
    # Remove the initialized data
    rm -rf "$PGDATA"/*
    
    # Take base backup
    PGPASSWORD="$REPLICATION_PASSWORD" pg_basebackup \
        -h "$PRIMARY_HOST" \
        -p "$PRIMARY_PORT" \
        -U "$REPLICATION_USER" \
        -D "$PGDATA" \
        -Fp \
        -Xs \
        -P \
        -v \
        -R  # creates postgresql.auto.conf with primary_conninfo and standby.signal
    
    echo "Replica initialized successfully via pg_basebackup."
    
    # Start PostgreSQL as standby
    pg_ctl -D "$PGDATA" -l /var/log/postgresql/replica.log start
else
    echo "Already a replica, skipping base backup."
fi