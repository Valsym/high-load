FROM confluentinc/cp-kafka-connect:7.6.0

ENV CONNECT_PLUGIN_PATH=/usr/share/java,/usr/share/confluent-helm-charts,/etc/kafka-connect/jars,/usr/share/confluent-hub-components

USER root

# Download Debezium PostgreSQL Connector from GitHub releases
RUN mkdir -p /usr/share/confluent-hub-components/debezium-postgres && \
    cd /tmp && \
    curl -L -O https://github.com/debezium/debezium/releases/download/v2.5.4.Final/debezium-connector-postgresql-2.5.4.Final-plugin.tar.gz && \
    tar -xzf debezium-connector-postgresql-2.5.4.Final-plugin.tar.gz -C /usr/share/confluent-hub-components/debezium-postgres --strip-components=1 && \
    rm -f debezium-connector-postgresql-2.5.4.Final-plugin.tar.gz

# Download JDBC Sink Connector from Confluent Hub (direct download)
RUN mkdir -p /usr/share/confluent-hub-components/kafka-connect-jdbc && \
    cd /tmp && \
    curl -L -O https://github.com/confluentinc/kafka-connect-jdbc/releases/download/v10.7.4/kafka-connect-jdbc-10.7.4.zip && \
    python3 -c "import zipfile, sys; zipfile.ZipFile('kafka-connect-jdbc-10.7.4.zip').extractall('/usr/share/confluent-hub-components/kafka-connect-jdbc')" && \
    rm -f kafka-connect-jdbc-10.7.4.zip

# Install ClickHouse JDBC driver
RUN mkdir -p /etc/kafka-connect/jars/clickhouse && \
    cd /tmp && \
    curl -L -o clickhouse-jdbc.jar https://repo1.maven.org/maven2/com/clickhouse/clickhouse-jdbc/0.6.5/clickhouse-jdbc-0.6.5-all.jar && \
    mv clickhouse-jdbc.jar /etc/kafka-connect/jars/clickhouse/

USER appuser