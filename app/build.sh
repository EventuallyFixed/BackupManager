#!/bin/bash
. "../build_helpers.sh"
trap 'restore_files' EXIT

declare -A MODELS
MODELS[amd64]="MyCloudPR4100 MyCloudPR2100 WDMyCloudDL4100 WDMyCloudDL2100"
MODELS[armhf]="WDCloud WDMyCloud WDMyCloudMirror WDMyCloudEX4100 WDMyCloudEX2100 MyCloudEX2Ultra"

for ARCH in "${!MODELS[@]}"; do
    build ${MODELS[${ARCH}]} ${ARCH}
done

. "../build_helpers.sh"
