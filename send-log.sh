#!/bin/bash
for i in {1..100}; do
    echo "{\"message\": \"Log $i\", \"level\": \"INFO\"}"
done | nc localhost 5000
