#!/bin/bash
cd "$(dirname "$0")"


# Default number of sensors is 5
NUM_SENSORS=${1:-5}
INTERVAL=${2:-3}

# Load environment variables from ../.env if it exists
if [ -f ../.env ]; then
    export RABBITMQ_HOST=$(grep -E "^RABBITMQ_HOST=" ../.env | cut -d'=' -f2 | tr -d '\r')
    export RABBITMQ_PORT=$(grep -E "^RABBITMQ_PORT=" ../.env | cut -d'=' -f2 | tr -d '\r')
    export RABBITMQ_USER=$(grep -E "^RABBITMQ_USER=" ../.env | cut -d'=' -f2 | tr -d '\r')
    export RABBITMQ_PASS=$(grep -E "^RABBITMQ_PASS=" ../.env | cut -d'=' -f2 | tr -d '\r')
fi

echo "Starting $NUM_SENSORS simulated sensors in the background..."

# List of room IDs
ROOMS=("101" "102" "201" "202" "301" "302" "401" "402" "501" "502" "601" "602" "701" "702")

# Store PIDs of spawned background processes
pids=()

# Trap SIGINT (Ctrl+C) to terminate all background sensors
cleanup() {
    echo -e "\nStopping all sensors..."
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null
    done
    exit 0
}
trap cleanup SIGINT

for ((i=0; i<NUM_SENSORS; i++)); do
    ROOM_INDEX=$((i % ${#ROOMS[@]}))
    ROOM_ID=${ROOMS[$ROOM_INDEX]}
    
    if [ $i -ge ${#ROOMS[@]} ]; then
        FLOOR=$((1 + (i / 2)))
        SUITE=$((1 + (i % 2)))
        ROOM_ID="${FLOOR}0${SUITE}"
    fi
    
    echo "Launching sensor for Room $ROOM_ID..."
    php sensor.php "$ROOM_ID" "$INTERVAL" &
    pids+=($!)
done

echo "All $NUM_SENSORS sensors running. Press Ctrl+C to stop them."
wait
